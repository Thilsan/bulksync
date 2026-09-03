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
     * A photo smaller than its canvas is upscaled without being asked — but
     * only because the background is trimmed first, which is what makes the
     * upscale work at all.
     *
     * It was tried before the crop existed and taken back out, rightly: the two
     * largest supplier files were over the model's ceiling so nothing ran, and
     * on the one that did the product was still too small in frame for
     * Photoroom to enlarge. Both objections were about the white sheet around
     * the product, not the upscale.
     */
    public function test_a_photo_smaller_than_the_canvas_is_upscaled(): void
    {
        $this->edit(sourceEdge: 500);

        $this->assertSame(PhotoroomService::UPSCALE_SLOW, $this->sent['upscale.mode'] ?? null);
        $this->assertSame('2000x2000', $this->sent['outputSize'] ?? null);
    }

    /** A photo that already has the pixels is still left alone. */
    public function test_a_photo_that_already_fills_the_canvas_is_not_upscaled(): void
    {
        $this->edit(sourceEdge: 2400);

        $this->assertArrayNotHasKey('upscale.mode', $this->sent ?? []);
    }

    /**
     * When it is asked for, the smallest inputs get the better of the two
     * models — they are both the worst pictures and the only ones ai.slow will
     * accept. "ai.auto" is not a v2 value at all: it sat in the code
     * unexercised, and the first run that used it failed the edit outright.
     */
    public function test_an_asked_for_upscale_uses_the_quality_model_when_it_fits(): void
    {
        $this->edit(sourceEdge: 500, edits: ['upscale' => true]); // 250,000 px

        $this->assertSame(PhotoroomService::UPSCALE_SLOW, $this->sent['upscale.mode'] ?? null);
    }

    /** Past ai.slow's quarter-megapixel ceiling, the faster model takes over. */
    public function test_a_larger_input_uses_the_mode_that_will_accept_it(): void
    {
        $this->edit(sourceEdge: 900, edits: ['upscale' => true]); // 810,000 px

        $this->assertSame(PhotoroomService::UPSCALE_FAST, $this->sent['upscale.mode'] ?? null);
    }

    /**
     * Pinned to the canvas rather than left open. The model picks its own
     * factor otherwise, and a mixed catalogue comes out at mixed sizes.
     */
    public function test_the_upscale_target_is_the_canvas(): void
    {
        $this->edit(sourceEdge: 500, edits: ['upscale' => true]);

        $this->assertSame('2000x2000', $this->sent['upscale.targetResolution'] ?? null);
    }

    /**
     * Ticking the box cannot make an oversized photo upscalable, and must not
     * cost the edit either — the refusal is predictable, so it is prevented
     * rather than recovered from.
     */
    public function test_an_upscale_too_big_for_either_model_is_dropped_not_failed(): void
    {
        $this->edit(sourceEdge: 2400, edits: ['upscale' => true]); // 5,760,000 px

        $this->assertArrayNotHasKey('upscale.mode', $this->sent ?? []);
        $this->assertSame('2000x2000', $this->sent['outputSize'] ?? null, 'the edit itself must still happen');
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
