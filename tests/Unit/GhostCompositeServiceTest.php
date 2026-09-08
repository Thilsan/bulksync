<?php

namespace Tests\Unit;

use App\Services\GhostCompositeService;
use Tests\TestCase;

/**
 * The composite has one job and one trap, and both are tested here.
 *
 * The job: a mannequin showing through a neckline is gone from the result.
 *
 * The trap: the redraw comes back at a fraction of the resolution, so it also
 * disagrees with the original everywhere it lost detail. A composite driven by
 * disagreement alone would take the redraw's ruined version of a print for
 * exactly that reason — so there is a test that the print survives untouched,
 * and it is the more important of the two.
 *
 * Synthetic images rather than photographs, because the assertions have to be
 * about pixels at known coordinates. The geometry models a real one: a garment
 * on a form, the form's neck showing through the collar, its legs below the
 * hem, and a fine print across the chest.
 */
class GhostCompositeServiceTest extends TestCase
{
    private GhostCompositeService $service;

    /*
     * The original, at 800x1200. Everything is in these coordinates so a test
     * can name a place rather than a number.
     */
    private const GARMENT   = [200, 200, 600, 1000];   // x0, y0, x1, y1
    private const NECK_HOLE = [350, 200, 450, 300];    // the form, inside the collar
    private const LEGS      = [350, 1000, 450, 1160];  // the form, below the hem
    private const PRINT     = [280, 420, 520, 520];    // fine striped print on the chest

    private const NAVY   = [40, 90, 160];
    private const INNER  = [24, 58, 104];  // what a redraw puts inside a collar
    private const FORM   = [215, 185, 160]; // a skin-toned dress form
    private const INK    = [240, 230, 60];  // the print's stripes

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GhostCompositeService();
    }

    public function test_it_removes_a_mannequin_showing_through_the_neckline(): void
    {
        $result = $this->service->composite($this->original(), $this->ghost());

        $this->assertTrue($result['accepted'], 'the composite should hold: ' . $result['reason']);

        // The middle of the neck hole, where the form was.
        [$r, $g, $b] = $this->pixelAt($result['image'], 400, 250);

        $this->assertLessThan(
            120,
            $r,
            "The form's skin tone is still in the neckline: rgb({$r}, {$g}, {$b})",
        );

        // What replaced it should be the redraw's inner fabric — a blue, not a
        // washed-out blend of blue and skin.
        $this->assertGreaterThan($r, $b, "The neckline is not fabric-coloured: rgb({$r}, {$g}, {$b})");
    }

    public function test_it_removes_the_stand_below_the_hem(): void
    {
        $result = $this->service->composite($this->original(), $this->ghost());

        [$r, $g, $b] = $this->pixelAt($result['image'], 400, 1080);

        $this->assertGreaterThan(
            230,
            min($r, $g, $b),
            "The stand is still below the hem: rgb({$r}, {$g}, {$b})",
        );
    }

    /**
     * The one that matters. The redraw flattened the chest print to a solid
     * block — which is what a 1000px redraw does to a logo, measured at 7% of
     * the original's detail. The composite must keep the original's pixels
     * there in spite of the two images disagreeing completely.
     */
    public function test_it_keeps_the_original_print_the_redraw_flattened(): void
    {
        $result = $this->service->composite($this->original(), $this->ghost());

        $original  = $this->rowSpread($this->original(), 470, self::PRINT[0], self::PRINT[2]);
        $composite = $this->rowSpread($result['image'], 470, self::PRINT[0], self::PRINT[2]);
        $redraw    = $this->rowSpread($this->ghost(), 235, 140, 260);

        // The redraw genuinely destroyed the detail — otherwise this test is
        // proving nothing.
        $this->assertLessThan(10, $redraw, 'the fixture should have a flattened print in the redraw');
        $this->assertGreaterThan(40, $original, 'the fixture should have a detailed print in the original');

        $this->assertGreaterThan(
            $original * 0.9,
            $composite,
            'The print lost its detail — the composite took it from the redraw instead of the original.',
        );
    }

    public function test_it_refuses_a_redraw_that_recut_the_garment(): void
    {
        // Same everything, but the redraw made the garment narrow: 160 wide
        // where the original is 200 at the redraw's scale.
        $result = $this->service->composite($this->original(), $this->ghost(garmentRight: 260));

        $this->assertFalse($result['accepted']);
        $this->assertSame('reshaped', $result['verdict']);
        $this->assertStringContainsString('proportions', $result['reason']);
    }

    public function test_it_refuses_a_redraw_that_moved_the_garment(): void
    {
        $result = $this->service->composite($this->original(), $this->tiltedGhost());

        $this->assertFalse($result['accepted']);
        $this->assertSame('moved', $result['verdict']);
    }

    /**
     * The known weak spot, asserted rather than left to be discovered.
     *
     * The mask only replaces pixels that are near-neutral in the original,
     * which is what protects a coloured garment from being taken wholesale
     * from the redraw. A near-white garment has no such protection — it looks
     * like a dress form to every test the mask applies — so a redraw that
     * recolours one is caught by the coverage guard instead.
     */
    public function test_it_refuses_a_redraw_that_reworked_a_near_neutral_garment(): void
    {
        $result = $this->service->composite($this->whiteGarment(), $this->greyedGarment());

        $this->assertFalse($result['accepted']);
        $this->assertSame('redrawn', $result['verdict']);
    }

    public function test_it_reports_the_geometry_it_measured(): void
    {
        $result = $this->service->composite($this->original(), $this->ghost());

        $this->assertSame('800x1200', $result['metrics']['original_size']);
        $this->assertGreaterThan(0.9, $result['metrics']['containment']);

        // A neckline and a pair of legs, not a whole garment.
        $this->assertLessThan(0.35, $result['metrics']['mask_coverage']);
        $this->assertGreaterThan(0.0, $result['metrics']['mask_coverage']);
    }

    public function test_it_refuses_an_image_it_cannot_read(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not read the ghost image.');

        $this->service->composite($this->original(), 'not an image');
    }

    public function test_it_refuses_a_blank_original(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No subject found in the original');

        $this->service->composite($this->blank(400, 400), $this->ghost());
    }

    // ── Fixtures ───────────────────────────────────────────────────────────

    /** A garment on a skin-toned form, with a fine print across the chest. */
    private function original(): string
    {
        $img = $this->canvas(800, 1200);

        $this->box($img, self::LEGS, self::FORM);
        $this->box($img, self::GARMENT, self::NAVY);
        $this->box($img, self::NECK_HOLE, self::FORM);

        // Two-pixel stripes: fine enough that a half-resolution redraw cannot
        // hold them, which is the whole point of the fixture.
        [$x0, $y0, $x1, $y1] = self::PRINT;

        for ($x = $x0; $x <= $x1; $x += 4) {
            $this->box($img, [$x, $y0, min($x + 1, $x1), $y1], self::INK);
        }

        return $this->png($img);
    }

    /**
     * Photoroom's redraw of it, at half the resolution: stand gone, inner
     * fabric invented behind the collar, and the chest print flattened to the
     * solid block a low-resolution generative pass turns it into.
     */
    private function ghost(int $garmentRight = 300): string
    {
        $img = $this->canvas(400, 600);

        $this->box($img, [100, 100, $garmentRight, 500], self::NAVY);
        $this->box($img, [175, 100, 225, 150], self::INNER);

        // The print, averaged into one colour — detail destroyed.
        $this->box($img, [140, 210, 260, 260], [140, 160, 110]);

        return $this->png($img);
    }

    /** The same redraw, with the garment leaning — the failure to catch. */
    private function tiltedGhost(): string
    {
        $img = @imagecreatefromstring($this->ghost());

        $white   = imagecolorallocate($img, 255, 255, 255);
        $rotated = imagerotate($img, 8, $white);
        imagedestroy($img);

        return $this->png($rotated);
    }

    /** A near-white garment: nothing the chroma test can tell from a form. */
    private function whiteGarment(): string
    {
        $img = $this->canvas(800, 1200);

        $this->box($img, self::GARMENT, [246, 245, 243]);
        $this->box($img, self::NECK_HOLE, self::FORM);

        return $this->png($img);
    }

    /** A redraw that took the near-white garment several shades darker. */
    private function greyedGarment(): string
    {
        $img = $this->canvas(400, 600);

        $this->box($img, [100, 100, 300, 500], [196, 196, 194]);
        $this->box($img, [175, 100, 225, 150], [188, 188, 186]);

        return $this->png($img);
    }

    private function blank(int $w, int $h): string
    {
        return $this->png($this->canvas($w, $h));
    }

    // ── Drawing and measuring ──────────────────────────────────────────────

    private function canvas(int $w, int $h): \GdImage
    {
        $img   = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, $white);

        return $img;
    }

    private function box(\GdImage $img, array $rect, array $rgb): void
    {
        [$x0, $y0, $x1, $y1] = $rect;

        imagefilledrectangle(
            $img,
            $x0,
            $y0,
            min($x1, imagesx($img) - 1),
            min($y1, imagesy($img) - 1),
            imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]),
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

    /** @return array{0:int,1:int,2:int} */
    private function pixelAt(string $bytes, int $x, int $y): array
    {
        $img = @imagecreatefromstring($bytes);
        $rgb = imagecolorat($img, $x, $y);
        imagedestroy($img);

        return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
    }

    /**
     * Standard deviation of luma along one row — how much detail is there.
     *
     * A row of stripes scores high, a row of one flat colour scores zero, and
     * that difference is what the print test is actually about.
     */
    private function rowSpread(string $bytes, int $y, int $x0, int $x1): float
    {
        $img = @imagecreatefromstring($bytes);

        $values = [];

        for ($x = $x0; $x <= $x1; $x++) {
            $rgb = imagecolorat($img, $x, $y);

            $values[] = 0.299 * (($rgb >> 16) & 0xFF)
                + 0.587 * (($rgb >> 8) & 0xFF)
                + 0.114 * ($rgb & 0xFF);
        }

        imagedestroy($img);

        $mean = array_sum($values) / count($values);
        $sum  = 0.0;

        foreach ($values as $v) {
            $sum += ($v - $mean) ** 2;
        }

        return sqrt($sum / count($values));
    }
}
