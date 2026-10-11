<?php

namespace Tests\Unit;

use App\Services\ImageProcessingService;
use Tests\TestCase;

/**
 * A kept redraw is given the photograph's own colour cast.
 *
 * Ghost Mannequin re-renders the garment under its own neutral lighting
 * instead of editing the picture, so a redraw arrives without whatever the
 * studio was doing that day — while the plain cutouts it will sit beside still
 * carry it. Measured on a grey satin robe: the backdrop, the white mannequin
 * and the fabric all sat about nine points warmer in red than in blue, which
 * is a white balance rather than a mauve fabric, and the redraw came back with
 * red and blue equal. Published as it was, one SKU would show the robe mauve
 * in one frame and grey in the next.
 *
 * This does not decide which rendering is truer. It makes the redraw agree
 * with the photographs around it.
 */
class ColourCastMatchingTest extends TestCase
{
    private function image(int $r, int $g, int $b, int $size = 200): string
    {
        $img = imagecreatetruecolor($size, $size);
        imagefilledrectangle($img, 0, 0, $size, $size, imagecolorallocate($img, $r, $g, $b));

        ob_start();
        imagepng($img);
        $out = (string) ob_get_clean();
        imagedestroy($img);

        return $out;
    }

    /** @return array{0:float,1:float,2:float} */
    private function means(string $png): array
    {
        $img = imagecreatefromstring($png);
        $w   = imagesx($img);
        $h   = imagesy($img);
        $s   = [0, 0, 0];

        for ($y = 0; $y < $h; $y += 4) {
            for ($x = 0; $x < $w; $x += 4) {
                $c = imagecolorat($img, $x, $y);
                $s[0] += ($c >> 16) & 0xFF;
                $s[1] += ($c >> 8) & 0xFF;
                $s[2] += $c & 0xFF;
            }
        }

        imagedestroy($img);
        $n = (int) (ceil($w / 4) * ceil($h / 4));

        return [$s[0] / $n, $s[1] / $n, $s[2] / $n];
    }

    public function test_a_neutral_redraw_takes_the_photographs_warmth(): void
    {
        $service = app(ImageProcessingService::class);

        // The robe's own numbers: a warm original, a neutral redraw.
        $original = $this->image(188, 183, 179);
        $redraw   = $this->image(183, 183, 183);

        [$r, $g, $b] = $this->means($service->matchColourCast($redraw, $original));

        $this->assertEqualsWithDelta(8.3, $r - $b, 1.5,
            'the photograph is nine points warmer in red and the redraw did not follow it');
    }

    /** Balance moves; brightness does not. */
    public function test_a_darker_redraw_stays_darker(): void
    {
        $service = app(ImageProcessingService::class);

        $original = $this->image(188, 183, 179);
        $redraw   = $this->image(120, 120, 120);

        $before = $this->means($redraw);
        $after  = $this->means($service->matchColourCast($redraw, $original));

        $this->assertEqualsWithDelta(
            array_sum($before) / 3,
            array_sum($after) / 3,
            1.5,
            'the correction changed how light the redraw is, not just its colour',
        );

        $this->assertGreaterThan(2.0, $after[0] - $after[2], 'and it took none of the warmth');
    }

    /**
     * A photograph with no cast leaves the redraw alone.
     *
     * Under one per cent is a camera doing its job, and correcting it would be
     * noise chasing noise.
     */
    public function test_a_neutral_photograph_changes_nothing(): void
    {
        $service = app(ImageProcessingService::class);

        $redraw = $this->image(140, 140, 140);

        $this->assertSame($redraw, $service->matchColourCast($redraw, $this->image(200, 200, 200)));
    }

    /** A shift large enough to suggest a bad measurement is refused outright. */
    public function test_an_implausible_correction_is_refused(): void
    {
        $service = app(ImageProcessingService::class);

        // A deep blue "photograph" would drag a grey redraw a very long way.
        $this->assertSame(
            $redraw = $this->image(150, 150, 150),
            $service->matchColourCast($redraw, $this->image(40, 60, 220)),
        );
    }

    public function test_an_unreadable_file_comes_back_untouched(): void
    {
        $service = app(ImageProcessingService::class);

        $this->assertSame('not an image', $service->matchColourCast('not an image', $this->image(188, 183, 179)));
    }
}
