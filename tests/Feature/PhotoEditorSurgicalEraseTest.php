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

    /** The same photo with the stand painted out, as an erase would return it. */
    private function erased(string $strip): string
    {
        $img = imagecreatefromstring($strip);
        imagefilledrectangle($img, 0, 0, imagesx($img) - 1, (int) (imagesy($img) * 0.9),
            imagecolorat($img, 5, 5));

        ob_start();
        imagejpeg($img, null, 96);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    public function test_the_strip_sent_for_erasing_is_only_the_top_of_the_photo(): void
    {
        $strip = $this->compositor->topStrip($this->photo(), 0.40);

        $this->assertSame(900, $strip['width']);
        $this->assertSame(480, $strip['height'], '40% of 1200 is 480');

        [$w, $h] = getimagesizefromstring($strip['bytes']);
        $this->assertSame([900, 480], [$w, $h]);
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
        $strip    = $this->compositor->topStrip($original, 0.40);
        $result   = $this->compositor->blend($original, $this->erased($strip['bytes']), $strip['height']);

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
        $strip    = $this->compositor->topStrip($original, 0.40);
        $result   = $this->compositor->blend($original, $this->erased($strip['bytes']), $strip['height']);

        $b = imagecreatefromstring($result);

        // Centre of where the dark bar was: should now be backdrop, not ink.
        $mid = imagecolorat($b, (int) (900 * 0.5), (int) (1200 * 0.19));
        $brightness = max(($mid >> 16) & 0xFF, ($mid >> 8) & 0xFF, $mid & 0xFF);

        $this->assertGreaterThan(180, $brightness,
            'the stand is still dark, so the erase was not composited in');
    }

    /** An unreadable strip must not take the photograph down with it. */
    public function test_a_broken_erase_is_refused_rather_than_guessed_at(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->compositor->blend($this->photo(), 'not an image at all', 400);
    }
}
