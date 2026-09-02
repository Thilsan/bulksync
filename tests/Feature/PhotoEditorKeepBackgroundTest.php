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
 * The third answer for a photo, between "cut it out" and "leave it alone".
 *
 * A SKU's shots are not one job. Two want the product cut out onto white; the
 * third is a detail or composed frame whose background is the point, and yet
 * it still has to land on the same canvas at the same size or the gallery
 * looks ragged. That photo goes to Photoroom for the framing with the erase
 * switched off — which costs a credit, unlike a photo sent up untouched.
 */
class PhotoEditorKeepBackgroundTest extends TestCase
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

    private function group(PhotoEditSession $session, string $sku): PhotoEditGroup
    {
        return PhotoEditGroup::create([
            'photo_edit_session_id' => $session->id,
            'sku'                   => $sku,
            'edits'                 => null,
        ]);
    }

    /** The case that asked for this: three photos of one SKU, treated three ways. */
    public function test_one_photo_of_a_sku_can_keep_its_background(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $cutout  = $this->photo($session, 'SKU-1', 'a.jpg');
        $framed  = $this->photo($session, 'SKU-1', 'b.jpg');
        $asIs    = $this->photo($session, 'SKU-1', 'c.jpg');
        $group   = $this->group($session, 'SKU-1');

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'groups' => [
                $group->id => [
                    'as_is'   => [$asIs->id],
                    'keep_bg' => [$framed->id],
                ],
            ],
        ])->assertRedirect(route('photo-editor.show', $session));

        $this->assertFalse($cutout->fresh()->keep_background);
        $this->assertTrue($framed->fresh()->keep_background);
        $this->assertFalse($framed->fresh()->skip_edit);
        $this->assertTrue($asIs->fresh()->skip_edit);
    }

    /**
     * It still costs a credit. Untouched photos are copied rather than edited;
     * a kept background is an edit like any other, so it goes to Photoroom.
     */
    public function test_a_kept_background_is_still_sent_to_photoroom(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $framed  = $this->photo($session, 'SKU-1', 'a.jpg');
        $asIs    = $this->photo($session, 'SKU-1', 'b.jpg');
        $group   = $this->group($session, 'SKU-1');

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'groups' => [
                $group->id => ['as_is' => [$asIs->id], 'keep_bg' => [$framed->id]],
            ],
        ]);

        Queue::assertPushed(EditPhotoItemJob::class, 1);
        Queue::assertPushed(CopyOriginalPhotoJob::class, 1);
    }

    /** Unticking has to be able to take a photo back out again. */
    public function test_clearing_the_tick_puts_the_photo_back_in_the_cutout_queue(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $photo   = $this->photo($session, 'SKU-1', 'a.jpg');
        $photo->update(['keep_background' => true]);
        $group = $this->group($session, 'SKU-1');

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'groups' => [$group->id => ['keep_bg' => []]],
        ]);

        $this->assertFalse($photo->fresh()->keep_background);
    }

    /**
     * Untouched is the stronger statement — the file is already right, so it
     * never reaches the API. A photo ticked as both must not end up being sent
     * for a reframe it was excluded from paying for.
     */
    public function test_untouched_wins_when_a_photo_is_marked_as_both(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $photo   = $this->photo($session, 'SKU-1', 'a.jpg');
        $group   = $this->group($session, 'SKU-1');

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'groups' => [
                $group->id => ['as_is' => [$photo->id], 'keep_bg' => [$photo->id]],
            ],
        ]);

        $this->assertTrue($photo->fresh()->skip_edit);
        $this->assertFalse($photo->fresh()->keep_background);
        Queue::assertPushed(CopyOriginalPhotoJob::class, 1);
        Queue::assertNotPushed(EditPhotoItemJob::class);
    }

    /**
     * The point of the whole feature, pinned at the API boundary: the erase is
     * off, and the framing the group asked for is still on. Asserted on the
     * multipart fields rather than on our own flags, because Photoroom's
     * behaviour is what the operator sees.
     */
    public function test_the_erase_is_switched_off_but_the_framing_is_not(): void
    {
        $sent = null;

        $this->mock(\App\Services\OneDriveService::class, function ($mock) {
            $mock->shouldReceive('setUser')->andReturnSelf();
            $mock->shouldReceive('downloadFileById')->andReturn($this->plainJpeg());
        });

        \Illuminate\Support\Facades\Http::fake([
            'image-api.photoroom.com/*' => function ($request) use (&$sent) {
                $sent = [];

                foreach ($request->data() as $part) {
                    $sent[$part['name'] ?? ''] = $part['contents'] ?? '';

                    if (($part['name'] ?? '') === 'imageFile') {
                        return \Illuminate\Support\Facades\Http::response($part['contents'], 200, ['Content-Type' => 'image/jpeg']);
                    }
                }

                return \Illuminate\Support\Facades\Http::response('', 500);
            },
        ]);

        $session = $this->makeSession();
        $session->update(['edits' => [
            'remove_background' => true,
            'background_mode'   => 'white',
            'padding'           => 0.10,
            'width'             => 2000,
            'height'            => 2000,
        ]]);

        $item = $this->photo($session, 'SKU-1', 'a.jpg');
        $item->update(['keep_background' => true]);

        (new \App\Jobs\EditPhotoItemJob($item->id))->handle(
            app(\App\Services\OneDriveService::class),
            app(\App\Services\ImageProcessingService::class),
            app(\App\Services\PhotoroomService::class),
            app(\App\Services\GeminiService::class),
        );

        $this->assertSame('edited', $item->fresh()->status, $item->fresh()->error_message ?? '');

        $this->assertSame('false', $sent['removeBackground'] ?? null, 'the background should have been kept');
        $this->assertSame('0.1', $sent['padding'] ?? null, 'the padding should still have been applied');
        $this->assertSame('2000x2000', $sent['outputSize'] ?? null, 'the canvas should still have been applied');
    }

    private function plainJpeg(int $w = 300, int $h = 300): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 200, 40, 40));
        ob_start();
        imagejpeg($im, null, 90);

        return ob_get_clean();
    }

    /** One SKU's answer must not reach another's photos. */
    public function test_the_flag_is_scoped_to_its_own_sku(): void
    {
        Queue::fake();

        $session = $this->makeSession();
        $mine    = $this->photo($session, 'SKU-1', 'a.jpg');
        $theirs  = $this->photo($session, 'SKU-2', 'b.jpg');
        $group   = $this->group($session, 'SKU-1');
        $this->group($session, 'SKU-2');

        $this->actingAs($session->user)->post(route('photo-editor.start', $session), [
            'groups' => [$group->id => ['keep_bg' => [$mine->id, $theirs->id]]],
        ]);

        $this->assertTrue($mine->fresh()->keep_background);
        $this->assertFalse($theirs->fresh()->keep_background);
    }
}
