<?php

namespace Tests\Feature;

use App\Jobs\CopyOriginalPhotoJob;
use App\Jobs\EditPhotoItemJob;
use App\Models\PhotoEditGroup;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Photos that go to Shopify untouched.
 *
 * A folder is rarely all one thing: six shots need a cutout and four are
 * already right. Sending those four through Photoroom costs four credits to
 * change nothing, so they take a different route — and the property that
 * matters most is that the route is genuinely different. A photo marked "as is"
 * that still reached the API would cost the money this exists to save, and
 * nothing on screen would show it had.
 */
class PhotoEditorAsIsTest extends TestCase
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

    private function photo(PhotoEditSession $session, string $sku, string $filename): PhotoEditItem
    {
        return PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'kind'                  => 'cutout',
            'filename'              => $filename,
            'sku_detected'          => $sku,
            'status'                => 'pending',
            'onedrive_drive_id'     => 'drive-1',
            'onedrive_item_id'      => 'item-' . $filename,
        ]);
    }

    public function test_only_the_photos_wanting_an_edit_reach_photoroom(): void
    {
        Queue::fake();

        $session = $this->makeSession();

        $edit  = collect(['a.jpg', 'b.jpg', 'c.jpg', 'd.jpg', 'e.jpg', 'f.jpg'])
            ->map(fn ($f) => $this->photo($session, 'BAG-1', $f));
        $as_is = collect(['g.jpg', 'h.jpg', 'i.jpg', 'j.jpg'])
            ->map(fn ($f) => $this->photo($session, 'BAG-1', $f));

        $group = PhotoEditGroup::create([
            'photo_edit_session_id' => $session->id,
            'sku'                   => 'BAG-1',
            'edits'                 => null,
        ]);

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'groups' => [$group->id => [
                'lifestyle_count' => 0,
                'as_is'           => $as_is->pluck('id')->all(),
            ]],
        ])->assertRedirect(route('photo-editor.show', $session));

        // Six billed, four free — which is the entire point.
        Queue::assertPushed(EditPhotoItemJob::class, 6);
        Queue::assertPushed(CopyOriginalPhotoJob::class, 4);

        $this->assertTrue($as_is->every(fn ($i) => $i->fresh()->skip_edit));
        $this->assertTrue($edit->every(fn ($i) => !$i->fresh()->skip_edit));
    }

    /** The quoted bill has to match the one that will actually be spent. */
    public function test_untouched_photos_are_not_counted_as_credits(): void
    {
        $session = $this->makeSession();

        foreach (['a.jpg', 'b.jpg', 'c.jpg', 'd.jpg'] as $f) {
            $this->photo($session, 'BAG-1', $f);
        }

        $group = PhotoEditGroup::create([
            'photo_edit_session_id' => $session->id,
            'sku'                   => 'BAG-1',
            'edits'                 => null,
            'lifestyle_count'       => 0,
        ]);

        $this->assertSame(4, $group->creditCost());

        PhotoEditItem::where('photo_edit_session_id', $session->id)
            ->whereIn('filename', ['c.jpg', 'd.jpg'])
            ->update(['skip_edit' => true]);

        $this->assertSame(2, $group->fresh()->creditCost());
    }

    /**
     * Unticking a photo has to put it back in the edit queue.
     *
     * The form posts the photos that are marked, never the ones that are not,
     * so a save that only added flags could set them but never clear them —
     * and a photo unticked on screen would still quietly skip the edit.
     */
    public function test_unmarking_a_photo_sends_it_back_to_be_edited(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $photo   = $this->photo($session, 'BAG-1', 'a.jpg');
        $photo->update(['skip_edit' => true]);

        $group = PhotoEditGroup::create([
            'photo_edit_session_id' => $session->id,
            'sku'                   => 'BAG-1',
            'edits'                 => null,
        ]);

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'groups' => [$group->id => ['lifestyle_count' => 0]],   // nothing marked
        ]);

        $this->assertFalse($photo->fresh()->skip_edit);
        Queue::assertPushed(EditPhotoItemJob::class, 1);
        Queue::assertNotPushed(CopyOriginalPhotoJob::class);
    }

    /** One SKU's choices must not reach into another's. */
    public function test_marking_one_sku_leaves_the_others_alone(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $mine    = $this->photo($session, 'BAG-1', 'a.jpg');
        $theirs  = $this->photo($session, 'BAG-2', 'b.jpg');
        $theirs->update(['skip_edit' => true]);

        $bag1 = PhotoEditGroup::create(['photo_edit_session_id' => $session->id, 'sku' => 'BAG-1', 'edits' => null]);
        $bag2 = PhotoEditGroup::create(['photo_edit_session_id' => $session->id, 'sku' => 'BAG-2', 'edits' => null]);

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'groups' => [
                $bag1->id => ['lifestyle_count' => 0, 'as_is' => [$mine->id]],
                $bag2->id => ['lifestyle_count' => 0, 'as_is' => [$theirs->id]],
            ],
        ]);

        $this->assertTrue($mine->fresh()->skip_edit);
        $this->assertTrue($theirs->fresh()->skip_edit, "BAG-2's own choice was cleared by BAG-1's save");
    }
}
