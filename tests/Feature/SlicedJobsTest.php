<?php

namespace Tests\Feature;

use App\Jobs\PushBarcodeImagesJob;
use App\Jobs\RunBarcodeImageDownloadJob;
use App\Jobs\RunStoreImageSyncJob;
use App\Models\BarcodeImageItem;
use App\Models\BarcodeImageSession;
use App\Models\Store;
use App\Models\StoreMigrationSession;
use App\Models\User;
use App\Services\ProductImageScraper;
use App\Services\ShopifyService;
use App\Support\Queues;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * The long jobs work for a slice and then queue the rest of their run behind
 * everyone else's (RunsInSlices), so one person's two-thousand-SKU sync cannot
 * hold a worker for hours.
 *
 * slice_seconds = 0 hands over after every item, so every boundary is crossed.
 * On the sync queue a hand-over runs straight away, which lets a whole sliced
 * run be checked end to end; with the queue faked, a single slice can be
 * checked for exactly what it passed on.
 */
class SlicedJobsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.slice_seconds' => 0]);

        $this->user = User::create([
            'name' => 'Slice Tester', 'email' => 'slices@example.test',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    private function onePixelPng(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
    }

    /** Hand each store its own stand-in Shopify, for every slice of the run. */
    private function shopifyPerStore(array $byStoreId): void
    {
        $this->app->bind(ShopifyService::class, fn ($app, $params) => $byStoreId[$params['store']->id]);
    }

    // ── Barcode image download ───────────────────────────────────────────────

    private function grab(string $barcodes): BarcodeImageSession
    {
        Http::fake([
            'shop.test/search/suggest.json*' => Http::response(['resources' => ['results' => ['products' => []]]]),
            '*' => Http::response('', 404),
        ]);

        return BarcodeImageSession::create([
            'user_id'      => $this->user->id,
            'site_url'     => 'https://shop.test',
            'status'       => 'pending',
            'raw_barcodes' => $barcodes,
        ]);
    }

    public function test_a_sliced_grab_counts_every_barcode_once_and_finishes(): void
    {
        $session = $this->grab("0000000000000\n1111111111111\n2222222222222");

        app(RunBarcodeImageDownloadJob::class, ['sessionId' => $session->id])
            ->handle(app(ProductImageScraper::class));

        $session->refresh();

        $this->assertSame('completed', $session->status);
        $this->assertSame(3, $session->processed);
        $this->assertSame(3, $session->missing_count);
        $this->assertSame(
            ['0000000000000', '1111111111111', '2222222222222'],
            $session->items()->orderBy('id')->pluck('barcode')->all(),
        );
    }

    public function test_a_grab_slice_hands_on_the_next_barcode_and_the_sites_it_may_still_ask(): void
    {
        $session = $this->grab("0000000000000\n1111111111111");

        Queue::fake();

        (new RunBarcodeImageDownloadJob($session->id))->onQueue(Queues::AUDITS)
            ->handle(app(ProductImageScraper::class));

        $this->assertSame('running', $session->refresh()->status);
        $this->assertSame(1, $session->processed);

        Queue::assertPushedOn(Queues::AUDITS, RunBarcodeImageDownloadJob::class, function ($next) use ($session) {
            return $next->sessionId === $session->id
                && $next->resume['offset'] === 1
                && array_keys($next->resume['usable']) === ['https://shop.test']
                && $next->resume['strikes'] === ['https://shop.test' => 0];
        });
    }

    // ── Barcode image push ───────────────────────────────────────────────────

    private function grabReadyToPush(Store $store, array $barcodes): BarcodeImageSession
    {
        $session = BarcodeImageSession::create([
            'user_id'            => $this->user->id,
            'site_url'           => 'https://shop.test',
            'status'             => 'completed',
            'push_store_id'      => $store->id,
            'push_matching_mode' => 'sku_barcode',
            'push_status'        => 'pending',
        ]);

        foreach ($barcodes as $barcode) {
            BarcodeImageItem::create([
                'barcode_image_session_id' => $session->id,
                'barcode'                  => $barcode,
                'status'                   => 'found',
                'image_count'              => 1,
            ]);

            $folder = $session->folderFor($barcode);
            mkdir($folder, 0755, true);
            file_put_contents("{$folder}/{$barcode}-1.jpg", $this->onePixelPng());
        }

        return $session;
    }

    public function test_a_sliced_push_sends_every_barcode_once_and_finishes(): void
    {
        $store   = Store::create(['name' => 'Dest', 'shopify_domain' => 'dest.myshopify.com', 'is_active' => true]);
        $session = $this->grabReadyToPush($store, ['AAA', 'BBB', 'CCC']);

        $shopify = Mockery::mock(ShopifyService::class);
        $shopify->shouldReceive('findVariantsBySkuOrBarcode')->times(3)
            ->andReturnUsing(fn ($code) => [['product_id' => "P-{$code}", 'variant_id' => "V-{$code}", 'product_title' => $code]]);
        $shopify->shouldReceive('uploadImageToProduct')->times(3)->andReturn('img');
        $this->shopifyPerStore([$store->id => $shopify]);

        (new PushBarcodeImagesJob($session->id))->handle();

        $session->refresh();

        $this->assertSame('completed', $session->push_status);
        $this->assertSame(3, $session->push_total);
        $this->assertSame(3, $session->push_done);
        $this->assertSame(3, $session->push_pushed);
        $this->assertSame(['pushed', 'pushed', 'pushed'], $session->items()->orderBy('id')->pluck('push_status')->all());

        $session->deleteFiles();
    }

    public function test_a_push_slice_hands_on_from_the_last_barcode_it_finished(): void
    {
        $store   = Store::create(['name' => 'Dest', 'shopify_domain' => 'dest.myshopify.com', 'is_active' => true]);
        $session = $this->grabReadyToPush($store, ['AAA', 'BBB']);

        $shopify = Mockery::mock(ShopifyService::class);
        $shopify->shouldReceive('findVariantsBySkuOrBarcode')->once()
            ->andReturn([['product_id' => 'P', 'variant_id' => 'V', 'product_title' => 'AAA']]);
        $shopify->shouldReceive('uploadImageToProduct')->once()->andReturn('img');
        $this->shopifyPerStore([$store->id => $shopify]);

        Queue::fake();

        (new PushBarcodeImagesJob($session->id))->onQueue(Queues::AUDITS)->handle();

        $first = $session->items()->orderBy('id')->first();

        $this->assertSame('pushing', $session->refresh()->push_status);
        $this->assertSame(1, $session->push_done);

        Queue::assertPushedOn(Queues::AUDITS, PushBarcodeImagesJob::class,
            fn ($next) => $next->sessionId === $session->id && $next->afterId === $first->id);

        $session->deleteFiles();
    }

    // ── Store image sync ─────────────────────────────────────────────────────

    /** @return array{0: Store, 1: Store} */
    private function stores(): array
    {
        return [
            Store::create(['name' => 'From', 'shopify_domain' => 'from.myshopify.com', 'is_active' => true]),
            Store::create(['name' => 'To', 'shopify_domain' => 'to.myshopify.com', 'is_active' => true]),
        ];
    }

    private function migration(Store $from, Store $to, string $token, string $type, int $total): void
    {
        StoreMigrationSession::create([
            'user_id'        => $this->user->id,
            'from_store_id'  => $from->id,
            'to_store_id'    => $to->id,
            'migration_type' => $type,
            'token'          => $token,
            'status'         => 'running',
            'total_skus'     => $total,
        ]);
    }

    private function csvRows(string $token): array
    {
        $path = storage_path("app/store-sync/{$token}.csv");
        $rows = array_map('str_getcsv', file($path, FILE_IGNORE_NEW_LINES));
        @unlink($path);

        return $rows;
    }

    public function test_a_sliced_images_only_sync_writes_one_report_with_every_sku(): void
    {
        [$from, $to] = $this->stores();
        $this->migration($from, $to, 'slice-images', 'images_only', 3);

        $photo = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($photo, $this->onePixelPng());

        $source = Mockery::mock(ShopifyService::class);
        $source->shouldReceive('findVariantsBySku')
            ->andReturnUsing(fn ($sku) => [['product_id' => '10', 'variant_id' => "V{$sku}", 'product_title' => "Title {$sku}"]]);
        $source->shouldReceive('getFullProduct')
            ->andReturnUsing(fn () => ['variants' => [], 'images' => [['id' => '1', 'src' => $photo, 'alt' => '']]]);
        $source->shouldReceive('getVariantMedia')->andReturn([]);

        $target = Mockery::mock(ShopifyService::class);
        $target->shouldReceive('findVariantsBySku')
            ->andReturnUsing(fn ($sku) => [['product_id' => '20', 'variant_id' => "T{$sku}"]]);
        $target->shouldReceive('uploadImageToProduct')->times(3)->andReturn('img');
        $target->shouldReceive('setVariantImage')->times(3);

        $this->shopifyPerStore([$from->id => $source, $to->id => $target]);

        (new RunStoreImageSyncJob('slice-images', $from->id, $to->id, ['A', 'B', 'C'], 'images_only'))->handle();

        $session = StoreMigrationSession::where('token', 'slice-images')->sole();
        $this->assertSame('completed', $session->status);
        $this->assertSame(3, (int) $session->success_count);
        $this->assertSame(0, (int) $session->failed_count);

        $rows = $this->csvRows('slice-images');
        $this->assertSame('SKU', $rows[0][0], 'the header is written once, by the first slice');
        $this->assertSame(['A', 'B', 'C'], array_column(array_slice($rows, 1), 0));

        @unlink($photo);
    }

    /*
     * The case slicing could break: two colours of one product, listed
     * together, must become one new product with both variants — not two
     * products because each landed in a different slice.
     */
    public function test_siblings_resolved_in_different_slices_still_become_one_product(): void
    {
        [$from, $to] = $this->stores();
        $this->migration($from, $to, 'slice-full', 'full_product', 2);

        $sourceProduct = [
            'id'       => '10',
            'title'    => 'Scarf',
            'options'  => [['name' => 'Colour']],
            'variants' => [
                ['id' => '101', 'sku' => 'RED', 'option1' => 'Red', 'image_id' => null],
                ['id' => '102', 'sku' => 'BLUE', 'option1' => 'Blue', 'image_id' => null],
            ],
            'images'   => [],
        ];

        $source = Mockery::mock(ShopifyService::class);
        $source->shouldReceive('findVariantsBySku')->andReturnUsing(fn ($sku) => [[
            'product_id' => '10', 'variant_id' => $sku === 'RED' ? '101' : '102',
            'product_title' => 'Scarf', 'collections' => ['Scarves'],
        ]]);
        $source->shouldReceive('getFullProduct')->with('10')->andReturn($sourceProduct);
        $source->shouldReceive('getVariantMedia')->andReturn([]);
        $source->shouldReceive('getProductMaterialAndFeatures')->andReturn(['material' => null, 'features' => []]);

        $created = [];
        $target  = Mockery::mock(ShopifyService::class);
        $target->shouldReceive('getAllCollectionTitles')->andReturn([]);
        $target->shouldReceive('findVariantsBySku')->andReturn([]);
        $target->shouldReceive('createFullProduct')->once()->andReturnUsing(function ($product) use (&$created) {
            $created[] = $product;

            return ['product_id' => '99'];
        });

        $this->shopifyPerStore([$from->id => $source, $to->id => $target]);

        (new RunStoreImageSyncJob('slice-full', $from->id, $to->id, ['RED', 'BLUE'], 'full_product'))->handle();

        $this->assertCount(1, $created);
        $this->assertSame(['RED', 'BLUE'], array_column($created[0]['variants'], 'sku'));

        $session = StoreMigrationSession::where('token', 'slice-full')->sole();
        $this->assertSame('completed', $session->status);
        $this->assertSame(2, (int) $session->success_count);

        $this->assertCount(3, $this->csvRows('slice-full'));
    }

    public function test_a_store_sync_slice_hands_on_its_place_and_counts(): void
    {
        [$from, $to] = $this->stores();
        $this->migration($from, $to, 'slice-handover', 'images_only', 2);

        $source = Mockery::mock(ShopifyService::class);
        $source->shouldReceive('findVariantsBySku')->once()->andReturn([]);

        $this->shopifyPerStore([$from->id => $source, $to->id => Mockery::mock(ShopifyService::class)]);

        Queue::fake();

        (new RunStoreImageSyncJob('slice-handover', $from->id, $to->id, ['A', 'B'], 'images_only'))
            ->onQueue(Queues::UPLOADS)
            ->handle();

        Queue::assertPushedOn(Queues::UPLOADS, RunStoreImageSyncJob::class, function ($next) {
            return $next->resume['offset'] === 1
                && $next->resume['processed'] === 1
                && $next->resume['failed'] === 1;
        });

        $this->assertSame('running', StoreMigrationSession::where('token', 'slice-handover')->value('status'));

        @unlink(storage_path('app/store-sync/slice-handover.csv'));
    }
}
