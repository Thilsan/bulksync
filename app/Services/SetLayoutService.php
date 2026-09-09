<?php

namespace App\Services;

/**
 * Lay a top and a bottom out as one two-piece product image.
 *
 * ── Why this is not a Photoroom call ───────────────────────────────────────
 *
 * It cannot be. Every endpoint of the Image Editing API takes one imageFile per
 * request — Flat Lay documents exactly three parameters (mode, prompt, size)
 * and no second garment — and the only multi-image field anywhere in the API is
 * virtualModel.additionalProductImages, which PhotoroomService already records
 * is for more angles of the *same* garment.
 *
 * That is no loss, because this is not a job for a generative model at all. A
 * set image is two cutouts placed on one canvas: arithmetic, not invention.
 * Asking a model to arrange an outfit means asking it to redraw both garments,
 * and this project has now measured three times over what that costs — an
 * Aigner monogram replaced by a dot grid, a woven label rewritten as gibberish,
 * a shirt re-posed into a three-quarter view. None of that can happen here,
 * because nothing is drawn: the pixels that go out are the pixels that came in.
 *
 * ── How the two are placed ─────────────────────────────────────────────────
 *
 * The same principle as FRAMING_PRESETS, for the same reason: a collection page
 * shows these side by side, so what makes them read as one brand is every set
 * landing on the same spot. The canvas is divided once — padding, the top's
 * band, the gap, the bottom's band — and both garments are fitted into their
 * bands rather than placed relative to each other. A long-sleeved top and a
 * cropped one then hang from the same line.
 */
class SetLayoutService
{
    /**
     * At or above this on every channel is background, not garment.
     *
     * The same threshold the rest of the pipeline uses. Cutouts usually arrive
     * with an alpha channel, which is exact and is preferred when it is there;
     * this is the fallback for one already flattened onto white.
     */
    private const BACKGROUND_WHITE = 252;

    /**
     * The layout, as fractions of the canvas.
     *
     * ── Measured, not judged ──────────────────────────────────────────────
     *
     * Off Kids/dress on a 1200 square: Combo.webp for the split, and the two
     * finished single-dress frames beside it for the baseline. Background taken
     * from the corners and everything more than 6 levels from it counted as
     * product, which is what it takes to find a white legging on white.
     *
     * The baseline is the same in all three, which is the point — a collection
     * page shows sets and single garments together, so a set has to hang from
     * the line the singles hang from:
     *
     *   combo    top edge 10.00%   bottom edge 90.33%
     *   dress A  top edge 10.08%   bottom edge 90.00%
     *   dress B  top edge 10.00%   bottom edge 90.17%
     *
     * So the product block is 10% to 90% and PAD_Y is 10%, confirmed three
     * times over rather than chosen.
     *
     * The split inside that block, from the combo:
     *
     *   top     10.00% .. 43.00% of the canvas   (33.00% tall)
     *   gap     43.00% .. 44.25%                 ( 1.25%, 15 px)
     *   bottom  44.25% .. 90.33%                 (46.08% tall)
     *
     * The gap is the correction worth recording. It was first read off a
     * screenshot as 12% of the block and it is 1.6% — eight times too much. The
     * pieces very nearly touch, and a set laid out with a visible channel
     * between them does not look like the reference at all.
     *
     * Side padding is deliberately not pinned. It measured 31.3% on the combo,
     * 11.1% on dress A and 22.3% on dress B — it follows each garment's own
     * shape, because what is held constant is the height. MAX_WIDTH is only a
     * cap so a spread sleeve cannot leave the canvas.
     */

    /**
     * Empty canvas above the top and below the bottom.
     *
     * Ten per cent both sides, deliberately, and not the combo's own 9.67%
     * bottom taken literally. The four figures in play are all the same line:
     *
     *   combo    bottom edge 90.33%
     *   dress B  bottom edge 90.17%
     *   dress A  bottom edge 90.00%
     *   this     bottom edge 89.85%
     *
     * The whole spread is under half a percent — ten pixels on a 2000 canvas —
     * and it is smaller than the difference between the two dresses. Following
     * the combo's 90.33% exactly would move a set six pixels away from where
     * both dresses actually sit rather than onto it, and would contradict what
     * FRAMING_PRESETS already records for kids dresses from two measured
     * frames. Ten per cent is the line all four are measuring, so ten per cent
     * is what is pinned.
     */
    private const PAD_Y = 0.10;

    /** Widest either piece may be, so a spread sleeve cannot run off the canvas. */
    private const MAX_WIDTH = 0.80;

    /*
     * How the block between the paddings is divided. Shares of that block, not
     * of the canvas, so a change to PAD_Y cannot silently re-proportion the
     * garments. Normalised from the measurements above to sum to exactly 1 —
     * the reference's own bottom padding came out at 9.67% rather than 10%, and
     * spreading that third of a percent across the three shares is a difference
     * of about seven pixels on a 2000 canvas.
     */
    private const TOP_SHARE    = 0.412;
    private const GAP_SHARE    = 0.016;
    private const BOTTOM_SHARE = 0.572;

    /**
     * Compose one set image.
     *
     * @param  string  $top     the upper garment, already cut out
     * @param  string  $bottom  the lower garment, already cut out
     * @param  int     $canvas  edge of the square output
     *
     * @return array{image: string, metrics: array}
     *
     * @throws \RuntimeException  when either piece cannot be read, or has no
     *                            findable garment in it
     */
    public function compose(string $top, string $bottom, int $canvas = 2000): array
    {
        $upper = $this->decode($top, 'top');

        try {
            $lower = $this->decode($bottom, 'bottom');

            try {
                $upperBox = $this->subjectBox($upper, 'top');
                $lowerBox = $this->subjectBox($lower, 'bottom');

                $out = imagecreatetruecolor($canvas, $canvas);
                imagealphablending($out, true);
                imagefilledrectangle(
                    $out,
                    0,
                    0,
                    $canvas - 1,
                    $canvas - 1,
                    imagecolorallocate($out, 255, 255, 255),
                );

                $block = (1 - 2 * self::PAD_Y) * $canvas;

                // Where each band begins and how tall it is, in pixels.
                $upperBand = self::PAD_Y * $canvas;
                $upperTall = self::TOP_SHARE * $block;

                $lowerBand = $upperBand + $upperTall + self::GAP_SHARE * $block;
                $lowerTall = self::BOTTOM_SHARE * $block;

                $placedUpper = $this->place($out, $upper, $upperBox, $upperBand, $upperTall, $canvas);
                $placedLower = $this->place($out, $lower, $lowerBox, $lowerBand, $lowerTall, $canvas);

                return [
                    'image'   => $this->encode($out),
                    'metrics' => [
                        'canvas'      => $canvas . 'x' . $canvas,
                        'top_placed'  => $placedUpper,
                        'bottom_placed' => $placedLower,
                        'gap_px'      => (int) round(self::GAP_SHARE * $block),
                    ],
                ];
            } finally {
                imagedestroy($lower);
            }
        } finally {
            imagedestroy($upper);
        }
    }

    /**
     * Fit one garment into its band and draw it, returning where it landed.
     *
     * Fitted rather than stretched: the scale is whichever of the two limits
     * bites first, so a wide-sleeved top gives up height rather than running
     * off the sides. When the width is what limits it the garment is shorter
     * than its band, and it is then centred in that band — hanging it from the
     * top of the band instead would leave one set sitting high and the next
     * low, which is the very thing a shared layout exists to prevent.
     *
     * @param  array{0:int,1:int,2:int,3:int}  $box
     * @return array{x:int,y:int,w:int,h:int}
     */
    private function place(
        \GdImage $canvas,
        \GdImage $piece,
        array $box,
        float $bandTop,
        float $bandHeight,
        int $edge,
    ): array {
        [$x0, $y0, $x1, $y1] = $box;

        $w = $x1 - $x0 + 1;
        $h = $y1 - $y0 + 1;

        $scale = min($bandHeight / max(1, $h), (self::MAX_WIDTH * $edge) / max(1, $w));

        $dw = max(1, (int) round($w * $scale));
        $dh = max(1, (int) round($h * $scale));

        $dx = (int) round(($edge - $dw) / 2);
        $dy = (int) round($bandTop + ($bandHeight - $dh) / 2);

        imagecopyresampled($canvas, $piece, $dx, $dy, $x0, $y0, $dw, $dh, $w, $h);

        return ['x' => $dx, 'y' => $dy, 'w' => $dw, 'h' => $dh];
    }

    // ── Reading ────────────────────────────────────────────────────────────

    /**
     * Bytes to an image, with any transparency kept.
     *
     * Kept rather than flattened, unlike everywhere else in this pipeline: a
     * cutout's alpha is the exact answer to where the garment is, and these
     * garments are the ones where guessing from colour fails — a cream top on
     * white is the case that defeats a brightness threshold.
     */
    private function decode(string $bytes, string $which): \GdImage
    {
        $img = @imagecreatefromstring($bytes);

        if (!$img) {
            throw new \RuntimeException("Could not read the {$which} image.");
        }

        imagealphablending($img, true);

        return $img;
    }

    /**
     * The garment's bounding box: from the alpha channel when there is one, and
     * from brightness otherwise.
     *
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function subjectBox(\GdImage $img, string $which): array
    {
        $w = imagesx($img);
        $h = imagesy($img);

        // Measured on a small copy — the box only has to be right to a few
        // pixels, and it is about to be scaled anyway.
        $edge  = 320;
        $scale = min(1.0, $edge / max($w, $h));

        $proxy = $scale < 1.0
            ? imagescale($img, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)))
            : $img;

        if ($proxy === false) {
            throw new \RuntimeException("Could not scale the {$which} image for analysis.");
        }

        $pw = imagesx($proxy);
        $ph = imagesy($proxy);

        $minX = $pw; $minY = $ph; $maxX = -1; $maxY = -1;

        for ($y = 0; $y < $ph; $y++) {
            for ($x = 0; $x < $pw; $x++) {
                $c = imagecolorat($proxy, $x, $y);

                // Transparent is background, whatever colour hides behind it.
                if ((($c >> 24) & 0x7F) > 100) {
                    continue;
                }

                if ((($c >> 16) & 0xFF) >= self::BACKGROUND_WHITE
                    && (($c >> 8) & 0xFF) >= self::BACKGROUND_WHITE
                    && ($c & 0xFF) >= self::BACKGROUND_WHITE) {
                    continue;
                }

                if ($x < $minX) $minX = $x;
                if ($x > $maxX) $maxX = $x;
                if ($y < $minY) $minY = $y;
                if ($y > $maxY) $maxY = $y;
            }
        }

        if ($proxy !== $img) {
            imagedestroy($proxy);
        }

        if ($maxX < 0) {
            throw new \RuntimeException(
                "No garment found in the {$which} image. A cutout is expected here — one already "
                . 'flattened onto a background this cannot tell from the garment will read as empty.'
            );
        }

        // Back to the image's own scale, rounded outwards so the box can only
        // grow with the conversion and never clip a sleeve.
        $sx = $w / $pw;
        $sy = $h / $ph;

        return [
            (int) floor($minX * $sx),
            (int) floor($minY * $sy),
            (int) min($w - 1, ceil(($maxX + 1) * $sx)),
            (int) min($h - 1, ceil(($maxY + 1) * $sy)),
        ];
    }

    private function encode(\GdImage $img): string
    {
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }
}
