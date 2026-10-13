<?php

namespace Tests\Feature;

use App\Services\ImageProcessingService;
use App\Services\PhotoroomService;
use Tests\TestCase;

/**
 * Samsonite's own canvas, which is not Blue Salon's.
 *
 * Every other preset frames for bluesalon.com: a 2000 square with padding
 * deciding how large the product sits. Samsonite publishes at 1600x2400 and
 * sizes its own cases — its supplier files arrive with the case already the
 * size the brand wants, and the only thing inconsistent is how high it sits.
 *
 * So this preset does one thing and leaves the rest alone. Fitting would
 * resize pictures that need no resizing, and a shelf of cases all scaled to
 * the same fill is not what that catalogue looks like.
 */
class PhotoEditorSamsoniteTest extends TestCase
{
    /** A product somewhere in a frame, for placing. */
    private function png(int $w, int $h, int $boxX, int $boxY, int $boxW, int $boxH): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 255, 255, 255));
        imagefilledrectangle($img, $boxX, $boxY, $boxX + $boxW, $boxY + $boxH, imagecolorallocate($img, 20, 40, 90));

        ob_start();
        imagepng($img);
        $out = (string) ob_get_clean();
        imagedestroy($img);

        return $out;
    }

    /** @return array{0:int,1:int,2:int,3:int} minX, minY, maxX, maxY */
    private function box(string $png): array
    {
        $im = imagecreatefromstring($png);
        $w  = imagesx($im);
        $h  = imagesy($im);
        $minX = $w; $minY = $h; $maxX = -1; $maxY = -1;

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($im, $x, $y);

                if ((($c >> 16) & 0xFF) < 250 || (($c >> 8) & 0xFF) < 250 || ($c & 0xFF) < 250) {
                    $minX = min($minX, $x); $maxX = max($maxX, $x);
                    $minY = min($minY, $y); $maxY = max($maxY, $y);
                }
            }
        }

        imagedestroy($im);

        return [$minX, $minY, $maxX, $maxY];
    }

    public function test_the_preset_is_samsonites_canvas_and_not_blue_salons(): void
    {
        $edits = PhotoroomService::framingPreset('samsonite')['edits'];

        $this->assertSame(1600, $edits['canvas_width']);
        $this->assertSame(2400, $edits['canvas_height']);
        $this->assertSame(0.046, $edits['padding_bottom']);
        $this->assertTrue($edits['baseline_only']);

        /*
         * width and height are absent on purpose. They are sent to Photoroom
         * as outputSize, and an outputSize brings the fitting back — the case
         * would be scaled to the canvas and the brand's own sizing lost.
         */
        $this->assertArrayNotHasKey('width', $edits);
        $this->assertArrayNotHasKey('height', $edits);
        $this->assertSame('originalImage', $edits['output_size_mode']);
    }

    public function test_the_product_lands_on_the_line_at_its_own_size(): void
    {
        // A case 600 wide and 1000 tall, sitting too high in a 1600x2400 file.
        $png = $this->png(1600, 2400, 500, 200, 600, 1000);

        $out = app(ImageProcessingService::class)->placeOnBaseline($png, 1600, 2400, 0.046);

        [, $minY, , $maxY] = $this->box($out);

        $this->assertEqualsWithDelta(2400 * (1 - 0.046), $maxY, 2, 'the case is not on the line');
        $this->assertEqualsWithDelta(1000, $maxY - $minY, 2, 'the case was resized');
    }

    /** The source is already the canvas width, so the brand's own position is kept. */
    public function test_horizontal_position_is_left_alone(): void
    {
        $png = $this->png(1600, 2400, 300, 900, 600, 1000);

        $out = app(ImageProcessingService::class)->placeOnBaseline($png, 1600, 2400, 0.046);

        [$minX, , $maxX] = $this->box($out);

        $this->assertEqualsWithDelta(300, $minX, 2);
        $this->assertEqualsWithDelta(900, $maxX, 2);
    }

    /**
     * The one case where it does resize.
     *
     * A product that will not fit at all has to shrink; the alternative is
     * cropping the thing being sold.
     */
    public function test_something_too_big_for_the_canvas_is_shrunk_to_fit(): void
    {
        $png = $this->png(2000, 3000, 0, 0, 1999, 2999);

        $out = app(ImageProcessingService::class)->placeOnBaseline($png, 1600, 2400, 0.046);

        [$minX, $minY, $maxX, $maxY] = $this->box($out);

        $this->assertLessThanOrEqual(1600, $maxX - $minX + 1);
        $this->assertLessThanOrEqual(2400, $maxY - $minY + 1);
    }
}
