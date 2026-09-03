<?php

namespace Tests\Feature;

use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use App\Services\ImageProcessingService;
use App\Services\PhotoroomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A photo smaller than the canvas it is being framed onto.
 *
 * It gets enlarged either way — the only question is whether Photoroom's model
 * does it or a plain resize does. One 8 KB supplier ring went up to Shopify
 * visibly soft beside two 121 KB shots of the same range, and the setting that
 * would have fixed it was a checkbox, off by default, that nobody revisits per
 * photo.
 */
class PhotoEditorUpscaleTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string,string>|null What actually reached the API. */
    private ?array $sent = null;

    private function edit(int $sourceEdge, array $edits = []): void
    {
        $this->mock(\App\Services\OneDriveService::class, function ($mock) use ($sourceEdge) {
            $mock->shouldReceive('setUser')->andReturnSelf();
            $mock->shouldReceive('downloadFileById')->andReturn($this->jpeg($sourceEdge));
        });

        Http::fake([
            'image-api.photoroom.com/*' => function ($request) {
                $this->sent = [];

                foreach ($request->data() as $part) {
                    $this->sent[$part['name'] ?? ''] = $part['contents'] ?? '';

                    if (($part['name'] ?? '') === 'imageFile') {
                        return Http::response($part['contents'], 200, ['Content-Type' => 'image/jpeg']);
                    }
                }

                return Http::response('', 500);
            },
        ]);

        $session = PhotoEditSession::create([
            'user_id'       => User::factory()->create(['is_active' => true])->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => array_merge([
                'remove_background' => true,
                'width'             => 2000,
                'height'            => 2000,
            ], $edits),
        ]);

        $item = PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'filename'              => 'ring.jpg',
            'status'                => 'pending',
            'onedrive_drive_id'     => 'drive-1',
            'onedrive_item_id'      => 'item-1',
        ]);

        (new \App\Jobs\EditPhotoItemJob($item->id))->handle(
            app(\App\Services\OneDriveService::class),
            app(ImageProcessingService::class),
            app(PhotoroomService::class),
            app(\App\Services\GeminiService::class),
        );

        $this->assertSame('edited', $item->fresh()->status, $item->fresh()->error_message ?? '');
    }

    /**
     * The case that prompted this: a tiny supplier photo onto a 2000px canvas.
     *
     * ai.slow rather than ai.fast — the smallest inputs are both the worst
     * pictures and the only ones ai.slow will accept, so they get the better of
     * the two models. "ai.auto" is not a v2 value at all: it was in the code
     * unexercised, and the first run that used it failed the edit outright.
     */
    public function test_a_photo_smaller_than_the_canvas_is_upscaled(): void
    {
        $this->edit(sourceEdge: 500); // 250,000 px — inside ai.slow's ceiling

        $this->assertSame(PhotoroomService::UPSCALE_SLOW, $this->sent['upscale.mode'] ?? null);
    }

    /** Past ai.slow's quarter-megapixel ceiling, the faster model takes over. */
    public function test_a_larger_input_uses_the_mode_that_will_accept_it(): void
    {
        $this->edit(sourceEdge: 900); // 810,000 px — too big for slow, fine for fast

        $this->assertSame(PhotoroomService::UPSCALE_FAST, $this->sent['upscale.mode'] ?? null);
    }

    /**
     * Above the larger ceiling there is no upscaling to be had, so the request
     * is not spent finding that out — the photo is still smaller than the
     * canvas, and still goes through without it.
     */
    public function test_an_input_too_big_for_either_model_is_not_upscaled(): void
    {
        $this->edit(sourceEdge: 1500); // 2,250,000 px, yet under the 2000 canvas

        $this->assertArrayNotHasKey('upscale.mode', $this->sent ?? []);
        $this->assertSame('2000x2000', $this->sent['outputSize'] ?? null, 'the edit itself must still happen');
    }

    /**
     * Pinned to the canvas rather than left open. The model picks its own
     * factor otherwise, and a mixed catalogue comes out at mixed sizes.
     */
    public function test_the_upscale_target_is_the_canvas(): void
    {
        $this->edit(sourceEdge: 500);

        $this->assertSame('2000x2000', $this->sent['upscale.targetResolution'] ?? null);
    }

    /**
     * A photo that already has the pixels is left alone. Reconstructing detail
     * that is already there is work for nothing, and on jewellery it is worse
     * than nothing — prongs and pavé are what an upscaler reinvents.
     */
    public function test_a_photo_that_already_fills_the_canvas_is_left_alone(): void
    {
        $this->edit(sourceEdge: 2400);

        $this->assertArrayNotHasKey('upscale.mode', $this->sent ?? []);
    }

    /** An operator who ticked the box still gets a pinned target. */
    public function test_an_explicit_upscale_is_still_pinned_to_the_canvas(): void
    {
        $this->edit(sourceEdge: 900, edits: ['upscale' => true]);

        $this->assertSame(PhotoroomService::UPSCALE_FAST, $this->sent['upscale.mode'] ?? null);
        $this->assertSame('2000x2000', $this->sent['upscale.targetResolution'] ?? null);
    }

    /**
     * Ticking the box cannot make an oversized photo upscalable, and must not
     * cost the edit either — the refusal is predictable, so it is prevented
     * rather than recovered from.
     */
    public function test_an_explicit_upscale_on_an_oversized_photo_is_dropped_not_failed(): void
    {
        $this->edit(sourceEdge: 2400, edits: ['upscale' => true]);

        $this->assertArrayNotHasKey('upscale.mode', $this->sent ?? []);
        $this->assertSame('2000x2000', $this->sent['outputSize'] ?? null);
    }

    /** With no fixed canvas there is nothing to be too small for. */
    public function test_no_canvas_means_no_automatic_upscale(): void
    {
        $this->edit(sourceEdge: 500, edits: ['width' => null, 'height' => null]);

        $this->assertArrayNotHasKey('upscale.mode', $this->sent ?? []);
    }

    private function jpeg(int $edge): string
    {
        $im = imagecreatetruecolor($edge, $edge);
        imagefilledrectangle($im, 0, 0, $edge, $edge, imagecolorallocate($im, 210, 210, 215));
        ob_start();
        imagejpeg($im, null, 90);

        return ob_get_clean();
    }
}
