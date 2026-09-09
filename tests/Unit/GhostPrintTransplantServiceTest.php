<?php

namespace Tests\Unit;

use App\Services\GhostPrintTransplantService;
use Tests\TestCase;

/**
 * The transplant has one job: the print in the result must be the
 * photograph's, not the redraw's.
 *
 * The fixtures model what Ghost Mannequin actually does, which is the whole
 * reason this class exists rather than GhostCompositeService:
 *
 *   - it re-renders the garment on its own square canvas at 1024
 *   - it re-proportions and re-frames it, so the two cannot be registered
 *   - it invents a *different* pattern where the print was, rather than a
 *     blurred version of the real one
 *
 * So the fixture's redraw carries a wrong pattern at low resolution, and the
 * test asserts the result carries the right pattern at high resolution. A test
 * that only measured sharpness would pass on a crisp forgery, which is exactly
 * the trap Photoroom's 4K tier falls into.
 */
class GhostPrintTransplantServiceTest extends TestCase
{
    private GhostPrintTransplantService $service;

    /** Ink, fabric and the backdrop — all three near each other, as they are in life. */
    private const INK     = [26, 26, 30];
    private const FABRIC  = [238, 238, 240];
    private const BACKDROP = [239, 240, 244];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GhostPrintTransplantService();
    }

    public function test_it_puts_the_photographs_print_onto_the_redraw(): void
    {
        $result = $this->service->transplant($this->photo(), $this->redraw(), 2000);

        $this->assertTrue($result['accepted'], $result['reason']);
        $this->assertSame('2000x2000', $result['metrics']['output_size']);

        // The real print is fine vertical bars; the redraw's forgery is a
        // coarse checker. Bars survive as a high count of light/dark changes
        // along a row, and a checker at the redraw's resolution cannot.
        $this->assertGreaterThan(
            14,
            $this->crossings($result['image'], 0.5),
            'The print in the result is not the photograph\'s fine artwork.',
        );
    }

    /**
     * The one that would catch a regression to "just upscale the redraw".
     *
     * Enlarging the redraw gives a plausible, smooth, wrong print. Only real
     * transplanted pixels give the bar count above, so this compares the two
     * directly rather than trusting a single threshold.
     */
    public function test_the_result_beats_an_upscale_of_the_redraw(): void
    {
        $result = $this->service->transplant($this->photo(), $this->redraw(), 2000);

        $transplanted = $this->crossings($result['image'], 0.5);
        $upscaled     = $this->crossings($this->upscaledRedraw(2000), 0.5);

        $this->assertGreaterThan(
            $upscaled * 2,
            $transplanted,
            'The transplant is no better than enlarging the redraw, which is the thing it replaces.',
        );
    }

    public function test_it_reports_how_much_detail_the_swap_bought(): void
    {
        $result = $this->service->transplant($this->photo(), $this->redraw(), 2000);

        $this->assertGreaterThan(1.2, $result['metrics']['print_detail_gain']);

        // The print is flat artwork on a flat chest, so the redraw should not
        // have changed its proportions much even though it re-cut the garment.
        $this->assertLessThan(0.12, $result['metrics']['print_aspect_shift']);
    }

    /**
     * The stand must not be mistaken for the print. It is darker and larger
     * than the artwork here, so only the edge-density test tells them apart —
     * position would work on this fixture and fail on a stand at the hem.
     */
    public function test_it_transplants_the_print_and_not_the_hanger(): void
    {
        $result = $this->service->transplant($this->photo(), $this->redraw(), 2000);

        // The hanger is a solid slab 300 px wide in the fixture. If it had been
        // taken for the print, the transplanted box would be about that shape
        // and would carry no bars at all.
        [$w, $h] = explode('x', $result['metrics']['photo_print']);

        $this->assertGreaterThan(
            1.2,
            (int) $w / (int) $h,
            'The transplanted region is not the wide print band — the hanger was probably picked.',
        );
    }

    /**
     * A redraw models the garment with shading the flat photograph does not
     * have, and that shading nearly broke this.
     *
     * With the ink threshold set only 70 levels below the frame's light level,
     * the shadows down the sleeve and along the hem — measured at luma 160 on a
     * real redraw — read as ink, chained into one run across the whole garment,
     * and the "print" came out as 52% of the frame. It did not throw; it found
     * the wrong thing and would have transplanted a garment-sized patch.
     *
     * So the fixture carries shading of its own, and the assertion is on the
     * size of what was found rather than merely on success.
     */
    public function test_shading_on_the_redraw_is_not_mistaken_for_the_print(): void
    {
        $result = $this->service->transplant($this->photo(), $this->shadedRedraw(), 2000);

        $this->assertTrue($result['accepted'], $result['reason']);

        [$w, $h] = array_map('intval', explode('x', $result['metrics']['target_print']));

        $this->assertLessThan(
            0.25 * 2000 * 2000,
            $w * $h,
            'the print found covers a quarter of the canvas — shading was gathered in with it',
        );
    }

    public function test_it_refuses_a_garment_with_no_print(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No print found');

        $this->service->transplant($this->plainPhoto(), $this->redraw(), 2000);
    }

    public function test_it_refuses_an_image_it_cannot_read(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not read the ghost image.');

        $this->service->transplant($this->photo(), 'not an image', 2000);
    }

    // ── Fixtures ───────────────────────────────────────────────────────────

    /**
     * The photograph: a cream garment on a backdrop one level away from it,
     * a dark hanger across the shoulders, and a chest print of fine bars.
     */
    private function photo(): string
    {
        $img = $this->canvas(2400, 3000, self::BACKDROP);

        // Garment.
        $this->box($img, [500, 600, 1900, 2600], self::FABRIC);

        // The hanger: a solid slab, darker than the print and wider than tall.
        $this->box($img, [1050, 380, 1350, 640], [64, 48, 40]);

        /*
         * The print: fine bars in two rows, drawn as separate marks so the
         * fixture also exercises the clustering — a real print is three bears
         * and six letters, not one connected shape.
         */
        foreach ([[1250, 1500], [1560, 1700]] as [$y0, $y1]) {
            for ($x = 900; $x <= 1500; $x += 24) {
                $this->box($img, [$x, $y0, $x + 9, $y1], self::INK);
            }
        }

        return $this->png($img);
    }

    /** The same garment with no print at all. */
    private function plainPhoto(): string
    {
        $img = $this->canvas(2400, 3000, self::BACKDROP);
        $this->box($img, [500, 600, 1900, 2600], self::FABRIC);

        return $this->png($img);
    }

    /**
     * Photoroom's redraw: 1024 square, garment re-proportioned and re-framed,
     * hanger gone — and the print replaced with a coarse checker, which is
     * what it actually does rather than blurring the real one.
     */
    private function redraw(): string
    {
        $img = $this->canvas(1024, 1024, self::BACKDROP);

        // Re-drawn garment: narrower and higher than a straight scale of the
        // photograph's would be.
        $this->box($img, [260, 180, 780, 900], self::FABRIC);

        /*
         * The forged print: coarse blocks, and deliberately at the same
         * proportions as the photograph's print (1.37 against 1.35). A print is
         * flat artwork on a flat chest, so the redraw keeps its shape even
         * while re-cutting the garment around it — measured at 3.5% on the
         * Aigner tee. A fixture that got this wrong would only ever exercise
         * the rejection path.
         */
        for ($x = 380; $x <= 620; $x += 40) {
            for ($y = 430; $y <= 600; $y += 40) {
                $this->box($img, [$x, $y, $x + 19, $y + 19], self::INK);
            }
        }

        return $this->png($img);
    }

    /**
     * The same redraw, modelled with shading: a pure-white ground, a shaded
     * sleeve and a shaded hem at the levels a real one carries.
     */
    private function shadedRedraw(): string
    {
        // Photoroom hands the redraw back on white, which is what pushes the
        // frame's light level to 255 and makes a relative threshold generous.
        $img = $this->canvas(1024, 1024, [255, 255, 255]);

        $this->box($img, [260, 180, 780, 900], self::FABRIC);

        // Sleeve and hem shadows, at the luma a real redraw measured.
        $this->box($img, [260, 180, 330, 900], [160, 160, 162]);
        $this->box($img, [260, 830, 780, 900], [169, 169, 171]);

        for ($x = 380; $x <= 620; $x += 40) {
            for ($y = 430; $y <= 600; $y += 40) {
                $this->box($img, [$x, $y, $x + 19, $y + 19], self::INK);
            }
        }

        return $this->png($img);
    }

    /** The redraw simply enlarged — the thing the transplant has to beat. */
    private function upscaledRedraw(int $edge): string
    {
        $img = @imagecreatefromstring($this->redraw());
        $big = imagescale($img, $edge, $edge);
        imagedestroy($img);

        return $this->png($big);
    }

    // ── Drawing and measuring ──────────────────────────────────────────────

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

    /**
     * How many times a horizontal line through the print crosses between light
     * and dark: the count of bars actually rendered there.
     *
     * A count, not a sharpness score — a smooth forgery of the wrong pattern
     * scores low here however crisp it is, which is the distinction the whole
     * class turns on. Scanned across the middle of the frame and reported for
     * the row that crosses most, so it does not depend on the print landing at
     * one exact height.
     */
    private function crossings(string $bytes, float $centre): int
    {
        $img = @imagecreatefromstring($bytes);
        $w   = imagesx($img);
        $h   = imagesy($img);

        $best = 0;

        for ($y = (int) (($centre - 0.12) * $h); $y < (int) (($centre + 0.12) * $h); $y += 4) {
            $n    = 0;
            $dark = null;

            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($img, $x, $y);

                $l = 0.299 * (($c >> 16) & 0xFF) + 0.587 * (($c >> 8) & 0xFF) + 0.114 * ($c & 0xFF);

                $isDark = $l < 140;

                if ($dark !== null && $isDark !== $dark) {
                    $n++;
                }

                $dark = $isDark;
            }

            if ($n > $best) {
                $best = $n;
            }
        }

        imagedestroy($img);

        return $best;
    }
}
