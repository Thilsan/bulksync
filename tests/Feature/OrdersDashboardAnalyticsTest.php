<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Services\ShopifyAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Analytics tab on the management dashboard: one row per website, read
 * from each store's own Shopify rather than the ecommerce-server endpoint
 * the Orders tab uses. ShopifyAnalyticsService is swapped for a fake in the
 * container so these tests never reach the network.
 */
class OrdersDashboardAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Ada Okonkwo', 'email' => 'ada@example.test',
            'password' => 'password', 'is_active' => true, 'is_super_admin' => true,
        ]);
    }

    /** A stand-in for one store's Shopify client, answering whatever a test needs. */
    private function fake(int $orders = 3, float $revenue = 300.0, array $products = [], array $channels = []): object
    {
        return new class($orders, $revenue, $products, $channels) {
            public function __construct(
                private int $orders,
                private float $revenue,
                private array $products,
                private array $channels,
            ) {}

            public function getOrderAnalytics($from, $to): array
            {
                return [
                    'orders'       => $this->orders,
                    'revenue'      => $this->revenue,
                    'currency'     => 'QAR',
                    'top_products' => $this->products,
                    'by_channel'   => $this->channels,
                    'capped'       => false,
                ];
            }
        };
    }

    public function test_shows_one_row_per_connected_store_with_its_shopify_totals(): void
    {
        Store::create([
            'name' => 'Blue Salon', 'shopify_domain' => 'bluesalon.myshopify.com',
            'shopify_access_token' => 'test-token',
        ]);

        $fake = new class {
            public function getOrderAnalytics($from, $to): array
            {
                return [
                    'orders'       => 3,
                    'revenue'      => 300.0,
                    'currency'     => 'QAR',
                    'top_products' => [['title' => 'Red Shirt', 'quantity' => 2, 'revenue' => 200.0]],
                    'capped'       => false,
                ];
            }
        };
        $this->app->instance(ShopifyAnalyticsService::class, new ShopifyAnalyticsService(fn ($s) => $fake));

        $response = $this->actingAs($this->admin)->get('/management-dashboard?tab=analytics');

        $response->assertOk();
        $response->assertSee('data-tab="analytics"', false);
        $response->assertSee('Blue Salon');
        $response->assertSee('300.00');
        $response->assertSee('Red Shirt');
    }

    public function test_a_store_without_shopify_credentials_shows_as_not_connected(): void
    {
        Store::create(['name' => 'No Token Shop', 'shopify_domain' => 'notoken.myshopify.com']);

        $response = $this->actingAs($this->admin)->get('/management-dashboard?tab=analytics');

        $response->assertOk();
        $response->assertSee('No Token Shop');
        $response->assertSee('Not connected');
    }

    /**
     * Ten more storefronts are being wired up. Naming them says the list is
     * complete — without it, somebody who knows one of these sites exists is
     * left wondering whether the tab is broken or the site is not ours.
     */
    public function test_the_websites_still_being_integrated_are_named_below_the_cards(): void
    {
        Store::create(['name' => 'No Token Shop', 'shopify_domain' => 'notoken.myshopify.com']);

        $response = $this->actingAs($this->admin)
            ->get('/management-dashboard?tab=analytics')
            ->assertOk()
            ->assertSee('Integration in progress');

        foreach ($this->integrating() as $domain) {
            $response->assertSee($domain);
        }
    }

    /**
     * Named, but not counted. A website that is not connected has reported
     * nothing, and rolling these into the coverage tile would turn "ten sites
     * we have not wired up" into "ten sites that sold nothing".
     */
    public function test_a_website_being_integrated_is_not_counted_among_those_reporting(): void
    {
        Store::create(['name' => 'No Token Shop', 'shopify_domain' => 'notoken.myshopify.com']);

        $this->actingAs($this->admin)
            ->get('/management-dashboard?tab=analytics')
            ->assertOk()
            ->assertSee('0 of 1');
    }

    /** The domains, as the business gave them. */
    private function integrating(): array
    {
        return [
            'billjumla.com', 'thefaceshopqatar.com', 'karisma-cosmetics.com', 'faltafalta.com',
            'colehaan.qa', 'outoftheblue.qa', 'goldgourmet.qa', 'oryx-tec.com',
            'shoptriumph.qa', 'replayjeans.qa',
        ];
    }

    public function test_shows_the_sales_channel_breakdown_for_a_connected_store(): void
    {
        Store::create([
            'name' => 'Blue Salon', 'shopify_domain' => 'bluesalon.myshopify.com',
            'shopify_access_token' => 'test-token',
        ]);

        $fake = new class {
            public function getOrderAnalytics($from, $to): array
            {
                return [
                    'orders'       => 3,
                    'revenue'      => 300.0,
                    'currency'     => 'QAR',
                    'top_products' => [],
                    'by_channel'   => [
                        ['channel' => 'Online Store', 'orders' => 2, 'revenue' => 250.0],
                        ['channel' => 'Point of Sale', 'orders' => 1, 'revenue' => 50.0],
                    ],
                    'capped' => false,
                ];
            }
        };
        $this->app->instance(ShopifyAnalyticsService::class, new ShopifyAnalyticsService(fn ($s) => $fake));

        $response = $this->actingAs($this->admin)->get('/management-dashboard?tab=analytics');

        $response->assertOk();
        $response->assertSee('Online Store');
        $response->assertSee('Point of Sale');
    }

    /** The tab is useless without its own dates — it used to borrow the Orders tab's and offer no way to change them. */
    public function test_the_analytics_tab_carries_its_own_date_filter(): void
    {
        Store::create([
            'name' => 'Blue Salon', 'shopify_domain' => 'bluesalon.myshopify.com',
            'shopify_access_token' => 'test-token',
        ]);

        $this->app->instance(ShopifyAnalyticsService::class, new ShopifyAnalyticsService(fn ($s) => $this->fake()));

        $response = $this->actingAs($this->admin)
            ->get('/management-dashboard?tab=analytics&preset=custom&from=2026-08-01&to=2026-08-31');

        $response->assertOk();
        // Submitting the filter bar has to land back on this tab, not Orders.
        $response->assertSee('name="tab" value="analytics"', false);
        $response->assertSee('value="2026-08-01"', false);
        $response->assertSee('value="2026-08-31"', false);
    }

    public function test_the_headline_tiles_total_every_connected_store(): void
    {
        Store::create(['name' => 'Alpha Site', 'shopify_domain' => 'a.myshopify.com', 'shopify_access_token' => 't']);
        Store::create(['name' => 'Beta Site', 'shopify_domain' => 'b.myshopify.com', 'shopify_access_token' => 't']);

        $byStore = [
            'a.myshopify.com' => $this->fake(orders: 3, revenue: 300.0),
            'b.myshopify.com' => $this->fake(orders: 2, revenue: 200.0),
        ];

        $this->app->instance(
            ShopifyAnalyticsService::class,
            new ShopifyAnalyticsService(fn ($s) => $byStore[$s->shopify_domain]),
        );

        $response = $this->actingAs($this->admin)->get('/management-dashboard?tab=analytics');

        $response->assertOk();
        $response->assertSee('500.00');  // combined revenue
        $response->assertSee('100.00');  // combined average order value
    }

    /** A missing read_orders scope is not a network hiccup — the page has to say so. */
    public function test_a_store_missing_the_orders_scope_shows_an_actionable_message(): void
    {
        Store::create([
            'name' => 'AT Website', 'shopify_domain' => 'at.myshopify.com',
            'shopify_access_token' => 'test-token',
        ]);

        $fake = new class {
            public function getOrderAnalytics($from, $to): array
            {
                throw new \RuntimeException('Shopify GraphQL error in getOrderAnalytics: Access denied for orders field.');
            }
        };
        $this->app->instance(ShopifyAnalyticsService::class, new ShopifyAnalyticsService(fn ($s) => $fake));

        $response = $this->actingAs($this->admin)->get('/management-dashboard?tab=analytics');

        $response->assertOk();
        $response->assertSee('AT Website');
        $response->assertSee('read_orders');
        $response->assertDontSee('Unavailable');
    }
}
