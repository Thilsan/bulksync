<?php

namespace Tests\Feature;

use App\Models\PhotoEditGroup;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The order photos appear in on the product page.
 *
 * Filename order was doing this by accident, and only ever worked for shoots
 * named so that the wanted photo sorted first. What is asserted here is that a
 * chosen order is stored, shown back, and — the part that actually matters —
 * survives being uploaded by several workers at once, since the first image on
 * a Shopify product is the one customers see in every collection grid.
 */
class PhotoEditorOrderTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(): PhotoEditSession
    {
        return PhotoEditSession::create([
            'user_id'       => User::factory()->create(['is_active' => true, 'perm_photo_editor' => true])->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => ['remove_background' => true, 'background_mode' => 'white'],
            'status'        => 'configuring',
            'scan_status'   => 'scanned',
        ]);
    }

    private function photo(PhotoEditSession $session, string $sku, string $filename, array $extra = []): PhotoEditItem
    {
        return PhotoEditItem::create(array_merge([
            'photo_edit_session_id' => $session->id,
            'kind'                  => 'cutout',
            'filename'              => $filename,
            'sku_detected'          => $sku,
            'status'                => 'pending',
            'onedrive_drive_id'     => 'drive-1',
            'onedrive_item_id'      => 'item-' . $filename,
        ], $extra));
    }

    public function test_the_order_the_thumbnails_were_dragged_into_is_stored(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $back    = $this->photo($session, 'BAG-1', 'back.jpg');
        $front   = $this->photo($session, 'BAG-1', 'front.jpg');
        $side    = $this->photo($session, 'BAG-1', 'side.jpg');

        $group = PhotoEditGroup::create([
            'photo_edit_session_id' => $session->id,
            'sku'                   => 'BAG-1',
            'edits'                 => null,
        ]);

        // Front first — which filename order would never have produced.
        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'groups' => [$group->id => [
                'lifestyle_count' => 0,
                'order'           => [$front->id, $side->id, $back->id],
            ]],
        ])->assertRedirect(route('photo-editor.show', $session));

        $this->assertSame(1, $front->fresh()->position);
        $this->assertSame(2, $side->fresh()->position);
        $this->assertSame(3, $back->fresh()->position);

        $this->assertSame(
            ['front.jpg', 'side.jpg', 'back.jpg'],
            PhotoEditItem::where('photo_edit_session_id', $session->id)
                ->inDisplayOrder()->pluck('filename')->all(),
        );
    }

    /** A run made before ordering existed keeps the order it always had. */
    public function test_photos_never_ordered_still_come_out_by_filename(): void
    {
        $session = $this->makeSession();
        $this->photo($session, 'BAG-1', 'c.jpg');
        $this->photo($session, 'BAG-1', 'a.jpg');
        $this->photo($session, 'BAG-1', 'b.jpg');

        $this->assertSame(
            ['a.jpg', 'b.jpg', 'c.jpg'],
            PhotoEditItem::where('photo_edit_session_id', $session->id)
                ->inDisplayOrder()->pluck('filename')->all(),
        );
    }

    /**
     * The gallery position is worked out per photo, not from upload sequence.
     *
     * Each photo is pushed by its own job and several run at once, so the order
     * they reach Shopify in is not an order at all. Every photo therefore has
     * to be able to say where it belongs without knowing anything about the
     * others' timing — which is what this checks, by asking in the wrong order
     * on purpose.
     */
    public function test_each_photo_knows_its_gallery_position_whatever_the_upload_order(): void
    {
        $session = $this->makeSession();

        $third  = $this->photo($session, 'BAG-1', 'back.jpg',  ['position' => 3, 'selected' => true]);
        $first  = $this->photo($session, 'BAG-1', 'front.jpg', ['position' => 1, 'selected' => true]);
        $second = $this->photo($session, 'BAG-1', 'side.jpg',  ['position' => 2, 'selected' => true]);

        // A SKU that sorts after these must not shift them along. One sorting
        // before them deliberately would — see the colourway test below.
        $this->photo($session, 'BAG-2', 'other.jpg', ['position' => 1, 'selected' => true]);

        $position = function (PhotoEditItem $item) {
            $method = new \ReflectionMethod(\App\Jobs\PushEditedPhotoJob::class, 'galleryPosition');
            $method->setAccessible(true);

            return $method->invoke(new \App\Jobs\PushEditedPhotoJob($item->id), $item);
        };

        // Asked last-first, to prove the answer does not depend on when it is asked.
        $this->assertSame(3, $position($third));
        $this->assertSame(1, $position($first));
        $this->assertSame(2, $position($second));
    }

    /**
     * Two colourways of one product must not interleave in its gallery.
     *
     * This is the bug the SKU scope hid. A grey case and a black case are
     * different SKUs on the same Shopify product, and each was numbering its
     * photos from 1 — so Shopify got two images claiming position 1, two
     * claiming 2, and laid the gallery out grey, black, grey, black.
     *
     * Asserted as "every grey before every black" rather than on exact numbers,
     * because it is the ordering that matters and the numbers depend on how
     * many SKUs a run happens to hold.
     */
    public function test_two_colourways_of_one_product_do_not_interleave(): void
    {
        $session = $this->makeSession();

        $grey = [];
        $black = [];

        foreach (['front.jpg', 'side.jpg', 'back.jpg'] as $i => $name) {
            $grey[]  = $this->photo($session, 'LUG-GREY',  $name, ['position' => $i + 1, 'selected' => true]);
            $black[] = $this->photo($session, 'LUG-BLACK', $name, ['position' => $i + 1, 'selected' => true]);
        }

        $method = new \ReflectionMethod(\App\Jobs\PushEditedPhotoJob::class, 'galleryPosition');
        $method->setAccessible(true);

        $at = fn (PhotoEditItem $i) => $method->invoke(new \App\Jobs\PushEditedPhotoJob($i->id), $i);

        // LUG-BLACK sorts before LUG-GREY, so black takes the first block.
        $blackPositions = array_map($at, $black);
        $greyPositions  = array_map($at, $grey);

        $this->assertSame([1, 2, 3], $blackPositions, 'the first colourway is not a contiguous block');

        $this->assertGreaterThan(
            max($blackPositions),
            min($greyPositions),
            'the two colourways interleave — every photo of one colour must come before the other starts',
        );

        // And no two photos may claim the same slot, which is what Shopify
        // resolves by interleaving them.
        $all = array_merge($blackPositions, $greyPositions);
        $this->assertSame(count($all), count(array_unique($all)), 'two photos claim the same gallery position');
    }

    /** A photo left out of the push does not leave a hole in the gallery. */
    public function test_an_unselected_photo_does_not_reserve_a_position(): void
    {
        $session = $this->makeSession();

        $this->photo($session, 'BAG-1', 'front.jpg', ['position' => 1, 'selected' => false]);
        $kept  = $this->photo($session, 'BAG-1', 'side.jpg', ['position' => 2, 'selected' => true]);

        $method = new \ReflectionMethod(\App\Jobs\PushEditedPhotoJob::class, 'galleryPosition');
        $method->setAccessible(true);

        $this->assertSame(1, $method->invoke(new \App\Jobs\PushEditedPhotoJob($kept->id), $kept),
            'a skipped photo still counted towards the gallery order');
    }

    /**
     * The variant's picture is the first photo, not the last one uploaded.
     *
     * Every push used to set it, so a SKU with several photos ended up showing
     * whichever the queue happened to finish last — a suitcase with its handle
     * raised, in the case that found this, instead of the front shot the
     * operator had dragged into position one.
     */
    public function test_only_the_first_photo_becomes_the_variant_image(): void
    {
        $session = $this->makeSession();

        $first  = $this->photo($session, 'LUG-1', 'front.jpg',  ['position' => 1, 'edited_path' => 'a.jpg']);
        $second = $this->photo($session, 'LUG-1', 'handle.jpg', ['position' => 2, 'edited_path' => 'b.jpg']);

        $leads = new \ReflectionMethod(\App\Jobs\PushEditedPhotoJob::class, 'leadsItsSku');
        $leads->setAccessible(true);

        $job = new \App\Jobs\PushEditedPhotoJob($first->id);

        $this->assertTrue($leads->invoke($job, $first), 'the first photo should set the variant image');
        $this->assertFalse($leads->invoke($job, $second), 'a later photo must not overwrite it');
    }

    /**
     * The regression this file did not catch the first time.
     *
     * leadsItsSku was correct and tested, and the variant still ended up
     * showing the back view — because uploading an image with variant_ids in
     * the payload is itself an assignment, and the upload was being handed the
     * variant for every photo. Testing the helper in isolation could never see
     * that; this tests what the caller actually passes.
     */
    public function test_only_the_leading_photo_is_linked_to_the_variant_on_upload(): void
    {
        $session = $this->makeSession();

        $first  = $this->photo($session, 'BTM-1', '0_0.jpg', ['position' => 1, 'edited_path' => 'a.jpg']);
        $second = $this->photo($session, 'BTM-1', '1_0.jpg', ['position' => 2, 'edited_path' => 'b.jpg']);

        $link = new \ReflectionMethod(\App\Jobs\PushEditedPhotoJob::class, 'variantToLink');
        $link->setAccessible(true);

        $job = new \App\Jobs\PushEditedPhotoJob($first->id);

        $this->assertSame(
            'gid://shopify/ProductVariant/42',
            $link->invoke($job, $first, 'gid://shopify/ProductVariant/42'),
            'the first photo must be linked, or the variant gets no image at all',
        );

        $this->assertNull(
            $link->invoke($job, $second, 'gid://shopify/ProductVariant/42'),
            'a later photo was linked to the variant, so it will overwrite the first',
        );
    }

    /** A gallery-only push has no variant to link to in the first place. */
    public function test_a_style_code_push_links_nothing(): void
    {
        $session = $this->makeSession();
        $photo   = $this->photo($session, 'BTM-1', '0_0.jpg', ['position' => 1, 'edited_path' => 'a.jpg']);

        $link = new \ReflectionMethod(\App\Jobs\PushEditedPhotoJob::class, 'variantToLink');
        $link->setAccessible(true);

        $this->assertNull(
            $link->invoke(new \App\Jobs\PushEditedPhotoJob($photo->id), $photo, null),
        );
    }

    /** Reordering moves the variant image with it. */
    public function test_dragging_a_photo_first_makes_it_the_variant_image(): void
    {
        $session = $this->makeSession();

        $wasFirst = $this->photo($session, 'LUG-1', 'front.jpg',  ['position' => 1, 'edited_path' => 'a.jpg']);
        $promoted = $this->photo($session, 'LUG-1', 'handle.jpg', ['position' => 2, 'edited_path' => 'b.jpg']);

        $wasFirst->update(['position' => 2]);
        $promoted->update(['position' => 1]);

        $leads = new \ReflectionMethod(\App\Jobs\PushEditedPhotoJob::class, 'leadsItsSku');
        $leads->setAccessible(true);
        $job = new \App\Jobs\PushEditedPhotoJob($promoted->id);

        $this->assertTrue($leads->invoke($job, $promoted));
        $this->assertFalse($leads->invoke($job, $wasFirst));
    }

    /**
     * A generated image belongs in the gallery, never on the variant — it is
     * made from one of the real photographs and is not the product's own front
     * view.
     */
    public function test_a_generated_image_never_becomes_the_variant_image(): void
    {
        $session = $this->makeSession();

        $photo   = $this->photo($session, 'LUG-1', 'front.jpg', ['position' => 5, 'edited_path' => 'a.jpg']);
        $closeup = $this->photo($session, 'LUG-1', 'front-pendant.jpg', [
            'position' => 1, 'edited_path' => 'b.jpg', 'kind' => 'closeup',
        ]);

        $leads = new \ReflectionMethod(\App\Jobs\PushEditedPhotoJob::class, 'leadsItsSku');
        $leads->setAccessible(true);
        $job = new \App\Jobs\PushEditedPhotoJob($closeup->id);

        $this->assertFalse($leads->invoke($job, $closeup), 'a close-up must not take the variant image');
        $this->assertTrue($leads->invoke($job, $photo), 'the real photograph should, even sorted later');
    }

    /**
     * The name a photo reaches Shopify under is built from the SKU, not the
     * file the operator's photo came in on.
     *
     * Reported directly: a Shopify media file named after a supplier's own
     * filename plus Shopify's own random suffix on top — unrecognisable in
     * the media library, nothing about it saying which product it belonged
     * to beyond the alt text underneath it.
     */
    public function test_a_single_photo_skus_shopify_name_is_the_bare_sku(): void
    {
        $session = $this->makeSession();
        $photo   = $this->photo($session, 'BRM202BTM00037', 'IMG_9182.jpg', ['position' => 1]);

        $method = new \ReflectionMethod(\App\Jobs\PushEditedPhotoJob::class, 'shopifyFilename');
        $method->setAccessible(true);

        $this->assertSame(
            'BRM202BTM00037.jpg',
            $method->invoke(new \App\Jobs\PushEditedPhotoJob($photo->id), $photo, 'jpg'),
        );
    }

    /**
     * Several photos of the same SKU are numbered against each other, in the
     * same order the gallery itself uses — position, then filename as the
     * tie-break — so the number on the name and the position in the gallery
     * agree rather than being two different orderings that merely look
     * similar.
     */
    public function test_multiple_photos_of_one_sku_are_numbered_in_display_order(): void
    {
        $session = $this->makeSession();

        $second = $this->photo($session, 'BRM202BTM00037', 'b.jpg', ['position' => 2]);
        $first  = $this->photo($session, 'BRM202BTM00037', 'a.jpg', ['position' => 1]);

        $method = new \ReflectionMethod(\App\Jobs\PushEditedPhotoJob::class, 'shopifyFilename');
        $method->setAccessible(true);
        $job = new \App\Jobs\PushEditedPhotoJob($first->id);

        $this->assertSame('BRM202BTM00037-1.png', $method->invoke($job, $first, 'png'));
        $this->assertSame('BRM202BTM00037-2.png', $method->invoke($job, $second, 'png'));
    }

    /** A different SKU on the same run never numbers against this one. */
    public function test_a_sku_is_only_ever_numbered_against_its_own_photos(): void
    {
        $session = $this->makeSession();

        $mine   = $this->photo($session, 'SKU-A', 'a.jpg', ['position' => 1]);
        $theirs = $this->photo($session, 'SKU-B', 'b.jpg', ['position' => 1]);

        $method = new \ReflectionMethod(\App\Jobs\PushEditedPhotoJob::class, 'shopifyFilename');
        $method->setAccessible(true);

        $this->assertSame('SKU-A.jpg', $method->invoke(new \App\Jobs\PushEditedPhotoJob($mine->id), $mine, 'jpg'));
        $this->assertSame('SKU-B.jpg', $method->invoke(new \App\Jobs\PushEditedPhotoJob($theirs->id), $theirs, 'jpg'));
    }
}
