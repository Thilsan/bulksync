<?php

namespace Tests\Feature;

use App\Services\StandEraseCompositor;
use Tests\TestCase;

/**
 * Erasing the stand without redrawing the garment.
 *
 * Photoroom's only object removal regenerates the whole picture. Measured
 * against a real pair, that took an Aigner horseshoe monogram down to 4% of its
 * original detail and reinvented the pattern as rings — to remove a hanger
 * occupying 1.25% of the frame.
 *
 * So the generative pass is kept for the strip the stand sits in, and its
 * answer is used only where it actually changed something. What these tests
 * pin is the property that makes it worth doing: pixels outside the change are
 * bit-for-bit the photograph that went in.
 */
class PhotoEditorSurgicalEraseTest extends TestCase
{
    private StandEraseCompositor $compositor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->compositor = new StandEraseCompositor();
    }

    /** A photo with a dark "stand" across the top and detail below it. */
    private function photo(int $w = 900, int $h = 1200): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 240, 241, 245));

        // Fine detail in the lower two thirds — the thing that must survive.
        $ink = imagecolorallocate($img, 20, 20, 20);
        for ($y = (int) ($h * 0.45); $y < $h; $y += 4) {
            imageline($img, (int) ($w * 0.2), $y, (int) ($w * 0.8), $y, $ink);
        }

        // The stand: a dark bar in the top third.
        imagefilledrectangle($img, (int) ($w * 0.35), (int) ($h * 0.10),
                                   (int) ($w * 0.65), (int) ($h * 0.28), $ink);

        ob_start();
        imagejpeg($img, null, 96);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    /**
     * The whole photo as a generative erase would return it: the stand gone,
     * and — as they do — the detail lower down redrawn differently.
     *
     * That second part is the point. The redrawn detail must be rejected, and
     * the only thing rejecting it is the band.
     */
    private function erased(string $original): string
    {
        $img = imagecreatefromstring($original);
        $w   = imagesx($img);
        $h   = imagesy($img);
        $bg  = imagecolorat($img, 5, 5);

        // The stand: gone.
        imagefilledrectangle($img, (int) ($w * 0.30), 0, (int) ($w * 0.70), (int) ($h * 0.32), $bg);

        // The print: "redrawn" as a solid block, standing in for a reinvented
        // monogram. If this ends up in the result, the band failed.
        imagefilledrectangle($img, (int) ($w * 0.20), (int) ($h * 0.50),
                                   (int) ($w * 0.80), (int) ($h * 0.70),
                             imagecolorallocate($img, 200, 40, 40));

        ob_start();
        imagejpeg($img, null, 96);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    /**
     * A redrawn print below the band is rejected.
     *
     * This is the failure that shipped: a band reaching 40% down swallowed a
     * chest print at 28%, so the monogram was replaced by the model's version
     * of it. The band exists to stop that, and nothing else does.
     */
    public function test_detail_below_the_band_is_rejected_however_much_it_changed(): void
    {
        $original = $this->photo();
        $result   = $this->compositor->blend($original, $this->erased($original), 0.30);

        $b = imagecreatefromstring($result);

        // Where the fake redraw painted red, well below the band.
        $mid = imagecolorat($b, (int) (900 * 0.5), (int) (1200 * 0.60));
        $red = ($mid >> 16) & 0xFF;
        $grn = ($mid >> 8) & 0xFF;

        $this->assertLessThan(60, $red - $grn,
            'the redrawn print was composited in — the band did not hold');
    }

    /**
     * The property the whole approach exists for.
     *
     * Outside the erased area the result must be the original photograph, not
     * an approximation of it. A few levels of JPEG re-encode noise is the only
     * difference allowed — anything more means the garment was touched, and a
     * garment that can be touched can be reshaped and its print reinvented.
     */
    public function test_the_photograph_survives_outside_the_erased_area(): void
    {
        $original = $this->photo();
        $result   = $this->compositor->blend($original, $this->erased($original), 0.30);

        $a = imagecreatefromstring($original);
        $b = imagecreatefromstring($result);

        $this->assertSame([imagesx($a), imagesy($a)], [imagesx($b), imagesy($b)],
            'the composite changed the size of the photograph');

        // Sampled well below the strip, where the fine detail lives.
        $worst = 0;
        for ($y = (int) (imagesy($a) * 0.55); $y < imagesy($a); $y += 3) {
            for ($x = 0; $x < imagesx($a); $x += 3) {
                $p = imagecolorat($a, $x, $y);
                $q = imagecolorat($b, $x, $y);
                $worst = max($worst,
                    abs((($p >> 16) & 0xFF) - (($q >> 16) & 0xFF)),
                    abs((($p >> 8) & 0xFF) - (($q >> 8) & 0xFF)),
                    abs(($p & 0xFF) - ($q & 0xFF)),
                );
            }
        }

        $this->assertLessThanOrEqual(12, $worst,
            "detail below the strip was altered by up to {$worst} levels");
    }

    /** And the stand does actually go. */
    public function test_the_stand_is_gone_where_the_erase_changed_it(): void
    {
        $original = $this->photo();
        $result   = $this->compositor->blend($original, $this->erased($original), 0.30);

        $b = imagecreatefromstring($result);

        // Centre of where the dark bar was: should now be backdrop, not ink.
        $mid = imagecolorat($b, (int) (900 * 0.5), (int) (1200 * 0.19));
        $brightness = max(($mid >> 16) & 0xFF, ($mid >> 8) & 0xFF, $mid & 0xFF);

        $this->assertGreaterThan(180, $brightness,
            'the stand is still dark, so the erase was not composited in');
    }

    /**
     * A reframed erase is refused rather than stretched into place.
     *
     * Stretching one produces exactly what a bad composite looks like: a
     * misaligned collar over the real one, ghost shoulders, a doubled hanger.
     * There is no recovering from it, and the photograph with its stand still
     * in shot is worth more than a garbled one.
     */
    public function test_a_reframed_erase_is_refused(): void
    {
        $original = $this->photo(900, 1200);           // 0.75

        $square = imagecreatetruecolor(1000, 1000);    // 1.00 — recomposed, not erased
        imagefill($square, 0, 0, imagecolorallocate($square, 250, 250, 250));
        ob_start();
        imagejpeg($square, null, 90);
        imagedestroy($square);
        $reframed = (string) ob_get_clean();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/reframed/');

        $this->compositor->blend($original, $reframed, 0.30);
    }

    /** An unreadable strip must not take the photograph down with it. */
    public function test_a_broken_erase_is_refused_rather_than_guessed_at(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->compositor->blend($this->photo(), 'not an image at all', 0.30);
    }
}
