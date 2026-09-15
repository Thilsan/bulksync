<?php

namespace Tests\Feature;

use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\GhostPrintTransplantService;
use App\Services\ImageProcessingService;
use App\Services\PhotoroomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A Ghost Mannequin edit publishes the photograph, or it publishes nothing.
 *
 * Photoroom's redraw is capped at 1K below an Enterprise plan, and at that size
 * it does not merely soften a garment — it reinvents one. An Aigner monogram
 * came back as a dotted grid; a sequinned poncho came back as a cropped top
 * with its long panel simply gone.
 *
 * This file used to test the print transplant, which put the photograph's own
 * artwork back over the redraw's shape. That is no longer what the job does,
 * and the reason is the poncho: the transplant rescues the fabric, not the
 * shape, and it was the shape that was wrong. The job now checks whether the
 * redraw agrees with the photograph at all, keeps the photograph and borrows
 * only the hole the stand left when it does, and throws the redraw away when it
 * does not. The transplant service and its command survive, with their own unit
 * tests, for work where the geometry is the thing being bought.
 *
 * So what is asserted here is the refusal. A redraw that cannot be shown to
 * match the photograph must not reach a product page, however good it looks.
 */
class PhotoEditorGhostPrintTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Run one item through the job with Ghost Mannequin asked for.
     *
     * Photoroom is faked to behave the way it really does: it ignores the
     * photograph it was sent and answers with a 1024 square of its own, print
     * and all.
     */
    private function edit(string $photo, string $redraw): PhotoEditItem
    {
        $this->mock(\App\Services\OneDriveService::class, function ($mock) use ($photo) {
            $mock->shouldReceive('setUser')->andReturnSelf();
            $mock->shouldReceive('downloadFileById')->andReturn($photo);
        });

        // A front view with a stand in shot is what routes an item to Ghost
        // Mannequin at all; see EditPhotoItemJob.
        $gemini = $this->mock(GeminiService::class, function ($mock) {
            $mock->shouldReceive('classifyGarmentView')->andReturn([
                'view_type'         => 'front',
                'mannequin_visible' => true,
            ]);
        });

        Http::fake([
            'image-api.photoroom.com/*' => fn () => Http::response(
                $redraw,
                200,
                ['Content-Type' => 'image/png'],
            ),
        ]);

        config(['services.photoroom.api_key' => 'live_test_key']);

        $session = PhotoEditSession::create([
            'user_id'       => User::factory()->create(['is_active' => true])->id,
            'name'          => 'Ghost run',
            'onedrive_link' => 'https://example.com',
            'edits'         => [
                'remove_background' => true,
                'ghost_mannequin'   => true,
                'width'             => 2000,
                'height'            => 2000,
            ],
        ]);

        $item = PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'filename'              => 'tee.jpg',
            'status'                => 'pending',
            'onedrive_drive_id'     => 'drive-1',
            'onedrive_item_id'      => 'item-1',
        ]);

        (new \App\Jobs\EditPhotoItemJob($item->id))->handle(
            app(\App\Services\OneDriveService::class),
            app(ImageProcessingService::class),
            app(PhotoroomService::class),
            $gemini,
            app(GhostPrintTransplantService::class),
            app(\App\Services\GhostCompositeService::class),
        );

        return $item->fresh();
    }

    /**
     * The redraw here is Photoroom's own square, unrelated to the photograph it
     * was sent — which is how the real thing behaves and is exactly the case
     * that must not be published.
     */
    public function test_a_redraw_that_does_not_match_the_photograph_is_not_published(): void
    {
        $item = $this->edit($this->photo(withPrint: true), $this->redraw(withPrint: true));

        $this->assertSame('edited', $item->status);

        $this->assertSame(
            'cutout_unnamed',
            $item->apparel_mode_applied,
            'an unverified redraw was published',
        );

        // And the operator is told why, rather than being left to notice that a
        // garment came back a different shape.
        $this->assertStringContainsString(
            'without altering the garment',
            (string) $item->error_message,
            'nothing explained why the stand is still in the picture',
        );
    }

    /**
     * The redraw's own bytes are thrown away, not kept as a fallback.
     *
     * A picture of a garment that was not photographed has nothing to salvage
     * in it, and the tempting half-measure — keep the redraw, note the problem —
     * is the one that put a cropped top on a product page.
     */
    public function test_the_redraws_pixels_do_not_survive_the_refusal(): void
    {
        $redraw = $this->redraw(withPrint: false);

        $item = $this->edit($this->photo(withPrint: false), $redraw);

        $this->assertSame('edited', $item->status);

        $written = (string) file_get_contents(storage_path('app/' . $item->edited_path));

        $this->assertNotSame($redraw, $written, 'the refused redraw was written out anyway');
    }

    // ── Fixtures ───────────────────────────────────────────────────────────

    /** The photograph: cream garment, dark stand, and optionally a fine print. */
    private function photo(bool $withPrint): string
    {
        $img = $this->canvas(2400, 3000, [239, 240, 244]);

        $this->box($img, [500, 600, 1900, 2600], [238, 238, 240]);
        $this->box($img, [1050, 380, 1350, 640], [64, 48, 40]);

        if ($withPrint) {
            foreach ([[1250, 1500], [1560, 1700]] as [$y0, $y1]) {
                for ($x = 900; $x <= 1500; $x += 24) {
                    $this->box($img, [$x, $y0, $x + 9, $y1], [26, 26, 30]);
                }
            }
        }

        return $this->png($img);
    }

    /** Photoroom's answer: 1024 square, stand gone, print forged coarsely. */
    private function redraw(bool $withPrint): string
    {
        $img = $this->canvas(1024, 1024, [239, 240, 244]);

        $this->box($img, [260, 180, 780, 900], [238, 238, 240]);

        if ($withPrint) {
            for ($x = 380; $x <= 620; $x += 40) {
                for ($y = 430; $y <= 600; $y += 40) {
                    $this->box($img, [$x, $y, $x + 19, $y + 19], [26, 26, 30]);
                }
            }
        }

        return $this->png($img);
    }

    private function canvas(int $w, int $h, array $rgb): \GdImage
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, imagecolorallocate($img, ...$rgb));

        return $img;
    }

    private function box(\GdImage $img, array $r, array $rgb): void
    {
        imagefilledrectangle(
            $img,
            $r[0],
            $r[1],
            min($r[2], imagesx($img) - 1),
            min($r[3], imagesy($img) - 1),
            imagecolorallocate($img, ...$rgb),
        );
    }

    private function png(\GdImage $img): string
    {
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    /** The most light/dark changes on any row through the middle of the frame. */
    private function crossings(string $bytes): int
    {
        $img  = @imagecreatefromstring($bytes);
        $w    = imagesx($img);
        $h    = imagesy($img);
        $best = 0;

        for ($y = (int) (0.35 * $h); $y < (int) (0.65 * $h); $y += 4) {
            $n    = 0;
            $dark = null;

            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($img, $x, $y);

                $isDark = (0.299 * (($c >> 16) & 0xFF)
                    + 0.587 * (($c >> 8) & 0xFF)
                    + 0.114 * ($c & 0xFF)) < 140;

                if ($dark !== null && $isDark !== $dark) {
                    $n++;
                }

                $dark = $isDark;
            }

            $best = max($best, $n);
        }

        imagedestroy($img);

        return $best;
    }
}
