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

        // A different SKU must not shift these along.
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
}
