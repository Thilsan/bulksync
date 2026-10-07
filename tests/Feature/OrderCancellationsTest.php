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

        // .env carries the real orders endpoint; nothing here may reach it.
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        config(['services.orders_api.token' => null]);

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
            'id' => '1', 'number' => '#5821',
            'reason' => 'CUSTOMER', 'staff_note' => 'Not answered.', 'payment' => 'VOIDED',
            'cancelled_at' => '2026-10-07T06:35:16Z', 'total' => 2141.0, 'currency' => 'QAR',
            'url' => 'https://pari.myshopify.com/admin/orders/1',
        ]]);
        $this->app->instance(OrderCancellationsService::class, new OrderCancellationsService(fn ($s) => $fake));

        $this->actingAs($this->admin)
            ->getJson(route('orders.dashboard.cancellations', ['from' => '2026-10-01', 'to' => '2026-10-07']))
            ->assertOk()
            ->assertJsonPath('orders.0.number', '#5821')
            ->assertJsonPath('orders.0.store', 'Pari Gallery Qatar')
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

    public function test_the_date_basis_reaches_shopify(): void
    {
        Store::create(['name' => 'Bluesalon', 'shopify_domain' => 'b.myshopify.com', 'shopify_access_token' => 't']);

        $seen = [];
        $fake = new class($seen) {
            public function __construct(public array &$seen) {}

            public function getCancelledOrders($from, $to, $basis = 'created'): array
            {
                $this->seen[] = $basis;

                return [];
            }
        };
        $this->app->instance(OrderCancellationsService::class, new OrderCancellationsService(fn ($s) => $fake));

        $this->actingAs($this->admin)->getJson(route('orders.dashboard.cancellations'))->assertOk();
        $this->actingAs($this->admin)->getJson(route('orders.dashboard.cancellations', ['basis' => 'updated']))->assertOk();
        // Same range as the first call, so it would come from the cache.
        \Illuminate\Support\Facades\Cache::flush();
        $this->actingAs($this->admin)->getJson(route('orders.dashboard.cancellations', ['basis' => 'nonsense']))->assertOk();

        $this->assertSame(['created', 'updated', 'created'], $seen);
    }

    private function endpoint(?array $cancelled): void
    {
        config([
            'services.orders_api.url'   => 'https://orders.test/orders_summary.php',
            'services.orders_api.token' => 'test-token-value',
        ]);

        $data = ['totals' => ['total_orders' => 0]];

        if ($cancelled !== null) {
            $data['cancelled_orders'] = $cancelled;
        }

        \Illuminate\Support\Facades\Http::fake(['orders.test/*' => \Illuminate\Support\Facades\Http::response(
            ['status_code' => 100, 'message' => 'ok', 'data' => $data],
        )]);
    }

    public function test_the_delivery_list_decides_which_orders_appear(): void
    {
        Store::create(['name' => 'Bluesalon', 'shopify_domain' => 'b.myshopify.com', 'shopify_access_token' => 't']);

        $row = fn ($n, $note) => [
            'id' => $n, 'number' => $n, 'reason' => 'CUSTOMER', 'staff_note' => $note, 'payment' => 'VOIDED',
            'cancelled_at' => '2026-10-05T10:00:00Z', 'total' => 100.0, 'currency' => 'QAR', 'url' => "https://b/{$n}",
        ];

        // Shopify cancelled three; the delivery system counts one of them,
        // spelled without the prefix, and one manual order Shopify never saw.
        $fake = $this->fake([$row('BS32011', 'PAYLATER'), $row('BS32162', 'Via CRM'), $row('BS32037', 'PAYLATER')]);
        $this->app->instance(OrderCancellationsService::class, new OrderCancellationsService(fn ($s) => $fake));

        $this->endpoint([
            ['platform' => 'bluesalon', 'order_number' => '32162', 'status' => 'Cancelled', 'status_id' => 6],
            ['platform' => 'bluesalon', 'order_number' => 'M-900', 'status' => 'Failed', 'status_id' => 17, 'total' => 50],
        ]);

        $json = $this->actingAs($this->admin)
            ->getJson(route('orders.dashboard.cancellations'))
            ->assertOk()
            ->assertJsonPath('source', 'delivery')
            ->assertJsonCount(2, 'orders')
            ->json('orders');

        $byNumber = collect($json)->keyBy('number');

        $this->assertSame('Via CRM', $byNumber['BS32162']['staff_note']);
        $this->assertTrue($byNumber['BS32162']['in_shopify']);
        $this->assertSame('Failed', $byNumber['M-900']['reason_label']);
        $this->assertFalse($byNumber['M-900']['in_shopify']);
        $this->assertArrayNotHasKey('BS32011', $byNumber->all());

        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_contains($r->url(), 'include=cancelled_orders'));
    }

    public function test_without_the_delivery_list_the_card_is_shopifys_own(): void
    {
        Store::create(['name' => 'Bluesalon', 'shopify_domain' => 'b.myshopify.com', 'shopify_access_token' => 't']);

        $fake = $this->fake([[
            'id' => '1', 'number' => 'BS1', 'reason' => 'CUSTOMER', 'staff_note' => null, 'payment' => 'PAID',
            'cancelled_at' => '2026-10-05T10:00:00Z', 'total' => 1.0, 'currency' => 'QAR', 'url' => 'https://b/1',
        ]]);
        $this->app->instance(OrderCancellationsService::class, new OrderCancellationsService(fn ($s) => $fake));

        $this->endpoint(null);

        $this->actingAs($this->admin)
            ->getJson(route('orders.dashboard.cancellations'))
            ->assertOk()
            ->assertJsonPath('source', 'shopify')
            ->assertJsonCount(1, 'orders');
    }

    public function test_unknown_reasons_stay_readable(): void
    {
        $this->assertSame('Not given', OrderCancellationsService::reason(null));
        $this->assertSame('Items unavailable', OrderCancellationsService::reason('INVENTORY'));
        $this->assertSame('Something new', OrderCancellationsService::reason('SOMETHING_NEW'));
    }
}
