<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Erase the stand without redrawing the garment.
 *
 * Photoroom's only object removal regenerates the whole picture, which on a
 * printed garment is destructive rather than merely soft: an Aigner monogram
 * came back as rings and dots, measured at 4% of the original's print detail,
 * to remove a hanger occupying 1.25% of the frame.
 *
 * So the generative pass still runs, on the whole photograph, and its answer is
 * accepted in one narrow band only:
 *
 *   1. The full photo is sent to be erased — not a crop.
 *   2. Its result is accepted only inside the top band the stand occupies.
 *   3. Within that band, only where it actually differs from the original.
 *
 * Both restrictions were learned the hard way and neither is optional.
 *
 * Sending a crop failed: the model cannot tell a crop from a photograph, saw a
 * partial garment and drew a small complete one to fill the frame, and a ghost
 * t-shirt was composited into the shoulders. It needs the whole picture to know
 * what it is looking at.
 *
 * A generous band failed too. Anything inside the band that the model changes
 * is accepted, and it changes prints — so a band reaching 40% down swallowed a
 * chest print sitting at 28%. The band must stop above the garment's detail,
 * which makes it a real decision rather than a safe over-estimate.
 *
 * What the difference test buys is the seam: the join runs through pixels that
 * did not change, so there is nothing to join.
 */
class StandEraseCompositor
{
    /** Below this, two pixels are the same pixel with compression noise on top. */
    private const DEFAULT_TOLERANCE = 22;

    /**
     * How far down the photo changes are accepted, when nobody says otherwise.
     *
     * Tight on purpose. A hanger's dark body ended at 25% on the shot this was
     * built against and the chest print began at 28%, so there is roughly three
     * percent of clearance between "removes the hanger" and "reinvents the
     * logo". The operator can move it; the default errs towards keeping the
     * garment rather than towards catching every last pixel of hook.
     */
    public const DEFAULT_BAND = 0.27;

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
     * Put the erased version back, in the top band only, where it differs.
     *
     * @param  string  $original  the whole photograph, untouched
     * @param  string  $erased    the whole photograph after erasing, any size
     * @param  float   $band      how far down to accept changes, 0–1
     */
    public function blend(
        string $original,
        string $erased,
        float $band,
        ?int $tolerance = null,
    ): string {
        $tolerance = $tolerance ?? self::DEFAULT_TOLERANCE;

        $full = $this->decode($original);
        $w    = imagesx($full);
        $h    = imagesy($full);
        $strip = max(1, (int) round($h * max(0.05, min(0.9, $band))));

        /*
         * The erase comes back at its own size — Photoroom returned 2333x3000
         * for a 2400x3000 input on the shot this was built against — so it is
         * stretched back onto the original's grid before anything is compared.
         * A few pixels of misalignment only widens the changed region slightly,
         * and the band already confines that to above the garment's detail.
         */
        $erasedImg = $this->decode($erased);

        /*
         * The erase must come back framed as it went in. A few percent of drift
         * is normal — 2400x3000 came back 2333x3000 — and stretching that onto
         * the original's grid costs nothing. A larger difference means the
         * picture was recomposed rather than erased, and stretching it would
         * paste a misaligned collar over the real one: ghost shoulders, a
         * doubled hanger, a floating label.
         *
         * There is no way to recover from that, so it is refused. The caller
         * keeps the photograph with the stand still in it, which is worth more
         * than a garbled one.
         */
        $wanted = $w / $h;
        $got    = imagesx($erasedImg) / max(1, imagesy($erasedImg));

        if (abs($got - $wanted) / $wanted > 0.06) {
            imagedestroy($full);
            imagedestroy($erasedImg);

            throw new \RuntimeException(sprintf(
                'The erase came back reframed (%s vs %s), so it cannot be aligned with the original.',
                round($got, 3), round($wanted, 3),
            ));
        }

        if (imagesx($erasedImg) !== $w || imagesy($erasedImg) !== $h) {
            $scaled = imagecreatetruecolor($w, $h);
            imagecopyresampled($scaled, $erasedImg, 0, 0, 0, 0, $w, $h, imagesx($erasedImg), imagesy($erasedImg));
            imagedestroy($erasedImg);
            $erasedImg = $scaled;
        }

        $erased = $erasedImg;

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
