<?php

namespace Tests\Feature;

use App\Models\PhotoEditGroup;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use App\Services\ImageProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Cutting a raised trolley handle off a suitcase.
 *
 * A case shot with the handle extended is half handle — on the sample it ran
 * from 10% to 47% down the frame — so framing the product to the catalogue
 * standard sized the case against a chrome pole.
 */
class PhotoEditorRemoveHandleTest extends TestCase
{
    use RefreshDatabase;

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

    private function makeSession(): PhotoEditSession
    {
        return PhotoEditSession::create([
            'user_id'       => User::factory()->create(['is_active' => true, 'perm_photo_editor' => true])->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => ['remove_background' => true],
            'status'        => 'configuring',
            'scan_status'   => 'scanned',
        ]);
    }

    /** One photo of a SKU can lose its handle while another keeps it. */
    public function test_the_tick_is_saved_per_photo(): void
    {
        Queue::fake();

        $session   = $this->makeSession();
        $handleUp  = $this->photo($session, 'MOS-1');
        $handleDown = $this->photo($session, 'MOS-1');
        $group     = PhotoEditGroup::create(['photo_edit_session_id' => $session->id, 'sku' => 'MOS-1', 'edits' => null]);

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'groups' => [$group->id => ['no_handle' => [$handleUp->id]]],
        ])->assertRedirect(route('photo-editor.show', $session));

        $this->assertTrue($handleUp->fresh()->remove_handle);
        $this->assertFalse($handleDown->fresh()->remove_handle, 'the other shot of the same SKU was changed too');
    }

    /** Unticking has to put the handle back. */
    public function test_clearing_the_tick_restores_the_handle(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $photo   = $this->photo($session, 'MOS-1');
        $photo->update(['remove_handle' => true]);
        $group = PhotoEditGroup::create(['photo_edit_session_id' => $session->id, 'sku' => 'MOS-1', 'edits' => null]);

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'groups' => [$group->id => ['no_handle' => []]],
        ]);

        $this->assertFalse($photo->fresh()->remove_handle);
    }

    /**
     * The measurement it rests on: a handle is thin and the case is wide, by a
     * factor of ten on a real photograph.
     */
    public function test_a_thin_handle_above_a_wide_case_is_cropped_away(): void
    {
        $cropped = app(ImageProcessingService::class)->cropAboveBody($this->caseWithHandle(handle: true));

        $this->assertNotNull($cropped, 'the handle was not found');

        [, $h] = array_slice(getimagesizefromstring($cropped), 0, 2);

        $this->assertLessThan(1000, $h, 'nothing was cropped');
        $this->assertGreaterThan(400, $h, 'the case itself was cropped into');
    }

    /**
     * A case photographed with the handle down has nothing above the body, and
     * cropping anyway would take its own corner off.
     */
    public function test_a_case_with_no_handle_is_left_alone(): void
    {
        $this->assertNull(app(ImageProcessingService::class)->cropAboveBody($this->caseWithHandle(handle: false)));
    }

    /** A wide block, optionally with a narrow pole standing on it. */
    private function caseWithHandle(bool $handle): string
    {
        $im = imagecreatetruecolor(1000, 1000);
        imagefilledrectangle($im, 0, 0, 1000, 1000, imagecolorallocate($im, 255, 255, 255));
        $dark = imagecolorallocate($im, 40, 40, 45);

        imagefilledrectangle($im, 200, 500, 800, 950, $dark);   // the case

        if ($handle) {
            imagefilledrectangle($im, 470, 80, 530, 500, $dark); // the pole
        }

        ob_start();
        imagepng($im);

        return ob_get_clean();
    }
}
