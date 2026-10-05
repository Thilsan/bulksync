<?php

namespace Tests\Feature;

use App\Jobs\SyncProductSalesJob;
use App\Models\ProductSalesDaily;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Models\User;
use App\Services\ProductSalesSyncService;
use App\Services\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

/**
 * Best, low and non-selling products, from the tables the nightly sync fills.
 *
 * What is worth holding still: a product only counts as "not selling" when it
 * had a fair chance to (live, in stock, already on the site), cancelled and
 * test orders never count as sales, and nobody sees a website they have no
 * access to.
 */
class ProductPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $overrides = []): User
    {
        // is_active matters: an inactive user is bounced to the login screen.
        return User::create(array_merge([
            'name'                     => 'Buyer',
            'email'                    => uniqid() . '@example.test',
            'password'                 => 'password',
            'is_active'                => true,
            'perm_product_performance' => true,
        ], $overrides));
    }

    private function store(User $user, string $name = 'Main Store', array $overrides = []): Store
    {
        $store = Store::create([
            'name'                 => $name,
            'shopify_domain'       => strtolower(str_replace(' ', '-', $name)) . '.myshopify.com',
            'shopify_access_token' => 'shpat_test',
            'user_id'              => $user->id,
        ]);

        $store->forceFill(array_merge([
            'sales_synced_at'    => now(),
            'sales_covered_from' => now()->subYear()->toDateString(),
            'sales_currency'     => 'QAR',
        ], $overrides))->save();

        $store->users()->attach($user->id);

        return $store;
    }

    private function product(Store $store, string $id, array $overrides = []): StoreProduct
    {
        return StoreProduct::create(array_merge([
            'store_id'           => $store->id,
            'product_id'         => $id,
            'title'              => "Product {$id}",
            'vendor'             => 'Brand A',
            'status'             => 'active',
            'total_inventory'    => 10,
            'sku'                => "SKU-{$id}",
            'shopify_created_at' => now()->subYear(),
        ], $overrides));
    }

    private function sale(Store $store, string $productId, int $units, int $daysAgo = 1, float $revenue = 100): void
    {
        ProductSalesDaily::create([
            'store_id'   => $store->id,
            'product_id' => $productId,
            'date'       => now()->subDays($daysAgo)->toDateString(),
            'units'      => $units,
            'revenue'    => $revenue,
        ]);
    }

    public function test_page_needs_the_permission(): void
    {
        $user = $this->user(['perm_product_performance' => false]);

        $this->actingAs($user)->get('/product-performance')->assertForbidden();
    }

    public function test_best_sellers_are_ranked_by_units_in_the_range(): void
    {
        $user  = $this->user();
        $store = $this->store($user);
        $this->product($store, '1', ['title' => 'Linen Shirt']);
        $this->product($store, '2', ['title' => 'Silk Scarf']);
        $this->product($store, '3', ['title' => 'Old Favourite']);

        $this->sale($store, '1', 3);
        $this->sale($store, '2', 7);
        $this->sale($store, '1', 2, daysAgo: 3);
        $this->sale($store, '3', 50, daysAgo: 60); // outside a 30-day range

        $response = $this->actingAs($user)->get('/product-performance?days=30');

        $response->assertOk()->assertSeeInOrder(['Silk Scarf', 'Linen Shirt']);
        $response->assertDontSee('Old Favourite');
        $this->assertSame([7, 5], collect($response->viewData('rows')->items())->pluck('units')->map(fn ($u) => (int) $u)->all());
    }

    public function test_a_website_without_access_is_never_shown(): void
    {
        $user   = $this->user();
        $mine   = $this->store($user, 'Mine');
        $other  = $this->store($this->user(), 'Theirs');

        $this->product($mine, '1', ['title' => 'Visible Dress']);
        $this->product($other, '2', ['title' => 'Hidden Dress']);
        $this->sale($mine, '1', 1);
        $this->sale($other, '2', 99);

        $this->actingAs($user)->get("/product-performance?store={$other->id}")
            ->assertOk()
            ->assertSee('Visible Dress')
            ->assertDontSee('Hidden Dress');
    }

    public function test_no_sales_lists_only_products_that_had_a_fair_chance(): void
    {
        $user  = $this->user();
        $store = $this->store($user);

        $this->product($store, '1', ['title' => 'Dusty Jacket']);
        $this->product($store, '2', ['title' => 'Brand New Arrival', 'shopify_created_at' => now()->subDays(3)]);
        $this->product($store, '3', ['title' => 'Draft Coat', 'status' => 'draft']);
        $this->product($store, '4', ['title' => 'Sold Out Boot', 'total_inventory' => 0]);
        $this->product($store, '5', ['title' => 'Selling Tee']);
        $this->sale($store, '5', 4);
        $this->sale($store, '1', 2, daysAgo: 120); // sold once, long ago

        $response = $this->actingAs($user)->get('/product-performance?tab=none&days=30');

        $response->assertOk()
            ->assertSee('Dusty Jacket')
            ->assertSee(now()->subDays(120)->format('j M Y'))
            ->assertDontSee('Brand New Arrival')
            ->assertDontSee('Draft Coat')
            ->assertDontSee('Sold Out Boot')
            ->assertDontSee('Selling Tee');

        $this->assertSame(1, $response->viewData('summary')['idle']);
    }

    public function test_low_sellers_use_the_threshold(): void
    {
        $user  = $this->user();
        $store = $this->store($user);
        $this->product($store, '1', ['title' => 'One Sold']);
        $this->product($store, '2', ['title' => 'Three Sold']);
        $this->product($store, '3', ['title' => 'Ten Sold']);
        $this->sale($store, '1', 1);
        $this->sale($store, '2', 3);
        $this->sale($store, '3', 10);

        $this->actingAs($user)->get('/product-performance?tab=low&max=2')
            ->assertSee('One Sold')->assertDontSee('Three Sold')->assertDontSee('Ten Sold');

        $this->actingAs($user)->get('/product-performance?tab=low&max=5')
            ->assertSee('One Sold')->assertSee('Three Sold')->assertDontSee('Ten Sold');
    }

    public function test_csv_download_holds_every_row(): void
    {
        $user  = $this->user();
        $store = $this->store($user);
        $this->product($store, '1', ['title' => 'Linen Shirt', 'sku' => 'LS-01']);
        $this->sale($store, '1', 4, revenue: 250.5);

        $csv = $this->actingAs($user)->get('/product-performance/download?days=30')->streamedContent();

        $this->assertStringContainsString('"Main Store","Linen Shirt",LS-01,"Brand A",,4,250.50,QAR,10,' . now()->subDay()->toDateString() . ',1', $csv);
    }

    public function test_sync_counts_real_orders_on_the_shop_day_and_rebuilds_the_catalogue(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');

        $user  = $this->user();
        $store = $this->store($user, 'Main Store', ['sales_synced_at' => null, 'sales_covered_from' => null]);
        $this->product($store, '999', ['title' => 'Deleted In Shopify']);

        $products = [
            ['id' => 'gid://shopify/Product/1', 'title' => 'Linen Shirt', 'handle' => 'linen-shirt', 'vendor' => 'Brand A', 'productType' => 'Shirts', 'status' => 'ACTIVE', 'totalInventory' => 12, 'createdAt' => '2025-01-01T00:00:00Z', 'featuredImage' => ['url' => 'https://cdn.example/a.jpg']],
            ['sku' => '', '__parentId' => 'gid://shopify/Product/1'],
            ['sku' => 'LS-01', '__parentId' => 'gid://shopify/Product/1'],
            ['sku' => 'LS-02', '__parentId' => 'gid://shopify/Product/1'],
        ];

        $orders = [
            // A line item before its order: the export does not promise order.
            ['quantity' => 2, 'product' => ['id' => 'gid://shopify/Product/1'], 'discountedTotalSet' => ['shopMoney' => ['amount' => '80.00']], '__parentId' => 'gid://shopify/Order/10'],
            // 22:30 UTC on the 1st is the 2nd in Doha.
            ['id' => 'gid://shopify/Order/10', 'createdAt' => '2026-10-01T22:30:00Z', 'cancelledAt' => null, 'test' => false],
            ['id' => 'gid://shopify/Order/11', 'createdAt' => '2026-10-02T09:00:00Z', 'cancelledAt' => null, 'test' => false],
            ['quantity' => 1, 'product' => ['id' => 'gid://shopify/Product/1'], 'discountedTotalSet' => ['shopMoney' => ['amount' => '40.00']], '__parentId' => 'gid://shopify/Order/11'],
            ['quantity' => 1, 'product' => null, 'discountedTotalSet' => ['shopMoney' => ['amount' => '5.00']], '__parentId' => 'gid://shopify/Order/11'],
            ['id' => 'gid://shopify/Order/12', 'createdAt' => '2026-10-02T09:00:00Z', 'cancelledAt' => '2026-10-03T09:00:00Z', 'test' => false],
            ['quantity' => 9, 'product' => ['id' => 'gid://shopify/Product/1'], 'discountedTotalSet' => ['shopMoney' => ['amount' => '360.00']], '__parentId' => 'gid://shopify/Order/12'],
            ['id' => 'gid://shopify/Order/13', 'createdAt' => '2026-10-02T09:00:00Z', 'cancelledAt' => null, 'test' => true],
            ['quantity' => 9, 'product' => ['id' => 'gid://shopify/Product/1'], 'discountedTotalSet' => ['shopMoney' => ['amount' => '360.00']], '__parentId' => 'gid://shopify/Order/13'],
        ];

        $write = fn (array $lines) => function (string $path) use ($lines) {
            @mkdir(dirname($path), 0775, true);
            file_put_contents($path, implode("\n", array_map('json_encode', $lines)) . "\n");
            return true;
        };

        $shopify = Mockery::mock(ShopifyService::class);
        $shopify->shouldReceive('getShopSettings')->andReturn(['timezone' => 'Asia/Qatar', 'currency' => 'QAR']);
        $shopify->shouldReceive('exportProductsForPerformance')->andReturnUsing($write($products));
        $shopify->shouldReceive('exportOrdersForPerformance')
            ->withArgs(fn (Carbon $from) => $from->toDateString() === '2025-10-05')
            ->andReturnUsing(fn ($from, $path) => $write($orders)($path));

        (new ProductSalesSyncService(fn () => $shopify))->sync($store);

        $product = StoreProduct::where('store_id', $store->id)->sole();
        $this->assertSame(['1', 'Linen Shirt', 'LS-01', 'active', 12], [$product->product_id, $product->title, $product->sku, $product->status, $product->total_inventory]);

        $day = ProductSalesDaily::where('store_id', $store->id)->sole();
        $this->assertSame(['2026-10-02', 3, 120.0], [$day->date->toDateString(), $day->units, $day->revenue]);

        $store->refresh();
        $this->assertSame('2026-10-02', $store->sales_covered_from->toDateString());
        $this->assertSame('QAR', $store->sales_currency);
        $this->assertNull($store->sales_sync_error);

        // Export files are gone once the run finishes.
        $this->assertSame([], glob(storage_path('app/' . ProductSalesSyncService::WORK_DIR . "/{$store->id}-*")) ?: []);

        Carbon::setTestNow();
    }

    public function test_a_later_sync_only_rewrites_the_recent_window(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');

        $user  = $this->user();
        $store = $this->store($user);
        $this->sale($store, '1', 5, daysAgo: 100);
        $this->sale($store, '1', 5, daysAgo: 2); // gets replaced: the order was cancelled since

        $shopify = Mockery::mock(ShopifyService::class);
        $shopify->shouldReceive('getShopSettings')->andReturn(['timezone' => 'UTC', 'currency' => 'QAR']);
        $shopify->shouldReceive('exportProductsForPerformance')->andReturn(false);
        $shopify->shouldReceive('exportOrdersForPerformance')
            ->withArgs(fn (Carbon $from) => $from->toDateString() === '2026-09-21')
            ->andReturn(false);

        (new ProductSalesSyncService(fn () => $shopify))->sync($store);

        $this->assertSame([now()->subDays(100)->toDateString()], ProductSalesDaily::pluck('date')->map->toDateString()->all());

        Carbon::setTestNow();
    }

    public function test_a_failed_sync_is_recorded_on_the_store(): void
    {
        $user  = $this->user();
        $store = $this->store($user);

        $job = new class($store->id) extends SyncProductSalesJob {
            protected function service(): ProductSalesSyncService
            {
                $service = Mockery::mock(ProductSalesSyncService::class);
                $service->shouldReceive('sync')->andThrow(new \RuntimeException('Access denied for orders field'));

                return $service;
            }
        };

        $job->handle();

        $this->assertSame('Access denied for orders field', $store->fresh()->sales_sync_error);
        $this->actingAs($user)->get('/product-performance')->assertSee('Access denied for orders field');
    }

    public function test_the_nightly_job_fans_out_one_job_per_connected_store(): void
    {
        Bus::fake();

        $user = $this->user();
        $a = $this->store($user, 'A');
        $b = $this->store($user, 'B');
        Store::create(['name' => 'Not connected', 'shopify_domain' => 'pending.myshopify.com', 'user_id' => $user->id]);

        (new SyncProductSalesJob)->handle();

        Bus::assertDispatchedTimes(SyncProductSalesJob::class, 2);
        Bus::assertDispatched(SyncProductSalesJob::class, fn ($job) => $job->storeId === $a->id);
        Bus::assertDispatched(SyncProductSalesJob::class, fn ($job) => $job->storeId === $b->id);
    }

    public function test_prune_drops_rows_past_the_keep_window(): void
    {
        $user  = $this->user();
        $store = $this->store($user);
        $this->sale($store, '1', 1, daysAgo: ProductSalesDaily::KEEP_DAYS + 5);
        $this->sale($store, '1', 1, daysAgo: 10);

        $this->assertSame(1, ProductSalesDaily::prune());
        $this->assertSame(1, ProductSalesDaily::count());
    }
}
