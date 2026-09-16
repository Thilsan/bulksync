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
                        'edges' => array_map(fn ($li) => ['node' => [
                            'title'              => $li['title'],
                            'quantity'           => $li['quantity'],
                            'discountedTotalSet' => ['shopMoney' => ['amount' => $li['revenue']]],
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
}
