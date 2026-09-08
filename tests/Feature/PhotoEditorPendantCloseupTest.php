<?php

namespace Tests\Feature;

use App\Jobs\EditPhotoItemJob;
use App\Models\PhotoEditGroup;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use App\Services\ImageProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A second photograph of the pendant, on the same SKU.
 *
 * A necklace listing is mostly chain. The thing being bought hangs at the
 * bottom and, framed to the catalogue standard, occupies a fiftieth of the
 * picture — so it gets its own image, pushed and downloaded like any other.
 */
class PhotoEditorPendantCloseupTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(User $user): PhotoEditSession
    {
        return PhotoEditSession::create([
            'user_id'       => $user->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => ['remove_background' => true],
            'status'        => 'configuring',
            'scan_status'   => 'scanned',
        ]);
    }

    private function photo(PhotoEditSession $session, string $sku): PhotoEditItem
    {
        return PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'kind'                  => 'cutout',
            'filename'              => $sku . '.jpg',
            'sku_detected'          => $sku,
            'status'                => 'pending',
            'onedrive_drive_id'     => 'drive-1',
            'onedrive_item_id'      => 'item-' . $sku,
        ]);
    }

    public function test_ticking_it_adds_a_second_item_on_the_same_sku(): void
    {
        Queue::fake();

        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $session = $this->makeSession($user);
        $photo   = $this->photo($session, 'CK1285');
        $group   = PhotoEditGroup::create(['photo_edit_session_id' => $session->id, 'sku' => 'CK1285', 'edits' => null]);

        $this->actingAs($user)->post(route('photo-editor.start', $session), [
            'groups' => [$group->id => ['pendant_closeup' => '1']],
        ])->assertRedirect(route('photo-editor.show', $session));

        $closeup = PhotoEditItem::where('photo_edit_session_id', $session->id)->where('kind', 'closeup')->first();

        $this->assertNotNull($closeup, 'no close-up item was created');
        $this->assertSame('CK1285', $closeup->sku_detected, 'the close-up must carry the same SKU');
        $this->assertSame($photo->id, $closeup->source_item_id);

        // It reads the same original the main image does, so the pendant reaches
        // the upscaler as a small crop rather than a blown-up finished frame.
        $this->assertSame($photo->onedrive_item_id, $closeup->onedrive_item_id);

        Queue::assertPushed(EditPhotoItemJob::class, 2);
    }

    /** Not ticked, not charged. */
    public function test_leaving_it_alone_adds_nothing(): void
    {
        Queue::fake();

        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $session = $this->makeSession($user);
        $this->photo($session, 'CK1285');
        $group = PhotoEditGroup::create(['photo_edit_session_id' => $session->id, 'sku' => 'CK1285', 'edits' => null]);

        $this->actingAs($user)->post(route('photo-editor.start', $session), [
            'groups' => [$group->id => []],
        ]);

        $this->assertSame(0, PhotoEditItem::where('kind', 'closeup')->count());
        Queue::assertPushed(EditPhotoItemJob::class, 1);
    }

    /** The tick survives, so reopening the screen shows what was chosen. */
    public function test_the_choice_is_remembered(): void
    {
        Queue::fake();

        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $session = $this->makeSession($user);
        $this->photo($session, 'CK1285');
        $group = PhotoEditGroup::create(['photo_edit_session_id' => $session->id, 'sku' => 'CK1285', 'edits' => null]);

        $this->actingAs($user)->post(route('photo-editor.start', $session), [
            'groups' => [$group->id => ['pendant_closeup' => '1']],
        ]);

        $this->assertTrue($group->fresh()->pendant_closeup);
    }

    /**
     * A pendant is found by weight, not by shape: a chain lays down a steady
     * few pixels a row and a pendant several hundred.
     */
    public function test_a_pendant_is_found_at_the_bottom_of_a_chain(): void
    {
        $cropped = app(ImageProcessingService::class)->cropToPendant($this->necklace(withPendant: true));

        $this->assertNotNull($cropped, 'the pendant was not found');

        [$w, $h] = array_slice(getimagesizefromstring($cropped), 0, 2);

        $this->assertSame($w, $h, 'a close-up should be square');
        $this->assertLessThan(1000, $w, 'the crop is the whole picture rather than the pendant');
    }

    /**
     * A plain chain has nothing to photograph close up, and saying so is better
     * than spending a credit on a picture of uniform links.
     */
    public function test_a_chain_with_no_pendant_is_declined(): void
    {
        $this->assertNull(app(ImageProcessingService::class)->cropToPendant($this->necklace(withPendant: false)));
    }

    /** A thin vertical chain on white, optionally with a blob at the bottom. */
    private function necklace(bool $withPendant, int $pendantSize = 220): string
    {
        $im = imagecreatetruecolor(1000, 1000);
        imagefilledrectangle($im, 0, 0, 1000, 1000, imagecolorallocate($im, 255, 255, 255));
        $metal = imagecolorallocate($im, 120, 120, 130);

        for ($y = 0; $y < 850; $y++) {
            imagefilledrectangle($im, 495, $y, 505, $y, $metal);
        }

        if ($withPendant) {
            imagefilledellipse($im, 500, 890, $pendantSize, $pendantSize, $metal);
        }

        ob_start();
        imagepng($im);

        return ob_get_clean();
    }
}
