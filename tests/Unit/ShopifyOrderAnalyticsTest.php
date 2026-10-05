<?php

namespace Tests\Unit;

use App\Services\ShopifyService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Carbon;
use ReflectionClass;
use Tests\TestCase;

/**
 * The analytics tab's per-store number: revenue, order count and top products
 * for a date range, read straight from that store's own Shopify orders rather
 * than the ecommerce server the Orders tab uses.
 */
class ShopifyOrderAnalyticsTest extends TestCase
{
    /** @var list<\Psr\Http\Message\RequestInterface> */
    private array $sent = [];

    /** @param  list<Response>  $responses */
    private function service(array $responses): ShopifyService
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sent));

        $service = (new ReflectionClass(ShopifyService::class))->newInstanceWithoutConstructor();

        $prop = (new ReflectionClass(ShopifyService::class))->getProperty('http');
        $prop->setAccessible(true);
        $prop->setValue($service, new Client(['handler' => $stack]));

        return $service;
    }

    /**
     * @param  list<array{id: int, total: string, channel?: string, line_items: list<array{title: string, quantity: int, revenue: string}>}>  $orders
     */
    private function ordersPage(array $orders, bool $hasNextPage = false): Response
    {
        $edges = array_map(function ($o) {
            // Shaped as API 2024-01 actually answers — the version this app
            // pins. `attribution` is a 2026-07 field and does not exist here,
            // which is what broke this in production. A null
            // channelInformation is what real manual and draft orders come
            // back as, so `'channel' => null` reproduces one; note the
            // array_key_exists, since ?? would read that null as "absent"
            // and quietly hand back the default instead.
            $channel = \array_key_exists('channel', $o) ? $o['channel'] : 'Online Store';

            return [
                'cursor' => 'cursor-' . $o['id'],
                'node'   => [
                    'id'            => 'gid://shopify/Order/' . $o['id'],
                    'totalPriceSet' => ['shopMoney' => ['amount' => $o['total'], 'currencyCode' => 'QAR']],
                    'channelInformation' => $channel === null
                        ? null
                        : ['channelDefinition' => ['channelName' => $channel]],
                    'app' => \array_key_exists('app', $o) ? ['name' => $o['app']] : null,
                    'lineItems' => [
                        // vendor sits on the line item; productType does not
                        // exist there on 2024-01 and is reached through the
                        // product, which comes back null once that product has
                        // been deleted — 4 of 238 line items on a live store.
                        'edges' => array_map(fn ($li) => ['node' => [
                            'title'              => $li['title'],
                            'quantity'           => $li['quantity'],
                            'discountedTotalSet' => ['shopMoney' => ['amount' => $li['revenue']]],
                            'vendor'             => $li['vendor'] ?? null,
                            'product'            => \array_key_exists('type', $li)
                                ? ($li['type'] === null ? null : ['productType' => $li['type']])
                                : null,
                        ]], $o['line_items']),
                    ],
                ],
            ];
        }, $orders);

        return new Response(200, [], json_encode([
            'data' => [
                'orders' => [
                    'edges'    => $edges,
                    'pageInfo' => ['hasNextPage' => $hasNextPage],
                ],
            ],
        ]));
    }

    public function test_sums_revenue_and_orders_from_a_single_page(): void
    {
        $service = $this->service([
            $this->ordersPage([
                ['id' => 1, 'total' => '100.00', 'line_items' => [
                    ['title' => 'Red Shirt', 'quantity' => 2, 'revenue' => '80.00'],
                ]],
                ['id' => 2, 'total' => '50.00', 'line_items' => [
                    ['title' => 'Blue Hat', 'quantity' => 1, 'revenue' => '50.00'],
                ]],
            ]),
        ]);

        $result = $service->getOrderAnalytics(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $this->assertSame(2, $result['orders']);
        $this->assertEqualsWithDelta(150.00, $result['revenue'], 0.001);
        $this->assertSame('QAR', $result['currency']);
        $this->assertFalse($result['capped']);
        $this->assertSame('Red Shirt', $result['top_products'][0]['title']);
        $this->assertSame(2, $result['top_products'][0]['quantity']);
        $this->assertEqualsWithDelta(80.00, $result['top_products'][0]['revenue'], 0.001);
    }

    public function test_merges_totals_across_pages(): void
    {
        $service = $this->service([
            $this->ordersPage([
                ['id' => 1, 'total' => '100.00', 'line_items' => [
                    ['title' => 'Red Shirt', 'quantity' => 1, 'revenue' => '100.00'],
                ]],
            ], hasNextPage: true),
            $this->ordersPage([
                ['id' => 2, 'total' => '25.00', 'line_items' => [
                    ['title' => 'Red Shirt', 'quantity' => 1, 'revenue' => '25.00'],
                ]],
            ]),
        ]);

        $result = $service->getOrderAnalytics(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $this->assertSame(2, $result['orders']);
        $this->assertEqualsWithDelta(125.00, $result['revenue'], 0.001);
        $this->assertFalse($result['capped']);
        $this->assertSame(2, $result['top_products'][0]['quantity']);
        $this->assertEqualsWithDelta(125.00, $result['top_products'][0]['revenue'], 0.001);
    }

    /** A store with more history than the page cap reports a partial total, not a hang. */
    public function test_flags_a_range_deeper_than_the_page_cap_as_capped(): void
    {
        $pages = [];

        for ($i = 1; $i <= 20; $i++) {
            $pages[] = $this->ordersPage([
                ['id' => $i, 'total' => '10.00', 'line_items' => []],
            ], hasNextPage: true);
        }

        $service = $this->service($pages);

        $result = $service->getOrderAnalytics(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $this->assertSame(20, $result['orders']);
        $this->assertTrue($result['capped']);
    }

    public function test_groups_revenue_and_orders_by_sales_channel(): void
    {
        $service = $this->service([
            $this->ordersPage([
                ['id' => 1, 'total' => '100.00', 'channel' => 'Online Store', 'line_items' => []],
                ['id' => 2, 'total' => '40.00', 'channel' => 'Point of Sale', 'line_items' => []],
                ['id' => 3, 'total' => '20.00', 'channel' => 'Point of Sale', 'line_items' => []],
            ]),
        ]);

        $result = $service->getOrderAnalytics(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $this->assertSame('Online Store', $result['by_channel'][0]['channel']);
        $this->assertSame(1, $result['by_channel'][0]['orders']);
        $this->assertEqualsWithDelta(100.00, $result['by_channel'][0]['revenue'], 0.001);

        $this->assertSame('Point of Sale', $result['by_channel'][1]['channel']);
        $this->assertSame(2, $result['by_channel'][1]['orders']);
        $this->assertEqualsWithDelta(60.00, $result['by_channel'][1]['revenue'], 0.001);
    }

    /**
     * A third of one real store's orders carry no channelInformation at all —
     * manual and draft orders do not have one — and bucketing that much
     * revenue under "Unknown" throws away most of the answer. The app that
     * created the order names it well enough to be worth falling back to.
     */
    public function test_an_order_with_no_channel_falls_back_to_the_app_that_created_it(): void
    {
        $service = $this->service([
            $this->ordersPage([
                ['id' => 1, 'total' => '100.00', 'channel' => null, 'app' => 'Draft Orders', 'line_items' => []],
                ['id' => 2, 'total' => '60.00', 'channel' => 'Online Store', 'line_items' => []],
            ]),
        ]);

        $result = $service->getOrderAnalytics(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $channels = collect($result['by_channel'])->keyBy('channel');

        $this->assertTrue($channels->has('Draft Orders'), 'expected the app name to stand in for the missing channel');
        $this->assertEqualsWithDelta(100.00, $channels['Draft Orders']['revenue'], 0.001);
        $this->assertFalse($channels->has('Unknown'));
    }

    /** "Total sales by product" is a fuller list than the old top-5, but a store with hundreds of SKUs still needs a cap. */
    public function test_caps_the_product_list_at_twenty_largest_first(): void
    {
        $lineItems = [];

        for ($i = 1; $i <= 25; $i++) {
            $lineItems[] = ['title' => "Product {$i}", 'quantity' => 1, 'revenue' => (string) $i];
        }

        $service = $this->service([
            $this->ordersPage([
                ['id' => 1, 'total' => '325.00', 'line_items' => $lineItems],
            ]),
        ]);

        $result = $service->getOrderAnalytics(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $this->assertCount(20, $result['top_products']);
        $this->assertSame('Product 25', $result['top_products'][0]['title']);
        $this->assertSame('Product 6', $result['top_products'][19]['title']);
    }

    /**
     * An order counts once for a brand however many of its items carry it.
     *
     * "Three orders had Luca Barra in them" is the question a buyer is
     * asking; counting line items would answer "Luca Barra appeared five
     * times", which reads the same and is not. Revenue is still summed per
     * line, because that half genuinely is per item.
     */
    public function test_groups_orders_by_brand_counting_each_order_once(): void
    {
        $service = $this->service([$this->ordersPage([
            ['id' => 1, 'total' => '300.00', 'line_items' => [
                ['title' => 'Bracelet', 'quantity' => 1, 'revenue' => '100.00', 'vendor' => 'Luca Barra', 'type' => 'Fashion Accessories'],
                ['title' => 'Earrings', 'quantity' => 1, 'revenue' => '120.00', 'vendor' => 'Luca Barra', 'type' => 'Fashion Accessories'],
                ['title' => 'Loafers',  'quantity' => 1, 'revenue' => '80.00',  'vendor' => 'Cole Haan',  'type' => 'Mens Fashion'],
            ]],
            ['id' => 2, 'total' => '60.00', 'line_items' => [
                ['title' => 'Ring', 'quantity' => 1, 'revenue' => '60.00', 'vendor' => 'Luca Barra', 'type' => 'Fashion Accessories'],
            ]],
        ])]);

        $result = $service->getOrderAnalytics(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

        $this->assertSame([
            ['label' => 'Luca Barra', 'orders' => 2, 'revenue' => 280.0],
            ['label' => 'Cole Haan',  'orders' => 1, 'revenue' => 80.0],
        ], $result['by_vendor']);

        $this->assertSame([
            ['label' => 'Fashion Accessories', 'orders' => 2, 'revenue' => 280.0],
            ['label' => 'Mens Fashion',        'orders' => 1, 'revenue' => 80.0],
        ], $result['by_product_type']);
    }

    /**
     * A deleted product answers with a null `product`, and some line items
     * carry no vendor at all. Both are real on a live store, and both have to
     * land somewhere nameable rather than being dropped — an order quietly
     * missing from the totals is worse than one labelled "Not recorded".
     */
    public function test_a_deleted_product_and_a_blank_brand_are_named_rather_than_dropped(): void
    {
        $service = $this->service([$this->ordersPage([
            ['id' => 1, 'total' => '150.00', 'line_items' => [
                ['title' => 'Gone',    'quantity' => 1, 'revenue' => '90.00', 'vendor' => '',   'type' => null],
                ['title' => 'Perfume', 'quantity' => 1, 'revenue' => '60.00', 'vendor' => 'Lorenzo Villoresi', 'type' => 'Perfumes & Cosmetics'],
            ]],
        ])]);

        $result = $service->getOrderAnalytics(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

        // Canonicalizing, not assertSame: both sides hold one order each, so
        // nothing in the data decides which comes first and asserting an order
        // would be testing the sort's tie-breaking rather than the labelling.
        $this->assertEqualsCanonicalizing(
            ['Lorenzo Villoresi', 'Not recorded'],
            array_column($result['by_vendor'], 'label'),
        );
        $this->assertEqualsCanonicalizing(
            ['Perfumes & Cosmetics', 'Not recorded'],
            array_column($result['by_product_type'], 'label'),
        );

        // The money is still all there, wherever its label came from.
        $this->assertSame(150.0, array_sum(array_column($result['by_vendor'], 'revenue')));
    }

    /** The brand and division fields have to be asked for, or none of this works. */
    public function test_the_query_asks_for_the_brand_and_the_division(): void
    {
        $service = $this->service([$this->ordersPage([
            ['id' => 1, 'total' => '10.00', 'line_items' => [
                ['title' => 'Thing', 'quantity' => 1, 'revenue' => '10.00', 'vendor' => 'Acme', 'type' => 'Watches'],
            ]],
        ])]);

        $service->getOrderAnalytics(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

        $body = (string) $this->sent[0]['request']->getBody();

        $this->assertStringContainsString('vendor', $body);
        // productType does not exist on LineItem in 2024-01; it is only
        // reachable through the product.
        $this->assertStringContainsString('product{productType}', $body);
    }
}
