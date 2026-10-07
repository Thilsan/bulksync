<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Services\OrderCancellationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cancelled-orders card on the Ecom Delivery tab: each store's Shopify
 * is asked for its cancellations, with the reason and staff note, and a
 * store that fails is named rather than dropped.
 */
class OrderCancellationsTest extends TestCase
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

    private function fake(array $orders): object
    {
        return new class($orders) {
            public function __construct(private array $orders) {}

            public function getCancelledOrders($from, $to): array
            {
                return $this->orders;
            }
        };
    }

    public function test_lists_cancelled_orders_with_reason_and_staff_note(): void
    {
        Store::create(['name' => 'Pari Gallery Qatar', 'shopify_domain' => 'pari.myshopify.com', 'shopify_access_token' => 't']);

        $fake = $this->fake([[
            'id' => '1', 'number' => '#5821', 'customer' => 'Mohammad Abdelrahim',
            'reason' => 'CUSTOMER', 'staff_note' => 'Not answered.',
            'cancelled_at' => '2026-10-07T06:35:16Z', 'total' => 2141.0, 'currency' => 'QAR',
            'url' => 'https://pari.myshopify.com/admin/orders/1',
        ]]);
        $this->app->instance(OrderCancellationsService::class, new OrderCancellationsService(fn ($s) => $fake));

        $this->actingAs($this->admin)
            ->getJson(route('orders.dashboard.cancellations', ['from' => '2026-10-01', 'to' => '2026-10-07']))
            ->assertOk()
            ->assertJsonPath('orders.0.number', '#5821')
            ->assertJsonPath('orders.0.store', 'Pari Gallery Qatar')
            ->assertJsonPath('orders.0.customer', 'Mohammad Abdelrahim')
            ->assertJsonPath('orders.0.reason_label', 'Customer changed or cancelled order')
            ->assertJsonPath('orders.0.staff_note', 'Not answered.')
            ->assertJsonPath('failed', []);
    }

    public function test_a_failing_store_is_named_and_unconnected_ones_are_skipped(): void
    {
        Store::create(['name' => 'Broken Shop', 'shopify_domain' => 'broken.myshopify.com', 'shopify_access_token' => 't']);
        Store::create(['name' => 'No Token Shop', 'shopify_domain' => 'notoken.myshopify.com']);

        $this->app->instance(OrderCancellationsService::class,
            new OrderCancellationsService(fn ($s) => throw new \RuntimeException(
                'Shopify GraphQL error in getCancelledOrders: Access denied for orders field.')));

        $this->actingAs($this->admin)
            ->getJson(route('orders.dashboard.cancellations'))
            ->assertOk()
            ->assertJsonPath('orders', [])
            ->assertJsonPath('failed.0.store', 'Broken Shop')
            ->assertJsonPath('failed.0.message', "This store's Shopify app is missing a permission: Access denied for orders field.")
            ->assertJsonCount(1, 'failed');
    }

    public function test_only_the_filtered_platforms_stores_are_asked(): void
    {
        foreach (['Bluesalon' => 'bluesalon', 'Parisgallery' => 'paris', 'Toys4me.com' => 'toys4me'] as $name => $sub) {
            Store::create(['name' => $name, 'shopify_domain' => "{$sub}.myshopify.com", 'shopify_access_token' => 't']);
        }

        $asked = [];
        $this->app->instance(OrderCancellationsService::class, new OrderCancellationsService(function ($s) use (&$asked) {
            $asked[] = $s->name;

            return $this->fake([]);
        }));

        $this->actingAs($this->admin)
            ->getJson(route('orders.dashboard.cancellations', ['platforms' => ['parigallery', 'toys4me', 'colehaan']]))
            ->assertOk()
            ->assertJsonPath('unmatched', ['Cole Haan']);

        $this->assertSame(['Parisgallery', 'Toys4me.com'], $asked);
    }

    public function test_no_platform_filter_asks_every_store(): void
    {
        Store::create(['name' => 'Bluesalon', 'shopify_domain' => 'b.myshopify.com', 'shopify_access_token' => 't']);
        Store::create(['name' => 'Colehaan', 'shopify_domain' => 'c.myshopify.com', 'shopify_access_token' => 't']);

        $asked = 0;
        $this->app->instance(OrderCancellationsService::class, new OrderCancellationsService(function ($s) use (&$asked) {
            $asked++;

            return $this->fake([]);
        }));

        $this->actingAs($this->admin)->getJson(route('orders.dashboard.cancellations'))
            ->assertOk()->assertJsonPath('unmatched', []);

        $this->assertSame(2, $asked);
    }

    public function test_unknown_reasons_stay_readable(): void
    {
        $this->assertSame('Not given', OrderCancellationsService::reason(null));
        $this->assertSame('Items unavailable', OrderCancellationsService::reason('INVENTORY'));
        $this->assertSame('Something new', OrderCancellationsService::reason('SOMETHING_NEW'));
    }
}
