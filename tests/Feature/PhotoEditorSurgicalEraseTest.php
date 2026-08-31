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

    /** A photo with a dark "stand" across the top and fine detail below it. */
    private function photo(int $w = 900, int $h = 1200): string
    {
        $img = imagecreatefromstring($this->blank($w, $h));

        /*
         * A print on the chest, as a real garment carries one: fine detail over
         * part of the garment rather than all of it. Covering most of it would
         * outvote the garment's own colour when the service samples for it,
         * which is a fixture problem rather than a real one — no product photo
         * is three quarters logo.
         */
        $ink = imagecolorallocate($img, 20, 20, 20);
        for ($y = (int) ($h * 0.48); $y < (int) ($h * 0.62); $y += 3) {
            imageline($img, (int) ($w * 0.30), $y, (int) ($w * 0.70), $y, $ink);
        }

        // The stand: dark, in the top quarter, resembling neither backdrop nor
        // garment — which is exactly how it is found.
        imagefilledrectangle($img, (int) ($w * 0.35), (int) ($h * 0.08),
                                   (int) ($w * 0.65), (int) ($h * 0.22), $ink);

        ob_start();
        imagejpeg($img, null, 96);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    /** Backdrop and a pale garment below it, with nothing to erase. */
    private function blank(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 239, 240, 244));
        imagefilledrectangle($img, (int) ($w * 0.15), (int) ($h * 0.30), (int) ($w * 0.85), $h - 1,
            imagecolorallocate($img, 241, 240, 236));

        ob_start();
        imagejpeg($img, null, 96);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    /**
     * The property the whole approach exists for.
     *
     * Below the band the result must be the photograph, not an approximation of
     * it. A few levels of JPEG re-encode noise is all that is allowed — a
     * garment that can be touched can be reshaped and have its print
     * reinvented, which is what the generative route did.
     */
    public function test_the_photograph_below_the_band_is_untouched(): void
    {
        $original = $this->photo();
        $result   = $this->compositor->erase($original, 0.27);

        $a = imagecreatefromstring($original);
        $b = imagecreatefromstring($result);

        $this->assertSame([imagesx($a), imagesy($a)], [imagesx($b), imagesy($b)],
            'the erase changed the size of the photograph');

        $worst = 0;
        for ($y = (int) (imagesy($a) * 0.30); $y < imagesy($a); $y += 3) {
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
            "detail below the band moved by up to {$worst} levels");
    }

    /** And the stand does actually go. */
    public function test_the_stand_is_removed(): void
    {
        $result = $this->compositor->erase($this->photo(), 0.27);
        $b      = imagecreatefromstring($result);

        // Centre of where the dark bar was.
        $c = imagecolorat($b, (int) (900 * 0.5), (int) (1200 * 0.15));
        $brightness = max(($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF);

        $this->assertGreaterThan(200, $brightness,
            'the stand is still dark, so it was not erased');
    }

    /**
     * The filled area must end up exactly the backdrop, not merely close to it.
     *
     * This is where the erase silently failed. The fill blends from its
     * surroundings, so a hanger against the backdrop came back 14 levels away
     * from it — looking right, and then read as part of the subject by
     * Photoroom's background removal, which kept a pale hanger-shaped
     * silhouette in the finished image.
     *
     * Close is not good enough. It has to be equal.
     */
    public function test_the_filled_area_becomes_exactly_the_backdrop(): void
    {
        $original = $this->photo();
        $result   = $this->compositor->erase($original, 0.27);

        $b        = imagecreatefromstring($result);
        $backdrop = imagecolorat($b, 5, 5);

        // Centre of where the stand was.
        $filled = imagecolorat($b, (int) (900 * 0.5), (int) (1200 * 0.15));

        $this->assertSame($backdrop, $filled,
            'the filled area is near the backdrop but not equal to it, so the cutout will keep it');
    }

    /**
     * A photo with nothing to erase comes back byte-for-byte.
     *
     * Re-encoding it would spend a JPEG generation to achieve nothing, and a
     * flat-lay or a bag on a table has no stand in the first place.
     */
    public function test_a_photo_with_no_stand_is_returned_untouched(): void
    {
        $flat = $this->blank(600, 800);

        $this->assertSame($flat, $this->compositor->erase($flat, 0.27));
    }

    /** An unreadable image is refused rather than guessed at. */
    public function test_an_unreadable_image_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->compositor->erase('not an image at all', 0.27);
    }
}
