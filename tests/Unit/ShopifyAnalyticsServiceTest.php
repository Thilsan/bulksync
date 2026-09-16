<?php

namespace Tests\Unit;

use App\Models\Store;
use App\Services\ShopifyAnalyticsService;
use App\Services\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The analytics tab's per-store row: one call out to that store's own Shopify
 * for connected stores, and an honest status — never a silently dropped row —
 * for the ones that aren't or that fail.
 */
class ShopifyAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function store(array $overrides = []): Store
    {
        return Store::create(array_merge([
            'name'                 => 'Blue Salon',
            'shopify_domain'       => 'bluesalon.myshopify.com',
            'shopify_access_token' => 'test-token',
        ], $overrides));
    }

    public function test_a_connected_store_carries_its_shopify_totals(): void
    {
        $store = $this->store();

        $fake = new class {
            public function getOrderAnalytics(Carbon $from, Carbon $to): array
            {
                return ['orders' => 4, 'revenue' => 400.0, 'currency' => 'QAR', 'top_products' => [], 'capped' => false];
            }
        };

        $service = new ShopifyAnalyticsService(fn (Store $s) => $fake);

        $rows = $service->forStores(collect([$store]), Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $this->assertSame('ok', $rows[0]['status']);
        $this->assertSame(4, $rows[0]['orders']);
        $this->assertEqualsWithDelta(400.0, $rows[0]['revenue'], 0.001);
        $this->assertEqualsWithDelta(100.0, $rows[0]['average_order_value'], 0.001);
    }

    public function test_a_store_without_a_shopify_token_is_reported_not_connected(): void
    {
        $store = $this->store(['shopify_access_token' => null]);

        $service = new ShopifyAnalyticsService(fn (Store $s) => throw new \LogicException('should never be called'));

        $rows = $service->forStores(collect([$store]), Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $this->assertSame('not_connected', $rows[0]['status']);
    }

    public function test_a_failed_shopify_call_is_reported_unavailable_not_thrown(): void
    {
        $store = $this->store();

        $fake = new class {
            public function getOrderAnalytics(Carbon $from, Carbon $to): array
            {
                throw new \RuntimeException('Shopify is down');
            }
        };

        $service = new ShopifyAnalyticsService(fn (Store $s) => $fake);

        $rows = $service->forStores(collect([$store]), Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $this->assertSame('unavailable', $rows[0]['status']);
    }

    /**
     * The tokens on these stores were created for the product/catalogue work
     * ShopifyService already did and were never granted `read_orders` — so
     * this is not a transient failure, and burying it under the same
     * "Unavailable" label as a network hiccup would send whoever's looking at
     * this table straight to the logs for something the page could just say.
     */
    public function test_a_missing_scope_error_is_reported_distinctly_from_a_generic_failure(): void
    {
        $store = $this->store();

        $fake = new class {
            public function getOrderAnalytics(Carbon $from, Carbon $to): array
            {
                throw new \RuntimeException('Shopify GraphQL error in getOrderAnalytics: Access denied for orders field.');
            }
        };

        $service = new ShopifyAnalyticsService(fn (Store $s) => $fake);

        $rows = $service->forStores(collect([$store]), Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $this->assertSame('missing_scope', $rows[0]['status']);
        $this->assertStringContainsString('read_orders', $rows[0]['message']);
    }

    public function test_a_second_call_for_the_same_range_does_not_refetch(): void
    {
        $store   = $this->store();
        $counter = new class { public int $calls = 0; };

        $fake = new class($counter) {
            public function __construct(private object $counter) {}

            public function getOrderAnalytics(Carbon $from, Carbon $to): array
            {
                $this->counter->calls++;

                return ['orders' => 1, 'revenue' => 10.0, 'currency' => 'QAR', 'top_products' => [], 'capped' => false];
            }
        };

        $service = new ShopifyAnalyticsService(fn (Store $s) => $fake);

        $service->forStores(collect([$store]), Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));
        $service->forStores(collect([$store]), Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $this->assertSame(1, $counter->calls);
    }
}
