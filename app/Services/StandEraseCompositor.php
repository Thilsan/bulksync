<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Erase the hanger without redrawing the garment.
 *
 * Photoroom's only object removal regenerates the whole picture, which on a
 * printed garment is destructive rather than merely soft: an Aigner monogram
 * came back as rings and dots, measured at 4% of the original's print detail.
 * The hanger it was asked to remove was 1.25% of the frame.
 *
 * So the generative pass is still used — nothing else can invent what was
 * behind a hanger — but only its answer to that 1.25% is kept:
 *
 *   1. Cut the strip of the photo the stand occupies.
 *   2. Send only that strip to be erased.
 *   3. Keep the erased pixels only where they actually differ from the
 *      original, feathered at the edges.
 *
 * Everywhere the strip came back unchanged — the collar, the shoulders, any
 * print inside the strip — the original pixels are kept. That is also what
 * removes the seam: the join runs through pixels that did not change, so there
 * is nothing to join. And it makes over-cutting safe, which matters because
 * guessing the strip too small would leave part of a hanger behind.
 */
class StandEraseCompositor
{
    /** Below this, two pixels are the same pixel with compression noise on top. */
    private const DEFAULT_TOLERANCE = 22;

    /**
     * The mask is built small and then enlarged. It is a soft, blurred shape,
     * so full resolution buys nothing and costs a per-pixel pass — and the
     * blur that feathers the edge also spreads it outward, which is the
     * dilation needed to catch a hanger's own anti-aliased outline.
     */
    private const MASK_EDGE = 420;

    /** How far the mask spreads past what changed, as a share of MASK_EDGE. */
    private const FEATHER = 0.012;

    /**
     * The top strip of a photo, as JPEG bytes, plus its height.
     *
     * A stand hangs from above, so the strip is measured from the top. Nothing
     * detects where the hanger ends: a wrong guess that is too generous costs
     * nothing, and one that is too tight leaves a hook in shot.
     *
     * @return array{bytes: string, height: int, width: int}
     */
    public function topStrip(string $imageContent, float $fraction): array
    {
        $src = $this->decode($imageContent);

        $width  = imagesx($src);
        $height = imagesy($src);
        $strip  = max(1, (int) round($height * max(0.05, min(0.9, $fraction))));

        $out = imagecreatetruecolor($width, $strip);
        imagecopy($out, $src, 0, 0, 0, 0, $width, $strip);
        imagedestroy($src);

        ob_start();
        imagejpeg($out, null, 96);
        $bytes = (string) ob_get_clean();
        imagedestroy($out);

        return ['bytes' => $bytes, 'height' => $strip, 'width' => $width];
    }

    /**
     * Put the erased strip back, keeping the original wherever it agrees.
     *
     * @param  string  $original      the whole photo, untouched
     * @param  string  $erasedStrip   the same strip after erasing, any scale
     * @param  int     $stripHeight   the strip's height in the original
     */
    public function blend(
        string $original,
        string $erasedStrip,
        int $stripHeight,
        ?int $tolerance = null,
    ): string {
        $tolerance = $tolerance ?? self::DEFAULT_TOLERANCE;

        $full = $this->decode($original);
        $w    = imagesx($full);
        $h    = imagesy($full);
        $strip = max(1, min($stripHeight, $h));

        // The erase may come back at a different size; it has to line up with
        // the strip it replaces before anything is compared.
        $erased = $this->decode($erasedStrip);

        if (imagesx($erased) !== $w || imagesy($erased) !== $strip) {
            $scaled = imagecreatetruecolor($w, $strip);
            imagecopyresampled($scaled, $erased, 0, 0, 0, 0, $w, $strip, imagesx($erased), imagesy($erased));
            imagedestroy($erased);
            $erased = $scaled;
        }

        $mask = $this->changeMask($full, $erased, $w, $strip, $tolerance);

        // The one full-resolution pass. Only over the strip — the rest of the
        // photograph is never read, let alone written.
        for ($y = 0; $y < $strip; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $m = imagecolorat($mask, $x, $y) & 0xFF;

                if ($m === 0) {
                    continue;               // unchanged: the original stands
                }

                $o = imagecolorat($full, $x, $y);
                $e = imagecolorat($erased, $x, $y);

                if ($m === 255) {
                    imagesetpixel($full, $x, $y, $e);
                    continue;
                }

                $a = $m / 255;
                imagesetpixel($full, $x, $y, (
                    ((int) ((($o >> 16) & 0xFF) * (1 - $a) + (($e >> 16) & 0xFF) * $a) << 16) |
                    ((int) ((($o >> 8) & 0xFF) * (1 - $a) + (($e >> 8) & 0xFF) * $a) << 8) |
                     (int) (($o & 0xFF) * (1 - $a) + ($e & 0xFF) * $a)
                ));
            }
        }

        imagedestroy($erased);
        imagedestroy($mask);

        ob_start();
        imagejpeg($full, null, 98);
        $bytes = (string) ob_get_clean();
        imagedestroy($full);

        return $bytes;
    }

    /**
     * Where the erase actually changed something, as a soft grayscale mask at
     * the strip's own size.
     */
    private function changeMask($full, $erased, int $w, int $strip, int $tolerance)
    {
        $sw = max(32, (int) round($w * (self::MASK_EDGE / max($w, $strip))));
        $sh = max(32, (int) round($strip * (self::MASK_EDGE / max($w, $strip))));

        $a = imagecreatetruecolor($sw, $sh);
        $b = imagecreatetruecolor($sw, $sh);
        imagecopyresampled($a, $full,   0, 0, 0, 0, $sw, $sh, $w, $strip);
        imagecopyresampled($b, $erased, 0, 0, 0, 0, $sw, $sh, $w, $strip);

        $small = imagecreatetruecolor($sw, $sh);

        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x++) {
                $p = imagecolorat($a, $x, $y);
                $q = imagecolorat($b, $x, $y);

                $d = max(
                    abs((($p >> 16) & 0xFF) - (($q >> 16) & 0xFF)),
                    abs((($p >> 8) & 0xFF)  - (($q >> 8) & 0xFF)),
                    abs(($p & 0xFF)         - ($q & 0xFF)),
                );

                $v = $d > $tolerance ? 255 : 0;
                imagesetpixel($small, $x, $y, ($v << 16) | ($v << 8) | $v);
            }
        }

        imagedestroy($a);
        imagedestroy($b);

        /*
         * Blur spreads the mask outwards as well as softening it, which is
         * exactly what is wanted: a hanger's edge is anti-aliased against the
         * backdrop, and a mask that stopped at the hard threshold would leave a
         * faint outline of it behind.
         */
        $passes = max(1, (int) round(self::FEATHER * self::MASK_EDGE));

        for ($i = 0; $i < $passes; $i++) {
            imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
        }

        $mask = imagecreatetruecolor($w, $strip);
        imagecopyresampled($mask, $small, 0, 0, 0, 0, $w, $strip, $sw, $sh);
        imagedestroy($small);

        return $mask;
    }

    private function decode(string $bytes)
    {
        $img = @imagecreatefromstring($bytes);

        if (!$img) {
            throw new \RuntimeException('Could not read the image while erasing the stand.');
        }

        return $img;
    }
}
