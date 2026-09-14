<?php

namespace Tests\Feature;

use App\Jobs\PushEditedPhotoJob;
use App\Jobs\SettleGalleryOrderJob;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The gallery comes out in the order the operator chose.
 *
 * Naming a position on the upload was not enough and could not have been.
 * Shopify clamps a position to the gallery as it stands at that instant, so
 * asking for position 7 of an eventual 27 lands the image at the end while only
 * six are up — and with several workers uploading at once, which six are up is
 * decided by whichever job finished first. A luggage product came back with 27
 * photos in no order at all.
 *
 * So the order is asserted once, at the end, against a complete gallery. Two
 * things have to hold for that to work, and both are tested here: the sequence
 * has to be right, and the moment has to be right — too early and the photos
 * still in the queue land after the ones just sorted.
 */
class PhotoEditorGalleryOrderTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(User $user): PhotoEditSession
    {
        return PhotoEditSession::create([
            'user_id'       => $user->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => [],
            'status'        => 'reviewing',
        ]);
    }

    private function item(PhotoEditSession $s, array $attributes): PhotoEditItem
    {
        return PhotoEditItem::create(array_merge([
            'photo_edit_session_id' => $s->id,
            'kind'                  => 'cutout',
            'status'                => 'pushed',
            'selected'              => true,
        ], $attributes));
    }

    /**
     * Every photo of one colourway before the next begins, and inside each the
     * order the tiles were dragged into.
     */
    public function test_colourways_are_kept_together_and_in_the_chosen_order(): void
    {
        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $session = $this->makeSession($user);

        // Created deliberately interleaved and out of order, because the row
        // order in the table is exactly what must not decide the gallery.
        $this->item($session, ['filename' => 'b2.jpg', 'sku_detected' => 'LUG-BLACK', 'position' => 2, 'product_id' => '900', 'shopify_image_id' => 'img-b2']);
        $this->item($session, ['filename' => 'g3.jpg', 'sku_detected' => 'LUG-GREY',  'position' => 3, 'product_id' => '900', 'shopify_image_id' => 'img-g3']);
        $this->item($session, ['filename' => 'g1.jpg', 'sku_detected' => 'LUG-GREY',  'position' => 1, 'product_id' => '900', 'shopify_image_id' => 'img-g1']);
        $this->item($session, ['filename' => 'b1.jpg', 'sku_detected' => 'LUG-BLACK', 'position' => 1, 'product_id' => '900', 'shopify_image_id' => 'img-b1']);
        $this->item($session, ['filename' => 'g2.jpg', 'sku_detected' => 'LUG-GREY',  'position' => 2, 'product_id' => '900', 'shopify_image_id' => 'img-g2']);

        $this->assertSame(
            ['img-b1', 'img-b2', 'img-g1', 'img-g2', 'img-g3'],
            SettleGalleryOrderJob::desiredOrder($session->id, '900'),
            'the gallery order does not group the colourways',
        );
    }

    /** A second product in the same run is ordered on its own. */
    public function test_another_product_in_the_run_is_not_mixed_in(): void
    {
        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $session = $this->makeSession($user);

        $this->item($session, ['filename' => 'a.jpg', 'sku_detected' => 'BAG-1', 'position' => 1, 'product_id' => '900', 'shopify_image_id' => 'img-a']);
        $this->item($session, ['filename' => 'b.jpg', 'sku_detected' => 'BAG-2', 'position' => 1, 'product_id' => '901', 'shopify_image_id' => 'img-b']);

        $this->assertSame(['img-a'], SettleGalleryOrderJob::desiredOrder($session->id, '900'));
        $this->assertSame(['img-b'], SettleGalleryOrderJob::desiredOrder($session->id, '901'));
    }

    /** A photo that never reached Shopify has no place to be put. */
    public function test_a_photo_with_no_image_on_shopify_is_left_out(): void
    {
        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $session = $this->makeSession($user);

        $this->item($session, ['filename' => 'a.jpg', 'sku_detected' => 'S', 'position' => 1, 'product_id' => '900', 'shopify_image_id' => 'img-a']);
        $this->item($session, ['filename' => 'b.jpg', 'sku_detected' => 'S', 'position' => 2, 'product_id' => '900', 'shopify_image_id' => null, 'status' => 'failed']);

        $this->assertSame(['img-a'], SettleGalleryOrderJob::desiredOrder($session->id, '900'));
    }

    /**
     * The timing half. Reordering while photos are still queued would sort the
     * ones that arrived and leave the rest to land after them — the same mess,
     * arrived at more expensively.
     */
    public function test_nothing_is_reordered_while_photos_are_still_on_their_way(): void
    {
        Queue::fake();

        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $session = $this->makeSession($user);

        $done    = $this->item($session, ['filename' => 'a.jpg', 'sku_detected' => 'S', 'position' => 1, 'product_id' => '900', 'shopify_image_id' => 'img-a']);
        $waiting = $this->item($session, ['filename' => 'b.jpg', 'sku_detected' => 'S', 'position' => 2, 'status' => 'pushing']);

        $this->settle($done);

        Queue::assertNotPushed(SettleGalleryOrderJob::class);

        // ...and the moment the last one lands, it is ordered.
        $waiting->update(['status' => 'pushed', 'product_id' => '900', 'shopify_image_id' => 'img-b']);

        $this->settle($waiting);

        Queue::assertPushed(SettleGalleryOrderJob::class, 1);
    }

    /**
     * A worker killed mid-upload leaves a row marked in flight for ever.
     * Waiting on it would mean the gallery is never put in order at all.
     */
    public function test_a_push_abandoned_long_ago_does_not_block_it_for_ever(): void
    {
        Queue::fake();

        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $session = $this->makeSession($user);

        $done = $this->item($session, ['filename' => 'a.jpg', 'sku_detected' => 'S', 'position' => 1, 'product_id' => '900', 'shopify_image_id' => 'img-a']);

        $stuck = $this->item($session, ['filename' => 'b.jpg', 'sku_detected' => 'S', 'position' => 2, 'status' => 'pushing']);
        $stuck->forceFill(['updated_at' => now()->subHours(3)])->saveQuietly();

        $this->settle($done);

        Queue::assertPushed(SettleGalleryOrderJob::class, 1);
    }

    /** Queuing a push marks it in flight, which is how the last one knows it is last. */
    public function test_queuing_a_push_marks_the_photo_in_flight(): void
    {
        Queue::fake();

        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $session = $this->makeSession($user);

        $item = $this->item($session, [
            'filename'     => 'a.jpg',
            'sku_detected' => 'S',
            'position'     => 1,
            'status'       => 'edited',
            'edited_path'  => 'photo-edits/1-after.png',
        ]);

        $this->actingAs($user)
            ->postJson(route('photo-editor.push', $session), ['item_ids' => [$item->id]])
            ->assertOk();

        $this->assertSame('pushing', $item->fresh()->status, 'a queued push is indistinguishable from one nobody asked for');
    }

    /** Runs the push job's own completion check for one item. */
    private function settle(PhotoEditItem $item): void
    {
        $method = new \ReflectionMethod(PushEditedPhotoJob::class, 'settleGalleryOrder');
        $method->setAccessible(true);
        $method->invoke(new PushEditedPhotoJob($item->id), $item->photo_edit_session_id);
    }
}
