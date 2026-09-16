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
