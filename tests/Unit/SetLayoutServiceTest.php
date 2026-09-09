<?php

namespace Tests\Unit;

use App\Services\SetLayoutService;
use Tests\TestCase;

/**
 * Two garments, one canvas.
 *
 * The property that matters most is not that a single set looks right — it is
 * that every set lands in the same place. A collection page shows these side by
 * side, and one outfit sitting high beside another sitting low is what reads as
 * amateur, however good each looks alone. So the test that earns its keep is
 * the one feeding the same garments in at different resolutions and demanding
 * identical placement.
 */
class SetLayoutServiceTest extends TestCase
{
    private SetLayoutService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SetLayoutService();
    }

    public function test_the_top_sits_above_the_bottom_and_both_are_centred(): void
    {
        $result = $this->service->compose($this->top(), $this->bottom(), 2000);

        $top    = $result['metrics']['top_placed'];
        $bottom = $result['metrics']['bottom_placed'];

        $this->assertLessThan(
            $bottom['y'],
            $top['y'] + $top['h'],
            'the top and the bottom overlap; the layout leaves a gap between them',
        );

        foreach (['top' => $top, 'bottom' => $bottom] as $which => $p) {
            $centre = $p['x'] + $p['w'] / 2;

            $this->assertEqualsWithDelta(
                1000,
                $centre,
                1.5,
                "the {$which} is not centred on the canvas",
            );
        }
    }

    /**
     * The whole point of a shared layout: the same garments land in the same
     * place whatever the supplier's photographs happened to measure.
     *
     * The tolerances are the measurement's own precision, not round numbers the
     * assertion happened to pass at. The garment's box is found on a 320-pixel
     * proxy and rounded outwards so a sleeve cannot be clipped, so each edge is
     * exact to within one proxy pixel — about six once fitted onto a
     * 2000-pixel canvas.
     *
     * A position depends on one such edge and a size on two, so a size may
     * drift twice as far as a position. Anything inside that is quantisation;
     * anything outside it is the layout genuinely depending on the source,
     * which is the thing being ruled out.
     */
    public function test_placement_does_not_depend_on_the_source_resolution(): void
    {
        $small = $this->service->compose($this->top(1.0), $this->bottom(1.0), 2000);
        $large = $this->service->compose($this->top(2.0), $this->bottom(2.0), 2000);

        $edge = 2000 / 320;

        $tolerance = ['x' => $edge + 1, 'y' => $edge + 1, 'w' => 2 * $edge + 1, 'h' => 2 * $edge + 1];

        foreach (['top_placed', 'bottom_placed'] as $key) {
            foreach ($tolerance as $field => $delta) {
                $this->assertEqualsWithDelta(
                    $small['metrics'][$key][$field],
                    $large['metrics'][$key][$field],
                    $delta,
                    "{$key}'s {$field} moved when the source was photographed larger",
                );
            }
        }
    }

    /**
     * A long-sleeved top is wider than it is tall. Filling its band's height
     * would run it off the sides, so the width has to win — and then the piece
     * is centred in the band rather than hung from the top of it.
     */
    public function test_a_wide_top_gives_up_height_rather_than_overflowing(): void
    {
        $result = $this->service->compose($this->wideTop(), $this->bottom(), 2000);

        $top = $result['metrics']['top_placed'];

        $this->assertLessThanOrEqual(2000, $top['x'] + $top['w'], 'the top runs off the canvas');
        $this->assertGreaterThanOrEqual(0, $top['x']);

        // Width-limited, so it cannot have filled the band's full height.
        // The share is the measured one from Kids/dress; see SetLayoutService.
        $band = 0.412 * (1 - 2 * 0.10) * 2000;

        $this->assertLessThan($band, $top['h'], 'the wide top was stretched to its band instead of fitted');
    }

    public function test_nothing_is_drawn_outside_the_canvas(): void
    {
        $result = $this->service->compose($this->top(), $this->bottom(), 1200);

        foreach (['top_placed', 'bottom_placed'] as $key) {
            $p = $result['metrics'][$key];

            $this->assertGreaterThanOrEqual(0, $p['x']);
            $this->assertGreaterThanOrEqual(0, $p['y']);
            $this->assertLessThanOrEqual(1200, $p['x'] + $p['w']);
            $this->assertLessThanOrEqual(1200, $p['y'] + $p['h']);
        }
    }

    public function test_it_refuses_an_image_it_cannot_read(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not read the bottom image.');

        $this->service->compose($this->top(), 'not an image', 2000);
    }

    /**
     * A photograph still on its background is not a cutout, and this cannot
     * tell a pale garment from the ground it was shot on — so it says so
     * rather than laying out an empty rectangle.
     */
    public function test_it_refuses_a_piece_with_no_findable_garment(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No garment found in the top image');

        $this->service->compose($this->blank(), $this->bottom(), 2000);
    }

    // ── Fixtures ───────────────────────────────────────────────────────────

    /** A long-sleeved top, cut out: body with sleeves spreading either side. */
    private function top(float $scale = 1.0): string
    {
        return $this->cutout(1400, 1000, $scale, function (\GdImage $i, float $s) {
            $g = imagecolorallocate($i, 150, 150, 152);

            $this->box($i, [420, 200, 980, 820], $s, $g);
            $this->box($i, [80, 240, 420, 470], $s, $g);
            $this->box($i, [980, 240, 1320, 470], $s, $g);
        });
    }

    /** Sleeves so wide the piece is limited by the canvas, not its band. */
    private function wideTop(): string
    {
        return $this->cutout(2000, 700, 1.0, function (\GdImage $i, float $s) {
            $g = imagecolorallocate($i, 150, 150, 152);

            $this->box($i, [40, 100, 1960, 420], $s, $g);
        });
    }

    /** A trouser flat-lay: waist above, two legs below. */
    private function bottom(float $scale = 1.0): string
    {
        return $this->cutout(1000, 1600, $scale, function (\GdImage $i, float $s) {
            $g = imagecolorallocate($i, 150, 150, 152);

            $this->box($i, [150, 120, 850, 540], $s, $g);
            $this->box($i, [150, 540, 470, 1470], $s, $g);
            $this->box($i, [530, 540, 850, 1470], $s, $g);
        });
    }

    /** Transparent throughout: a cutout of nothing. */
    private function blank(): string
    {
        return $this->cutout(800, 800, 1.0, function () {});
    }

    private function cutout(int $w, int $h, float $scale, callable $draw): string
    {
        $img = imagecreatetruecolor((int) round($w * $scale), (int) round($h * $scale));

        imagesavealpha($img, true);
        imagealphablending($img, false);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagealphablending($img, true);

        $draw($img, $scale);

        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    /** @param array{0:int,1:int,2:int,3:int} $r */
    private function box(\GdImage $img, array $r, float $s, int $colour): void
    {
        imagefilledrectangle(
            $img,
            (int) round($r[0] * $s),
            (int) round($r[1] * $s),
            (int) round($r[2] * $s),
            (int) round($r[3] * $s),
            $colour,
        );
    }
}
