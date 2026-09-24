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

    /**
     * The gap this was written to close: a redraw that held its shape, its
     * position and its print, and still came back the wrong colour. Nothing
     * before this metric existed had any way to notice.
     */
    public function test_it_refuses_a_redraw_that_changed_the_garments_colour(): void
    {
        $result = $this->service->composite($this->original(), $this->recolouredGhost());

        $this->assertFalse($result['accepted']);
        $this->assertSame('recoloured', $result['verdict']);
        $this->assertStringContainsString('colour', $result['reason']);
        $this->assertNotNull($result['metrics']['colour_shift']);
        $this->assertGreaterThan(0.12, $result['metrics']['colour_shift']);
    }

    /**
     * A reason says what was measured and why the two images cannot be
     * blended. It must not say which image was then published, because this
     * service does not decide that — the job does, and the job appends its
     * own sentence to whatever comes back from here.
     *
     * The recoloured reason used to end "the photograph was kept instead".
     * That was true while a failed composite fell back to the photograph. It
     * stopped being true when the redraw started being kept instead, and
     * nothing caught it, so the review grid carried cards reading "the
     * photograph was kept instead. The photograph was not used" — one card,
     * two contradictory sentences, in front of the person deciding whether
     * the image is safe to push.
     */
    public function test_a_reason_never_claims_which_image_was_published(): void
    {
        $rejections = [
            'recoloured' => $this->recolouredGhost(),
            'reshaped'   => $this->ghost(garmentRight: 260),
            'moved'      => $this->tiltedGhost(),
        ];

        foreach ($rejections as $verdict => $ghost) {
            $reason = $this->service->composite($this->original(), $ghost)['reason'];

            foreach (['was kept instead', 'photograph was kept', 'was not used'] as $claim) {
                $this->assertStringNotContainsString($claim, $reason,
                    "the {$verdict} reason states an outcome this service does not decide");
            }
        }
    }

    /**
     * Not so sensitive that ordinary rendering variance between a photograph
     * and a generative redraw of the same navy fabric reads as a colour
     * failure — only a colour a person would actually call different.
     */
    public function test_it_accepts_the_ordinary_colour_variance_of_a_faithful_redraw(): void
    {
        $result = $this->service->composite($this->original(), $this->ghost());

        $this->assertTrue($result['accepted']);
        $this->assertNotNull($result['metrics']['colour_shift']);
        $this->assertLessThan(0.12, $result['metrics']['colour_shift']);
    }

    /**
     * A near-white garment has no reliably garment-coloured pixels to average
     * on either side, so the metric declines to judge rather than guessing —
     * the same honesty the existing chroma filter already has for this case.
     */
    public function test_colour_is_not_judged_when_neither_side_has_enough_of_it(): void
    {
        $result = $this->service->composite($this->whiteGarment(), $this->greyedGarment());

        $this->assertNull($result['metrics']['colour_shift']);
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

    /**
     * How much stand is in the photograph must not change how much garment
     * the guard thinks was reworked.
     *
     * The coverage guard divided replaced pixels by every non-background
     * pixel in the subject box, and the subject box contains the form. The
     * form is also precisely what the mask replaces, so it sat in the
     * numerator and the denominator at a ratio of one, dragging the whole
     * figure toward 100% as more of it came into frame — while the reason
     * printed underneath said "%% of the garment differs from the original".
     *
     * Backwards, and it showed. One batch of tops, two views of a SKU
     * differing only in whether the form's legs were in shot: 47.9% against
     * 18.2%, and 47.4% and 55.3% against 8.0%. Every high one refused for
     * reworking a garment nothing had touched, and the operator got the
     * mannequin back on exactly the photographs where it was most visible.
     *
     * So this holds the invariant rather than a number: same garment, same
     * redraw, a form four times taller, and the figure must barely move.
     */
    public function test_a_taller_form_does_not_read_as_a_reworked_garment(): void
    {
        $redraw = $this->ghost();

        $short = $this->service->composite($this->original(), $redraw);
        $tall  = $this->service->composite($this->originalWithTallForm(), $redraw);

        $this->assertLessThan(
            0.05,
            abs($tall['metrics']['mask_coverage'] - $short['metrics']['mask_coverage']),
            'the amount of stand in frame moved a figure that reports how much garment changed',
        );

        $this->assertTrue($short['accepted']);
        $this->assertTrue(
            $tall['accepted'],
            'a faithful redraw was refused for having too much mannequin to remove',
        );
    }

    /**
     * A refusal says whether the garment was found, or whether the figures
     * beside it came from the fallback.
     *
     * The fallback compares garment-plus-stand against garment, and from the
     * outside its numbers are indistinguishable from real ones: a refusal at
     * 55% reads as a recut garment whether or not anything was recut. Every
     * cream, white and pale-grey product in the catalogue lands there, and
     * without this flag the only way to tell the two apart is to argue about
     * the photograph.
     */
    public function test_it_says_whether_it_found_the_garment(): void
    {
        $navy = $this->service->composite($this->original(), $this->ghost());

        $this->assertTrue($navy['metrics']['garment_found'],
            'a navy garment carries plenty of colour and should have been found');

        $pale = $this->service->composite($this->paleGarment(), $this->paleGhost());

        $this->assertFalse($pale['metrics']['garment_found'],
            'a cream garment has no colour to find, and the figures beside it are fallback figures');
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
     * The same garment on a form whose legs run four times as far below the
     * hem — the leg-form mannequin a top or a shirt is shot on, where the
     * stand is a large part of the frame rather than a neck behind a collar.
     *
     * Identical to original() above the hem, so any difference in what the
     * metrics report comes from the stand and nothing else.
     */
    private function originalWithTallForm(): string
    {
        $img = $this->canvas(800, 1800);

        $this->box($img, [300, 1000, 500, 1780], self::FORM);
        $this->box($img, self::GARMENT, self::NAVY);
        $this->box($img, self::NECK_HOLE, self::FORM);

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

    /** A cream garment on a cream form — no colour anywhere to tell them apart. */
    private function paleGarment(): string
    {
        $img = $this->canvas(800, 1400);

        $this->box($img, [300, 1000, 500, 1380], self::FORM);
        $this->box($img, self::GARMENT, [232, 222, 205]);
        $this->box($img, self::NECK_HOLE, self::FORM);

        return $this->png($img);
    }

    /** Its redraw: same proportions, same colour, stand gone. Nothing recut. */
    private function paleGhost(): string
    {
        $img = $this->canvas(400, 600);

        $this->box($img, [100, 100, 300, 500], [232, 222, 205]);
        $this->box($img, [175, 100, 225, 150], [214, 205, 189]);

        return $this->png($img);
    }

    /**
     * Same box, same position, same print treatment as ghost() — only the
     * colour differs, and by enough that a person would call it a different
     * colour, not a different rendering of the same one. Navy [40,90,160]
     * to burgundy [120,20,50] is roughly a third of the largest possible RGB
     * distance, well past the 12% limit.
     */
    private function recolouredGhost(): string
    {
        $img = $this->canvas(400, 600);

        $this->box($img, [100, 100, 300, 500], [120, 20, 50]);
        $this->box($img, [175, 100, 225, 150], [90, 15, 38]);
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
