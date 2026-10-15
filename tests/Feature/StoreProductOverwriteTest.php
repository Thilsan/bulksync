<?php

namespace Tests\Feature;

use App\Jobs\RunStoreImageSyncJob;
use App\Models\Store;
use App\Models\StoreMigrationSession;
use App\Models\User;
use App\Services\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Full Product with "update products the target store already has": the
 * target's product becomes a copy of the source's — or, when the two stores
 * hold the product differently, is left exactly as it was.
 */
class StoreProductOverwriteTest extends TestCase
{
    use RefreshDatabase;

    private Store $from;
    private Store $to;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::create([
            'name' => 'Migrator', 'email' => 'migrate@example.test',
            'password' => 'password', 'is_active' => true,
        ]);

        $this->from = Store::create(['name' => 'Blue Salon', 'shopify_domain' => 'from.myshopify.com', 'is_active' => true]);
        $this->to   = Store::create(['name' => 'Paris Gallery', 'shopify_domain' => 'to.myshopify.com', 'is_active' => true]);

        StoreMigrationSession::create([
            'user_id' => $user->id, 'from_store_id' => $this->from->id, 'to_store_id' => $this->to->id,
            'migration_type' => 'full_product', 'token' => 'overwrite', 'status' => 'running', 'total_skus' => 1,
        ]);
    }

    private function sourceProduct(): array
    {
        return [
            'id'       => '10',
            'title'    => 'Giantto Watch',
            'options'  => [['name' => 'Colour', 'values' => ['Grey', 'Black']]],
            'variants' => [
                ['id' => '101', 'sku' => 'GREY', 'option1' => 'Grey', 'image_id' => null],
                ['id' => '102', 'sku' => 'BLACK', 'option1' => 'Black', 'image_id' => null],
            ],
            'images'   => [['id' => '1', 'src' => 'https://cdn.test/a.jpg', 'position' => 1]],
        ];
    }

    private function source(): ShopifyService
    {
        $source = Mockery::mock(ShopifyService::class);
        $source->shouldReceive('findVariantsBySku')->andReturn([[
            'product_id' => '10', 'variant_id' => '101', 'product_title' => 'Giantto Watch', 'collections' => [],
        ]]);
        $source->shouldReceive('getFullProduct')->with('10')->andReturn($this->sourceProduct());
        $source->shouldReceive('getProductSeo')->andReturn(['title' => 'Giantto Watch | Qatar', 'description' => null]);
        $source->shouldReceive('getProductMaterialAndFeatures')->andReturn(['material' => null, 'features' => []]);

        return $source;
    }

    private function migrate(ShopifyService $target, bool $overwrite = true): array
    {
        $byStore = [$this->from->id => $this->source(), $this->to->id => $target];
        $this->app->bind(ShopifyService::class, fn ($app, $params) => $byStore[$params['store']->id]);

        (new RunStoreImageSyncJob('overwrite', $this->from->id, $this->to->id, ['GREY'], 'full_product', overwriteExisting: $overwrite))->handle();

        $path = storage_path('app/store-sync/overwrite.csv');
        $rows = array_map('str_getcsv', file($path, FILE_IGNORE_NEW_LINES));
        @unlink($path);

        return $rows;
    }

    public function test_an_existing_product_is_mirrored_keeping_its_matching_variant_ids(): void
    {
        $target = Mockery::mock(ShopifyService::class);
        $target->shouldReceive('getAllCollectionTitles')->andReturn([]);
        $target->shouldReceive('findVariantsBySku')->with('GREY')->andReturn([['product_id' => '20', 'variant_id' => '201']]);
        // BLACK is new to the target and nowhere else in it.
        $target->shouldReceive('findVariantsBySku')->with('BLACK')->andReturn([]);
        $target->shouldReceive('getFullProduct')->with('20')->andReturn([
            'id' => '20',
            'variants' => [
                ['id' => '201', 'sku' => 'grey'],
                ['id' => '202', 'sku' => 'OLD-RED'],
            ],
        ]);
        $target->shouldReceive('overwriteProduct')->once()
            ->withArgs(fn ($id, $product, $ids, $seo) => $id === '20'
                && $product['title'] === 'Giantto Watch'
                && $ids === ['GREY' => '201', 'OLD-RED' => '202']
                && $seo['title'] === 'Giantto Watch | Qatar')
            ->andReturn([]);
        $target->shouldNotReceive('createFullProduct');
        $target->shouldNotReceive('uploadImageToProduct');

        $rows = $this->migrate($target);

        $this->assertStringContainsString('Updated existing product (ID: 20): 2 variant(s), 1 image(s) replaced', $rows[1][4]);
        $this->assertStringContainsString('removed variants: OLD-RED', $rows[1][4]);
        $this->assertSame(1, (int) StoreMigrationSession::where('token', 'overwrite')->value('success_count'));
    }

    public function test_without_the_option_an_existing_product_only_gets_photos(): void
    {
        $target = Mockery::mock(ShopifyService::class);
        $target->shouldReceive('getAllCollectionTitles')->andReturn([]);
        $target->shouldReceive('findVariantsBySku')->andReturn([['product_id' => '20', 'variant_id' => '201']]);
        $target->shouldNotReceive('overwriteProduct');

        $source = $this->source();
        $source->shouldReceive('getVariantMedia')->andReturn([]);

        $byStore = [$this->from->id => $source, $this->to->id => $target];
        $this->app->bind(ShopifyService::class, fn ($app, $params) => $byStore[$params['store']->id]);

        (new RunStoreImageSyncJob('overwrite', $this->from->id, $this->to->id, ['GREY'], 'full_product'))->handle();

        @unlink(storage_path('app/store-sync/overwrite.csv'));
        $this->assertSame('completed', StoreMigrationSession::where('token', 'overwrite')->value('status'));
    }

    /*
     * The target keeps each colour as its own product. Mirroring the whole
     * source product onto the grey one would put BLACK on two products.
     */
    public function test_a_source_sku_living_on_another_target_product_stops_the_overwrite(): void
    {
        $target = Mockery::mock(ShopifyService::class);
        $target->shouldReceive('getAllCollectionTitles')->andReturn([]);
        $target->shouldReceive('findVariantsBySku')->with('GREY')->andReturn([['product_id' => '20', 'variant_id' => '201']]);
        $target->shouldReceive('findVariantsBySku')->with('BLACK')->andReturn([['product_id' => '30', 'variant_id' => '301']]);
        $target->shouldReceive('getFullProduct')->with('20')->andReturn(['id' => '20', 'variants' => [['id' => '201', 'sku' => 'GREY']]]);
        $target->shouldNotReceive('overwriteProduct');

        $rows = $this->migrate($target);

        $this->assertSame('Not updated: SKU BLACK is already on another product in the target store', $rows[1][4]);
        $this->assertSame(1, (int) StoreMigrationSession::where('token', 'overwrite')->value('failed_count'));
    }
}
