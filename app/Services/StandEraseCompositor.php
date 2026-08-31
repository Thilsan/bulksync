<?php

namespace App\Services;

/**
 * Remove the stand from a photograph without redrawing the garment.
 *
 * Taking an object out of a photo is two jobs, and they fail in different ways.
 *
 * Finding it is the first. This class used to guess by colour inside a band
 * across the top: anything that was neither the backdrop nor the garment. That
 * works on a dark hanger against a pale wall and fails on a white plastic one,
 * a clear acrylic one, or a black rail behind a black dress — and a band set
 * too low reads a chest print as part of the stand. So finding it is no longer
 * guessed at here: Gemini is asked where the stand is, because it knows what a
 * hanger is rather than what one happens to be coloured, and it hands back
 * boxes. See GeminiService::locateStand.
 *
 * Filling it is the second, and it stays here, where full resolution is free.
 * Inside each box the stand's own pixels are separated from the backdrop and
 * the garment, the gap is filled from its surroundings, and anything that ends
 * up near the backdrop is snapped onto it exactly so the cutout that follows
 * takes it away.
 *
 * Nothing outside the boxes is ever written to, which is the property that
 * matters: a print cannot be reinvented by a process that never touches it.
 * Photoroom's own erase regenerates the whole picture and took an Aigner
 * horseshoe monogram down to 4% of its original detail to remove a hanger
 * covering 1.25% of the frame.
 *
 * What this cannot do is invent fabric. Where the stand passed behind a collar
 * the camera never saw what was behind it, so the fill smears its neighbours
 * into the gap. On plain backdrop that is invisible; across a garment it is a
 * smudge, and no improvement to the finding fixes it.
 */
class StandEraseCompositor
{
    /**
     * How far a colour must sit from both the backdrop and the garment before
     * it is treated as part of the stand. Low enough to catch a pale hook,
     * high enough to leave studio lighting variation alone.
     */
    private const DISTANCE = 34;

    /** Pixels the mask grows by, to take the stand's anti-aliased rim with it. */
    private const GROW = 5;

    /** Smoothing passes over the filled area, to even out the fill's banding. */
    private const SMOOTH = 6;

    /**
     * How close to the backdrop a filled pixel has to be before it is snapped
     * onto it exactly. Generous enough to catch the fill's drift, tight enough
     * to leave fabric colour where the stand crossed the garment.
     */
    private const SETTLE = 26;

    /**
     * Grown outward from each box before anything is masked.
     *
     * A box is a rectangle drawn round an irregular object by a model working
     * from a shrunken copy, so its edges are approximate. Being a little
     * generous costs nothing — inside the box the stand still has to be told
     * apart from the backdrop and the garment, so extra area is simply extra
     * backdrop that nothing happens to.
     */
    private const BOX_MARGIN = 0.015;

    /**
     * Erase whatever is holding the garment up, inside the boxes given.
     *
     * @param  array<int, array{x0: float, y0: float, x1: float, y1: float}>  $boxes
     *         fractions of the image, from GeminiService::locateStand
     *
     * Returns the original bytes untouched when there are no boxes, or when
     * nothing inside them looks like a stand. Both mean "nothing to do", and
     * re-encoding would cost a JPEG generation for no reason.
     */
    public function erase(string $imageContent, array $boxes): string
    {
        if (!$boxes) {
            return $imageContent;
        }

        $img = @imagecreatefromstring($imageContent);

        if (!$img) {
            throw new \RuntimeException('Could not read the image while erasing the stand.');
        }

        $w = imagesx($img);
        $h = imagesy($img);

        $backdrop = $this->sample($img, [[5, 5], [$w - 6, 5], [30, 30], [$w - 31, 30]]);

        /*
         * The garment's own colour, read from a grid across it and taken as a
         * median rather than a mean. A single reading — or an average — can land
         * on a chest print and come back dark, and a dark reference makes the
         * stand look like the garment, so nothing gets erased at all. The median
         * ignores a print unless it covers most of the garment, hence forty-two
         * readings rather than a handful.
         */
        $grid = [];

        foreach ([0.38, 0.46, 0.54, 0.62, 0.70, 0.78, 0.86] as $fy) {
            foreach ([0.20, 0.32, 0.44, 0.56, 0.68, 0.80] as $fx) {
                $grid[] = [(int) ($w * $fx), (int) ($h * $fy)];
            }
        }

        $garment = $this->median($img, $grid);

        $mask = $this->maskStand($img, $w, $h, $this->pixelBoxes($boxes, $w, $h), $backdrop, $garment);

        if (!str_contains($mask, "\1")) {
            imagedestroy($img);

            return $imageContent;
        }

        $filled = $this->fill($img, $mask, $w, $h);
        $this->smooth($img, $filled, $w, $h);
        $this->settleToBackdrop($img, $filled, $w, $backdrop);

        ob_start();
        imagejpeg($img, null, 96);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    /**
     * The boxes as pixel rectangles, grown by the margin and clipped to the
     * image.
     *
     * @return array<int, array{0: int, 1: int, 2: int, 3: int}>  x0, y0, x1, y1
     */
    private function pixelBoxes(array $boxes, int $w, int $h): array
    {
        $out = [];

        foreach ($boxes as $box) {
            $x0 = (int) floor(($box['x0'] - self::BOX_MARGIN) * $w);
            $y0 = (int) floor(($box['y0'] - self::BOX_MARGIN) * $h);
            $x1 = (int) ceil(($box['x1'] + self::BOX_MARGIN) * $w);
            $y1 = (int) ceil(($box['y1'] + self::BOX_MARGIN) * $h);

            $out[] = [
                max(0, min($w - 1, $x0)),
                max(0, min($h - 1, $y0)),
                max(0, min($w - 1, $x1)),
                max(0, min($h - 1, $y1)),
            ];
        }

        return $out;
    }

    /**
     * Snap filled pixels that are nearly the backdrop onto it exactly.
     *
     * Without this the erase looks right and then fails at the next step. The
     * fill blends from its surroundings, so where a stand sat against the
     * backdrop it comes back close to that colour but not equal to it — 14
     * levels away, on the shot this was built against. Photoroom's background
     * removal then reads the patch as part of the subject and keeps it, and a
     * pale hanger-shaped silhouette survives into the finished image.
     *
     * Only pixels the fill wrote, and only ones already close to the backdrop.
     * Where the stand crossed the garment the fill carries fabric colour, which
     * is nowhere near the backdrop and is left alone.
     */
    private function settleToBackdrop($img, array $filled, int $w, array $backdrop): void
    {
        [$br, $bg, $bb] = $backdrop;

        $exact = ($br << 16) | ($bg << 8) | $bb;
        $near  = self::SETTLE ** 2;

        foreach ($filled as $i => $_) {
            $x = $i % $w;
            $y = intdiv($i, $w);
            $c = imagecolorat($img, $x, $y);

            $d = ((($c >> 16) & 0xFF) - $br) ** 2
               + ((($c >> 8) & 0xFF) - $bg) ** 2
               + (($c & 0xFF) - $bb) ** 2;

            if ($d <= $near) {
                imagesetpixel($img, $x, $y, $exact);
            }
        }
    }

    /** The average colour at a handful of points, as [r, g, b]. */
    private function sample($img, array $points): array
    {
        $r = $g = $b = 0;

        foreach ($points as [$x, $y]) {
            $c = imagecolorat($img, max(0, min(imagesx($img) - 1, $x)), max(0, min(imagesy($img) - 1, $y)));
            $r += ($c >> 16) & 0xFF;
            $g += ($c >> 8) & 0xFF;
            $b += $c & 0xFF;
        }

        $n = max(1, count($points));

        return [intdiv($r, $n), intdiv($g, $n), intdiv($b, $n)];
    }

    /** The median colour over a set of points, per channel. */
    private function median($img, array $points): array
    {
        $r = $g = $b = [];

        foreach ($points as [$x, $y]) {
            $c = imagecolorat(
                $img,
                max(0, min(imagesx($img) - 1, $x)),
                max(0, min(imagesy($img) - 1, $y)),
            );

            $r[] = ($c >> 16) & 0xFF;
            $g[] = ($c >> 8) & 0xFF;
            $b[] = $c & 0xFF;
        }

        return array_map(function (array $channel) {
            sort($channel);

            return (int) $channel[intdiv(count($channel), 2)];
        }, [$r, $g, $b]);
    }

    /**
     * Which pixels belong to the stand, as a byte per pixel.
     *
     * A string rather than a nested array: at 2400 x 3000 that is seven million
     * entries, and PHP arrays cost enough per element to exhaust a gigabyte.
     *
     * Only pixels inside a box are considered at all — that is what replaced
     * guessing a band across the top, and it is why a chest print is now safe
     * by construction rather than by choosing the band carefully.
     *
     * Inside a box, two reference colours rather than one. A hanger is not the
     * backdrop, but neither is the garment; asking only "is this the backdrop?"
     * would mask the shoulders along with the hanger where a box overlaps them.
     */
    private function maskStand($img, int $w, int $h, array $boxes, array $backdrop, array $garment): string
    {
        [$br, $bg, $bb] = $backdrop;
        [$gr, $gg, $gb] = $garment;

        $t    = self::DISTANCE ** 2;
        $mask = str_repeat("\0", $w * $h);

        foreach ($boxes as [$x0, $y0, $x1, $y1]) {
            for ($y = $y0; $y <= $y1; $y++) {
                for ($x = $x0; $x <= $x1; $x++) {
                    $c = imagecolorat($img, $x, $y);
                    $r = ($c >> 16) & 0xFF;
                    $g = ($c >> 8) & 0xFF;
                    $b = $c & 0xFF;

                    $fromBackdrop = ($r - $br) ** 2 + ($g - $bg) ** 2 + ($b - $bb) ** 2;
                    $fromGarment  = ($r - $gr) ** 2 + ($g - $gg) ** 2 + ($b - $gb) ** 2;

                    if ($fromBackdrop > $t && $fromGarment > $t) {
                        $mask[$y * $w + $x] = "\1";
                    }
                }
            }
        }

        return $this->grow($mask, $w, $h);
    }

    private function grow(string $mask, int $w, int $h): string
    {
        for ($pass = 0; $pass < self::GROW; $pass++) {
            $next = $mask;

            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    if ($mask[$y * $w + $x] !== "\1") {
                        continue;
                    }

                    if ($x > 0)         $next[$y * $w + $x - 1] = "\1";
                    if ($x < $w - 1)    $next[$y * $w + $x + 1] = "\1";
                    if ($y > 0)         $next[($y - 1) * $w + $x] = "\1";
                    if ($y < $h - 1) $next[($y + 1) * $w + $x] = "\1";
                }
            }

            $mask = $next;
        }

        return $mask;
    }

    /**
     * Fill the masked pixels from the nearest known pixel on each of the four
     * sides, weighted by inverse square distance.
     *
     * Four directions rather than two because a horizontal-only fill smears a
     * gap that crosses the shoulder into a flat band — the vertical
     * contributions carry the shoulder line and the backdrop's own gradient
     * through the gap instead.
     *
     * @return array<int, true>  the pixels written, keyed by y * w + x
     */
    private function fill($img, string $mask, int $w, int $h): array
    {
        $sumR = $sumG = $sumB = $weight = [];

        $sweep = function (array $line) use ($img, $mask, $w, &$sumR, &$sumG, &$sumB, &$weight) {
            $r = $g = $b = null;
            $away = 0;

            foreach ($line as [$x, $y]) {
                $i = $y * $w + $x;

                if ($mask[$i] !== "\1") {
                    $c = imagecolorat($img, $x, $y);
                    $r = ($c >> 16) & 0xFF;
                    $g = ($c >> 8) & 0xFF;
                    $b = $c & 0xFF;
                    $away = 0;

                    continue;
                }

                $away++;

                if ($r === null) {
                    continue;       // nothing known behind us yet on this line
                }

                $wt = 1 / ($away * $away);

                $sumR[$i]   = ($sumR[$i] ?? 0) + $r * $wt;
                $sumG[$i]   = ($sumG[$i] ?? 0) + $g * $wt;
                $sumB[$i]   = ($sumB[$i] ?? 0) + $b * $wt;
                $weight[$i] = ($weight[$i] ?? 0) + $wt;
            }
        };

        for ($y = 0; $y < $h; $y++) {
            $line = [];
            for ($x = 0; $x < $w; $x++) {
                $line[] = [$x, $y];
            }
            $sweep($line);
            $sweep(array_reverse($line));
        }

        for ($x = 0; $x < $w; $x++) {
            $line = [];
            for ($y = 0; $y < $h; $y++) {
                $line[] = [$x, $y];
            }
            $sweep($line);
            $sweep(array_reverse($line));
        }

        $written = [];

        foreach ($weight as $i => $total) {
            if ($total <= 0) {
                continue;
            }

            imagesetpixel($img, $i % $w, intdiv($i, $w), (
                ((int) ($sumR[$i] / $total) << 16) |
                ((int) ($sumG[$i] / $total) << 8) |
                 (int) ($sumB[$i] / $total)
            ));

            $written[$i] = true;
        }

        return $written;
    }

    /**
     * Even out the fill, writing only where the fill wrote.
     *
     * The sweeps leave faint vertical banding, because each column blends its
     * own pair of distances. Blurring settles it, and confining the blur to the
     * filled pixels is what makes it safe: the photograph is never a source of
     * a write, only of a read.
     */
    private function smooth($img, array $filled, int $w, int $h): void
    {
        for ($pass = 0; $pass < self::SMOOTH; $pass++) {
            $before = [];

            foreach ($filled as $i => $_) {
                $before[$i] = imagecolorat($img, $i % $w, intdiv($i, $w));
            }

            foreach ($filled as $i => $_) {
                $x = $i % $w;
                $y = intdiv($i, $w);
                $r = $g = $b = $n = 0;

                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $nx = $x + $dx;
                        $ny = $y + $dy;

                        if ($nx < 0 || $nx >= $w || $ny < 0 || $ny >= $h) {
                            continue;
                        }

                        $j = $ny * $w + $nx;
                        $c = $before[$j] ?? imagecolorat($img, $nx, $ny);

                        $r += ($c >> 16) & 0xFF;
                        $g += ($c >> 8) & 0xFF;
                        $b += $c & 0xFF;
                        $n++;
                    }
                }

                if ($n) {
                    imagesetpixel($img, $x, $y, (intdiv($r, $n) << 16) | (intdiv($g, $n) << 8) | intdiv($b, $n));
                }
            }
        }
    }
}
