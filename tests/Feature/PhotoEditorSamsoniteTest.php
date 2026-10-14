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

    /** Nothing but the base line, when no target box is given. */
    public function test_without_a_target_box_it_only_moves_the_product(): void
    {
        // A case 600 wide and 1000 tall, sitting too high in a 1600x2400 file.
        $png = $this->png(1600, 2400, 500, 200, 600, 1000);

        $out = app(ImageProcessingService::class)->placeOnBaseline($png, 1600, 2400, 0.046);

        [, $minY, , $maxY] = $this->box($out);

        $this->assertEqualsWithDelta(2400 * (1 - 0.046), $maxY, 2, 'the case is not on the line');
        $this->assertEqualsWithDelta(1000, $maxY - $minY, 2, 'the case was resized');
    }

    /*
     * A small picture is brought up to the brand's own size.
     *
     * This preset began with no sizing: the supplier's files arrive with the
     * case already right. Then the same category met images fetched from
     * Shopify — whatever was uploaded there — and a small cutout kept at its
     * own size landed as a thumbnail in the middle of a 2400-tall canvas.
     */
    public function test_a_small_source_is_scaled_up_to_the_brands_size(): void
    {
        $edits = PhotoroomService::framingPreset('samsonite')['edits'];

        // A 400x600 cutout: a quarter of the size the brand publishes at.
        $png = $this->png(400, 600, 50, 50, 300, 400);

        $out = app(ImageProcessingService::class)->placeOnBaseline(
            $png, 1600, 2400, $edits['padding_bottom'], 'FFFFFF',
            $edits['fit_width'], $edits['fit_height'],
        );

        [$minX, $minY, $maxX, $maxY] = $this->box($out);

        $this->assertSame([1600, 2400], array_slice(getimagesizefromstring($out), 0, 2));
        $this->assertEqualsWithDelta(2400 * (1 - $edits['padding_bottom']), $maxY, 3, 'not on the line');

        // Fitted to whichever edge binds, and centred.
        $this->assertLessThanOrEqual((int) round(1600 * $edits['fit_width']) + 2, $maxX - $minX + 1);
        $this->assertLessThanOrEqual((int) round(2400 * $edits['fit_height']) + 2, $maxY - $minY + 1);
        // Centred to within the proxy's own precision: subjectBox measures on
        // a 240px proxy, so the horizontal extents carry about ten pixels of
        // slop on a 1600-wide canvas. The base line is measured at full
        // resolution because it is the thing that must be exact; a case ten
        // pixels off centre is not visible beside one ten pixels off its line.
        $this->assertEqualsWithDelta(1600 - $maxX, $minX, 14, 'the case is off centre');
    }

    /**
     * And a file already at the brand's size comes through unchanged.
     *
     * Not as a special case — the scale simply works out to 1.0, which is the
     * original no-resize behaviour arrived at by arithmetic.
     */
    public function test_a_supplier_file_already_the_right_size_is_not_resized(): void
    {
        $edits = PhotoroomService::framingPreset('samsonite')['edits'];

        // The reference: 967 x 1326 on 1600 x 2400, sitting too high.
        $png = $this->png(1600, 2400, 316, 200, 966, 1325);

        $out = app(ImageProcessingService::class)->placeOnBaseline(
            $png, 1600, 2400, $edits['padding_bottom'], 'FFFFFF',
            $edits['fit_width'], $edits['fit_height'],
        );

        [$minX, $minY, $maxX, $maxY] = $this->box($out);

        $this->assertEqualsWithDelta(967, $maxX - $minX + 1, 4, 'the case was resized');
        $this->assertEqualsWithDelta(1326, $maxY - $minY + 1, 4, 'the case was resized');
        $this->assertEqualsWithDelta(2400 * (1 - $edits['padding_bottom']), $maxY, 3);
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
