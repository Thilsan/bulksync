<?php

namespace Tests\Feature;

use App\Jobs\EditPhotoItemJob;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use App\Services\ImageProcessingService;
use App\Services\OneDriveService;
use App\Services\PhotoroomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ironing runs at the photograph's own size, not at the catalogue canvas.
 *
 * A 480x560 shirt — 268,800 pixels — came back with "The image you are trying
 * to upscale is too big. Maximum allowed size: width * height = 1000000
 * pixels. Current image size: 1015104 pixels", on a request that asked for no
 * upscale at all. 1,015,104 is not the photograph: it is what Photoroom made
 * of it on the way to the 2000x2000 outputSize we asked for. Enlarging a small
 * photo to fill a big canvas is itself an upscale, and the ironing model
 * refuses to work on the result.
 *
 * The same picture goes through untouched with ironing off, which is what says
 * the canvas alone is not the problem.
 */
class PhotoEditorIroningCanvasTest extends TestCase
{
    use RefreshDatabase;

    private function photo(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 255, 255, 255));
        imagefilledrectangle($im, 40, 40, $w - 40, $h - 40, imagecolorallocate($im, 60, 90, 150));

        ob_start();
        imagejpeg($im);
        $out = (string) ob_get_clean();
        imagedestroy($im);

        return $out;
    }

    /** @return list<array<string,string>> the fields of every request sent */
    private function runWith(array $edits): array
    {
        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $session = PhotoEditSession::create([
            'user_id' => $user->id, 'name' => 'Run', 'onedrive_link' => 'https://example.com',
            'edits' => $edits, 'status' => 'processing', 'scan_status' => 'scanned',
        ]);

        $item = PhotoEditItem::create([
            'photo_edit_session_id' => $session->id, 'kind' => 'cutout',
            'filename' => 'a.jpg', 'sku_detected' => 'S', 'status' => 'pending',
            'onedrive_drive_id' => 'd', 'onedrive_item_id' => 'i',
        ]);

        $photo = $this->photo(480, 560);

        $oneDrive = \Mockery::mock(OneDriveService::class);
        $oneDrive->shouldReceive('setUser')->andReturnSelf();
        $oneDrive->shouldReceive('downloadFileById')->andReturn($photo);

        $sent = [];

        Http::fake(function ($request) use (&$sent, $photo) {
            $fields = [];

            foreach ($request->data() as $part) {
                $fields[$part['name'] ?? ''] = (string) ($part['contents'] ?? '');
            }

            $sent[] = $fields;

            return Http::response($photo, 200);
        });

        (new EditPhotoItemJob($item->id))->handle(
            $oneDrive,
            app(ImageProcessingService::class),
            app(PhotoroomService::class),
            app(\App\Services\GhostPrintTransplantService::class),
            app(\App\Services\GhostCompositeService::class),
        );

        return $sent;
    }

    public function test_an_ironed_request_does_not_carry_the_canvas(): void
    {
        $sent = $this->runWith([
            'remove_background' => true,
            'ironing'           => true,
            'framing_preset'    => 'men/shirts',
            'width'             => 2000,
            'height'            => 2000,
        ]);

        $this->assertNotEmpty($sent);
        $this->assertSame('ai.auto', $sent[0]['ironing.mode'] ?? null, 'ironing was not asked for');
        $this->assertSame('originalImage', $sent[0]['outputSize'] ?? null,
            'the canvas went out with the ironing, which is what the model refuses');
    }

    /** Without ironing the canvas goes as it always has. */
    public function test_an_unironed_request_still_asks_for_the_canvas(): void
    {
        $sent = $this->runWith([
            'remove_background' => true,
            'ironing'           => false,
            'framing_preset'    => 'men/shirts',
            'width'             => 2000,
            'height'            => 2000,
        ]);

        $this->assertSame('2000x2000', $sent[0]['outputSize'] ?? null);
    }

    /** And the finished photograph is still on the catalogue canvas. */
    public function test_the_ironed_photo_still_ends_on_the_canvas(): void
    {
        $this->runWith([
            'remove_background' => true,
            'ironing'           => true,
            'framing_preset'    => 'men/shirts',
            'width'             => 2000,
            'height'            => 2000,
        ]);

        $item = PhotoEditItem::sole();

        $this->assertSame('edited', $item->status, (string) $item->error_message);

        [$w, $h] = getimagesize(storage_path('app/' . $item->edited_path));

        $this->assertSame([2000, 2000], [$w, $h], 'the canvas was never applied locally');
    }
}
