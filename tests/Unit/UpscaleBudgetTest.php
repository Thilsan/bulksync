<?php

namespace Tests\Unit;

use App\Services\ImageProcessingService;
use App\Services\PhotoroomService;
use Tests\TestCase;

/**
 * Photoroom's upscaler refuses Photoroom's own on-model output.
 *
 * Generation returns 848 x 1264 — 1,071,872 pixels — and ai.fast takes a
 * million. Seven per cent over, with no way to ask the generator for anything
 * smaller, because its sizes are named by aspect rather than by pixels. Until
 * this, every on-model shot fell back to un-upscaled and was filed at 848 px
 * wide, which is where the soft faces came from.
 *
 * The picture has to come back before it can be shrunk, so the upscale happens
 * in a second call against a copy trimmed under the ceiling.
 */
class UpscaleBudgetTest extends TestCase
{
    private function png(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 200, 150, 150));

        ob_start();
        imagepng($img);
        $out = (string) ob_get_clean();
        imagedestroy($img);

        return $out;
    }

    public function test_a_generated_on_model_shot_is_trimmed_under_the_ceiling(): void
    {
        $ceiling = PhotoroomService::UPSCALE_MAX_PIXELS[PhotoroomService::UPSCALE_FAST];

        $out = app(ImageProcessingService::class)
            ->scaleToPixelBudget($this->png(848, 1264), (int) ($ceiling * 0.98));

        [$w, $h] = getimagesizefromstring($out);

        $this->assertLessThanOrEqual($ceiling, $w * $h, 'still too big for ai.fast');
        $this->assertGreaterThan(0.9, ($w * $h) / $ceiling, 'shrunk further than it needed to be');
    }

    /** Aspect is kept, or the model would come back stretched. */
    public function test_the_shape_survives_the_trim(): void
    {
        $out = app(ImageProcessingService::class)
            ->scaleToPixelBudget($this->png(848, 1264), 980_000);

        [$w, $h] = getimagesizefromstring($out);

        $this->assertEqualsWithDelta(848 / 1264, $w / $h, 0.01);
    }

    /** An image already inside the budget is returned untouched, byte for byte. */
    public function test_something_already_small_enough_is_left_alone(): void
    {
        $png = $this->png(600, 800);

        $this->assertSame($png, app(ImageProcessingService::class)->scaleToPixelBudget($png, 980_000));
    }

    /** Rubbish in, rubbish out — but not an exception. */
    public function test_an_unreadable_file_comes_back_untouched(): void
    {
        $this->assertSame('nonsense', app(ImageProcessingService::class)->scaleToPixelBudget('nonsense', 980_000));
    }

    /*
     * And the upscaled result is brought back to the catalogue edge.
     *
     * Photoroom's upscaler scales by a factor rather than to a target: asked
     * for 2000 from an 810 x 1207 generation it returns 3240 x 4828, fifteen
     * megapixels. The one-megabyte ceiling that follows is held by lowering
     * JPEG quality and never by scaling, so without this the upscale would be
     * undone by its own compression — a worse face than the 848 px version it
     * replaced.
     */
    public function test_the_upscaled_shot_comes_back_to_the_catalogue_edge(): void
    {
        $out = app(ImageProcessingService::class)->capLongEdge($this->png(3240, 4828), 2000);

        [$w, $h] = getimagesizefromstring($out);

        $this->assertSame(2000, max($w, $h));
        $this->assertEqualsWithDelta(3240 / 4828, $w / $h, 0.01, 'the model came back stretched');
    }

    /** Never enlarges: a generation that was refused the upscale stays as it is. */
    public function test_a_small_shot_is_not_blown_up_to_fill_the_edge(): void
    {
        $png = $this->png(848, 1264);

        $this->assertSame($png, app(ImageProcessingService::class)->capLongEdge($png, 2000));
    }
}
