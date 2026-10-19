<?php

namespace Tests\Feature;

use App\Jobs\ScanShopifySkusJob;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The second way into the photo editor: SKUs already on Shopify.
 *
 * The folder route reads a shoot. This reads the storefront, for products
 * whose pictures are already live and want redoing — paste the SKUs, take each
 * product's first image, and the edited version goes back into the slot it
 * came from.
 *
 * One image per SKU, deliberately. That is the one a shopper sees in a
 * collection grid, and a product with five images should not quietly cost five
 * credits.
 */
class PhotoEditorFromShopifyTest extends TestCase
{
    use RefreshDatabase;

    private function editor(): User
    {
        return User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.photoroom.api_key' => 'live_sk_test']);
        Http::fake(['*/v2/account' => Http::response('', 503)]);
    }

    public function test_a_pasted_list_starts_a_shopify_run(): void
    {
        Queue::fake();

        $this->actingAs($this->editor())
            ->post(route('photo-editor.store-shopify'), [
                'skus' => "LLE122NIG04116\nRGN122NIG00432",
            ])
            ->assertRedirect();

        $session = PhotoEditSession::sole();

        $this->assertSame('shopify', $session->source);

        Queue::assertPushed(
            ScanShopifySkusJob::class,
            fn ($job) => $job->skus === ['LLE122NIG04116', 'RGN122NIG00432'],
        );
    }

    /**
     * A paste is whatever the clipboard held.
     *
     * A column out of a spreadsheet arrives newline-separated, a copied cell
     * range can be tab- or comma-separated, and a list typed by hand is
     * whatever the typist felt like. Duplicates go: the same SKU twice would
     * fetch one picture, edit it twice and push both into one slot.
     */
    public function test_it_reads_whatever_separator_the_paste_carried(): void
    {
        Queue::fake();

        $this->actingAs($this->editor())
            ->post(route('photo-editor.store-shopify'), [
                'skus' => " A-1 , A-2\tA-3\n\nA-1 ;A-4 ",
            ])
            ->assertRedirect();

        Queue::assertPushed(
            ScanShopifySkusJob::class,
            fn ($job) => $job->skus === ['A-1', 'A-2', 'A-3', 'A-4'],
        );
    }

    public function test_an_empty_paste_is_refused(): void
    {
        Queue::fake();

        $this->actingAs($this->editor())
            ->post(route('photo-editor.store-shopify'), ['skus' => "  \n , ; "])
            ->assertSessionHasErrors('skus');

        Queue::assertNothingPushed();
    }

    /** More than a morning's work is refused rather than quietly queued. */
    public function test_too_many_at_once_is_refused(): void
    {
        Queue::fake();

        $this->actingAs($this->editor())
            ->post(route('photo-editor.store-shopify'), [
                'skus' => collect(range(1, 201))->map(fn ($i) => "SKU-{$i}")->implode("\n"),
            ])
            ->assertSessionHasErrors('skus');

        Queue::assertNothingPushed();
    }

    /**
     * The OneDrive route is untouched.
     *
     * This was asked for as a second function, not a replacement, and the two
     * share a session, a configure screen and a push — so the thing worth
     * pinning is that adding one did not quietly change the other.
     */
    public function test_the_folder_route_still_works(): void
    {
        Queue::fake();

        $this->actingAs($this->editor())
            ->post(route('photo-editor.store'), [
                'onedrive_link' => 'https://1drv.ms/f/s!example',
                'matching_mode' => 'sku_barcode',
            ])
            ->assertRedirect();

        $this->assertSame('onedrive', PhotoEditSession::sole()->source);
    }

    /**
     * An edited photograph goes back into the slot it came from.
     *
     * Counting positions across the run is right for a folder of shoot files,
     * where nothing on Shopify corresponds to them yet. A picture fetched from
     * a product's own gallery has neighbours this run never touched sitting
     * either side of it, and the fifth SKU's photograph has no business at
     * position five of its own product.
     */
    public function test_a_fetched_photo_keeps_its_own_gallery_position(): void
    {
        $session = PhotoEditSession::create([
            'user_id' => $this->editor()->id, 'name' => 'Run',
            'onedrive_link' => '', 'source' => 'shopify',
            'edits' => ['remove_background' => true],
        ]);

        $item = PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'kind' => 'cutout', 'filename' => 'A-9.jpg', 'sku_detected' => 'A-9',
            'status' => 'edited', 'selected' => true, 'position' => 4,
            'source_url' => 'https://cdn.shopify.com/x.jpg', 'replaces_image_id' => '555',
        ]);

        $method = new \ReflectionMethod(\App\Jobs\PushEditedPhotoJob::class, 'galleryPosition');
        $method->setAccessible(true);

        $this->assertSame(4, $method->invoke(new \App\Jobs\PushEditedPhotoJob($item->id), $item));
    }

    /*
     * The scan leaves a run the configure screen can actually render.
     *
     * Items alone are not enough. That screen is built out of groups, one per
     * SKU, because its settings are chosen per SKU — and it waits for
     * scan_status to be the exact word "scanned". A first attempt here wrote
     * "done" and created no groups, so a run whose photographs were sitting in
     * the table showed "Still reading the folder" until somebody asked.
     */
    public function test_the_scan_leaves_a_run_the_configure_screen_can_render(): void
    {
        $user = $this->editor();

        $session = PhotoEditSession::create([
            'user_id' => $user->id, 'name' => 'Run', 'onedrive_link' => '',
            'source' => 'shopify', 'edits' => ['remove_background' => true],
            'status' => 'processing', 'scan_status' => 'pending',
        ]);

        $this->fakeShopify();

        (new ScanShopifySkusJob($session->id, ['A-1', 'NOPE']))->handle();

        $session->refresh();

        $this->assertSame('scanned', $session->scan_status, 'the screen waits on this exact word');
        $this->assertSame(2, $session->total_files);
        $this->assertSame(1, $session->failed_files);

        $this->assertSame(
            ['A-1', 'NOPE'],
            \App\Models\PhotoEditGroup::where('photo_edit_session_id', $session->id)
                ->orderBy('sku')->pluck('sku')->all(),
            'the configure screen renders groups, and a run with none renders nothing',
        );

        $found = PhotoEditItem::where('sku_detected', 'A-1')->sole();

        /*
         * The variant's own picture, not the product's first.
         *
         * A SKU is a colour and a size; a product is every colour together.
         * Taking images[0] fetched whichever colour sat first in the gallery,
         * so a light green shirt came back as the night blue one — and the
         * edited version would then have replaced the blue image.
         */
        $this->assertSame('https://cdn.example.com/second.jpg', $found->source_url);
        $this->assertSame('992', $found->replaces_image_id, 'it must know which image it replaces');
        $this->assertSame(2, $found->position, 'and which slot that image was in');

        $missing = PhotoEditItem::where('sku_detected', 'NOPE')->sole();

        $this->assertSame('failed', $missing->status);
        $this->assertStringContainsString('not found', $missing->error_message);
    }

    /**
     * Only the first image, and one row per product.
     *
     * A size run of eight variants shares one gallery, so eight rows would
     * spend eight credits overwriting the same slot — and a product with five
     * images should not quietly cost five.
     */
    public function test_it_takes_one_image_per_sku_and_only_the_first(): void
    {
        $session = PhotoEditSession::create([
            'user_id' => $this->editor()->id, 'name' => 'Run', 'onedrive_link' => '',
            'source' => 'shopify', 'edits' => [], 'scan_status' => 'pending',
        ]);

        $this->fakeShopify();

        (new ScanShopifySkusJob($session->id, ['A-1']))->handle();

        $this->assertSame(1, PhotoEditItem::where('photo_edit_session_id', $session->id)->count());
        $this->assertSame('992', PhotoEditItem::sole()->replaces_image_id, 'it took another colour\'s image');
    }

    /*
     * A big folder is read, and stopped at the point it would cost something.
     *
     * The ceiling used to refuse the scan: a folder over PHOTOROOM_MAX_IMAGES
     * would not be read at all, so an operator was told their batch was too
     * large before they could see what was in it — and reading a folder costs
     * nothing. It moved to start(), which is the click that queues the edits
     * and the only place Photoroom is billed.
     */
    public function test_a_large_run_is_stopped_when_it_would_be_billed_not_when_it_is_read(): void
    {
        config(['services.photoroom.max_images' => 2]);

        $user    = $this->editor();
        $session = PhotoEditSession::create([
            'user_id' => $user->id, 'name' => 'Run', 'onedrive_link' => '',
            'source' => 'shopify', 'edits' => [], 'status' => 'configuring', 'scan_status' => 'scanned',
        ]);

        $group = \App\Models\PhotoEditGroup::create([
            'photo_edit_session_id' => $session->id, 'sku' => 'A-1', 'edits' => null,
        ]);

        foreach (range(1, 3) as $n) {
            PhotoEditItem::create([
                'photo_edit_session_id' => $session->id, 'kind' => 'cutout',
                'filename' => "{$n}.jpg", 'sku_detected' => 'A-1', 'status' => 'pending',
                'onedrive_drive_id' => 'd', 'onedrive_item_id' => "i{$n}",
            ]);
        }

        $this->actingAs($user)
            ->post(route('photo-editor.start', $session), [
                'groups' => [$group->id => ['lifestyle_count' => 0]],
            ])
            ->assertSessionHas('error');

        $this->assertSame('configuring', $session->fresh()->status, 'the run started anyway');
    }

    /** And a photo marked "as is" never reaches Photoroom, so it is not counted. */
    public function test_pass_through_photos_do_not_count_towards_the_ceiling(): void
    {
        config(['services.photoroom.max_images' => 2]);

        $user    = $this->editor();
        $session = PhotoEditSession::create([
            'user_id' => $user->id, 'name' => 'Run', 'onedrive_link' => '',
            'source' => 'shopify', 'edits' => [], 'status' => 'configuring', 'scan_status' => 'scanned',
        ]);

        $group = \App\Models\PhotoEditGroup::create([
            'photo_edit_session_id' => $session->id, 'sku' => 'A-1', 'edits' => null,
        ]);

        $ids = collect(range(1, 3))->map(fn ($n) => PhotoEditItem::create([
            'photo_edit_session_id' => $session->id, 'kind' => 'cutout',
            'filename' => "{$n}.jpg", 'sku_detected' => 'A-1', 'status' => 'pending',
            'onedrive_drive_id' => 'd', 'onedrive_item_id' => "i{$n}",
        ])->id);

        $this->actingAs($user)
            ->post(route('photo-editor.start', $session), [
                'groups' => [$group->id => ['lifestyle_count' => 0, 'as_is' => [$ids->last()]]],
            ])
            ->assertSessionMissing('error');
    }

    /*
     * The review grid shows the whole run, not sixty of it.
     *
     * It used to page. Reviewing is the one part of this job that cannot be
     * hurried — somebody looks at each picture and decides — and paging broke
     * it twice over: a selection made on page one was invisible from page two,
     * and "which of these went wrong" is a question about the batch rather
     * than about a slice of it.
     */
    public function test_the_review_grid_returns_every_photo_at_once(): void
    {
        $user    = $this->editor();
        $session = PhotoEditSession::create([
            'user_id' => $user->id, 'name' => 'Run', 'onedrive_link' => '',
            'source' => 'shopify', 'edits' => [], 'status' => 'processing', 'scan_status' => 'scanned',
        ]);

        foreach (range(1, 75) as $n) {
            PhotoEditItem::create([
                'photo_edit_session_id' => $session->id, 'kind' => 'cutout',
                'filename' => sprintf('%03d.jpg', $n), 'sku_detected' => 'A-1',
                'status' => 'edited', 'edited_path' => "e/{$n}.jpg",
                'onedrive_drive_id' => 'd', 'onedrive_item_id' => "i{$n}",
            ]);
        }

        $body = $this->actingAs($user)
            ->getJson(route('photo-editor.status', $session))
            ->assertOk()
            ->json();

        $this->assertCount(75, $body['items'], 'the grid is still paging');
        $this->assertSame(1, $body['pagination']['last_page'], 'the pager would still be drawn');
        $this->assertSame(75, $body['pagination']['total']);
    }

    /*
     * A product with one colour assigns nothing, and still works.
     *
     * Shopify only records variant_ids where there is something to
     * distinguish. Most products have one set of photographs for every size,
     * so the fallback to the first image is the right answer for them — and it
     * is the only case the old behaviour got right.
     */
    public function test_a_product_with_no_variant_assignments_uses_the_first_image(): void
    {
        $session = PhotoEditSession::create([
            'user_id' => $this->editor()->id, 'name' => 'Run', 'onedrive_link' => '',
            'source' => 'shopify', 'edits' => [], 'scan_status' => 'pending',
        ]);

        $this->app->bind(\App\Services\ShopifyService::class, fn () => new class extends \App\Services\ShopifyService {
            public function __construct() {}

            public function findVariantsBySkus(array $skus, bool $throwOnFailure = false): array
            {
                return ['A-1' => [[
                    'product_id' => '7001', 'product_title' => 'A top',
                    'variant_id' => '8001', 'variant_sku' => 'A-1',
                ]]];
            }

            public function getProductImages(string $productId): array
            {
                return [
                    ['id' => '991', 'src' => 'https://cdn.example.com/only.jpg', 'position' => 1, 'variant_ids' => []],
                ];
            }
        });

        (new ScanShopifySkusJob($session->id, ['A-1']))->handle();

        $this->assertSame('991', PhotoEditItem::sole()->replaces_image_id);
    }

    /**
     * Stand in for Shopify.
     *
     * findVariantsBySkus and getProductImages are the two calls the scan makes;
     * everything else on the service is irrelevant here, and a fake that only
     * answers those two says so.
     */
    private function fakeShopify(): void
    {
        $this->app->bind(\App\Services\ShopifyService::class, fn () => new class extends \App\Services\ShopifyService {
            public function __construct() {}

            public function findVariantsBySkus(array $skus, bool $throwOnFailure = false): array
            {
                return in_array('A-1', $skus, true) ? ['A-1' => [[
                    'product_id'    => '7001',
                    'product_title' => 'A top',
                    'variant_id'    => '8001',
                    'variant_sku'   => 'A-1',
                ]]] : [];
            }

            public function getProductImages(string $productId): array
            {
                // Two colours on one product: the first image belongs to
                // another variant, the second to ours.
                return [
                    ['id' => '991', 'src' => 'https://cdn.example.com/first.jpg',  'position' => 1, 'variant_ids' => [9999]],
                    ['id' => '992', 'src' => 'https://cdn.example.com/second.jpg', 'position' => 2, 'variant_ids' => [8001]],
                ];
            }
        });
    }
}
