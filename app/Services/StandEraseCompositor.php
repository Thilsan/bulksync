<?php

namespace App\Services;

/**
 * Remove the hanger from a photograph without redrawing the garment.
 *
 * Photoroom's only object removal regenerates the whole picture. Measured
 * against a real pair, that took an Aigner horseshoe monogram down to 4% of its
 * original detail and reinvented the pattern as rings — in order to remove a
 * hanger occupying 1.25% of the frame. It also moves the garment inside the
 * frame, so nothing from its output can be pasted back onto the original.
 *
 * Three attempts at using it were made and all three failed for that reason:
 * a cropped strip came back with a whole small garment drawn into it; a
 * generous accept-band swallowed the chest print; a tight band still ghosted
 * the collar because the garment had shifted. The conclusion is that a
 * generative erase and an untouched photograph cannot be combined.
 *
 * So nothing generative is used here. The hanger is found by colour — it
 * resembles neither the backdrop nor the garment — and the gap it leaves is
 * filled from the pixels around it. That has three properties worth more than
 * cleverness: it costs no credit, it cannot move the garment, and every pixel
 * outside the hanger is the photograph that went in.
 *
 * The fill only has to be good enough for the cutout that follows. Most of a
 * hanger sits on backdrop, and the backdrop is about to be replaced with white
 * regardless — so what matters is the small part that overlapped the collar,
 * where there is real fabric on both sides to fill from.
 */
class StandEraseCompositor
{
    /**
     * How far down the photo to look, when nobody says otherwise.
     *
     * A stand hangs from above, and looking further than necessary risks
     * finding a chest print instead: on the shot this was built against the
     * hanger ended at 25% and the print began at 28%. Tight by default.
     */
    public const DEFAULT_BAND = 0.27;

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
     * Erase whatever is holding the garment up, in the top $band of the photo.
     *
     * Returns the original bytes untouched when nothing stand-like is found —
     * a photo shot flat has nothing to erase, and re-encoding it would only
     * cost it a generation of JPEG for no reason.
     */
    public function erase(string $imageContent, float $band = self::DEFAULT_BAND): string
    {
        $img = @imagecreatefromstring($imageContent);

        if (!$img) {
            throw new \RuntimeException('Could not read the image while erasing the stand.');
        }

        $w    = imagesx($img);
        $h    = imagesy($img);
        $rows = max(1, (int) round($h * max(0.05, min(0.9, $band))));

        $backdrop = $this->sample($img, [[5, 5], [$w - 6, 5], [30, 30], [$w - 31, 30]]);

        /*
         * The garment's own colour, read from a grid across it and taken as a
         * median rather than a mean. A single reading — or an average — can
         * land on a chest print and come back dark, and a dark reference makes
         * the stand look like the garment, so nothing gets erased at all. The
         * median ignores a print unless the print covers most of the
         * garment — hence forty-two readings rather than a handful.
         */
        $grid = [];
        foreach ([0.38, 0.46, 0.54, 0.62, 0.70, 0.78, 0.86] as $fy) {
            foreach ([0.20, 0.32, 0.44, 0.56, 0.68, 0.80] as $fx) {
                $grid[] = [(int) ($w * $fx), (int) ($h * $fy)];
            }
        }

        $garment = $this->median($img, $grid);

        $mask = $this->maskStand($img, $w, $rows, $backdrop, $garment);

        if (!str_contains($mask, "\1")) {
            imagedestroy($img);

            return $imageContent;
        }

        $filled = $this->fill($img, $mask, $w, $rows);
        $this->smooth($img, $filled, $w, $rows);
        $this->settleToBackdrop($img, $filled, $w, $backdrop);

        ob_start();
        imagejpeg($img, null, 96);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    /**
     * Snap filled pixels that are nearly the backdrop onto it exactly.
     *
     * Without this the erase looks right and then fails at the next step. The
     * fill blends from its surroundings, so where a hanger sat against the
     * backdrop it comes back close to that colour but not equal to it — 14
     * levels away, on the shot this was built against. Photoroom's background
     * removal then reads that patch as part of the subject and keeps it, and a
     * pale hanger-shaped silhouette survives into the finished image.
     *
     * Only pixels already close to the backdrop are moved, and only ones the
     * fill wrote. Where the hanger crossed the collar the fill carries fabric
     * colour, which is nowhere near the backdrop and is left alone.
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
     * A string rather than a nested array: at 2400 x 810 that is two million
     * entries, and PHP arrays cost enough per element to exhaust a gigabyte.
     *
     * Two reference colours, not one. A hanger is not the backdrop, but neither
     * is the garment — asking only "is this the backdrop?" masks the shoulders
     * along with the hanger.
     */
    private function maskStand($img, int $w, int $rows, array $backdrop, array $garment): string
    {
        [$br, $bg, $bb] = $backdrop;
        [$gr, $gg, $gb] = $garment;

        $t    = self::DISTANCE ** 2;
        $mask = str_repeat("\0", $w * $rows);

        for ($y = 0; $y < $rows; $y++) {
            for ($x = 0; $x < $w; $x++) {
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

        return $this->grow($mask, $w, $rows);
    }

    private function grow(string $mask, int $w, int $rows): string
    {
        for ($pass = 0; $pass < self::GROW; $pass++) {
            $next = $mask;

            for ($y = 0; $y < $rows; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    if ($mask[$y * $w + $x] !== "\1") {
                        continue;
                    }

                    if ($x > 0)         $next[$y * $w + $x - 1] = "\1";
                    if ($x < $w - 1)    $next[$y * $w + $x + 1] = "\1";
                    if ($y > 0)         $next[($y - 1) * $w + $x] = "\1";
                    if ($y < $rows - 1) $next[($y + 1) * $w + $x] = "\1";
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
    private function fill($img, string $mask, int $w, int $rows): array
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

        for ($y = 0; $y < $rows; $y++) {
            $line = [];
            for ($x = 0; $x < $w; $x++) {
                $line[] = [$x, $y];
            }
            $sweep($line);
            $sweep(array_reverse($line));
        }

        for ($x = 0; $x < $w; $x++) {
            $line = [];
            for ($y = 0; $y < $rows; $y++) {
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
    private function smooth($img, array $filled, int $w, int $rows): void
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

                        if ($nx < 0 || $nx >= $w || $ny < 0 || $ny >= $rows) {
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
