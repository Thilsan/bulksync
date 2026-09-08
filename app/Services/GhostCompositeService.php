<?php

namespace App\Services;

/**
 * Put a Ghost Mannequin redraw back onto the photograph it came from, keeping
 * the original's pixels everywhere the redraw did not need to invent anything.
 *
 * The problem this exists to solve: Photoroom's API returns its generative
 * modes at the "HD" preset — 1000x1000 for SQUARE_HD — and no parameter asks
 * for more. A 1024-class redraw was measured at 7% of the original's print
 * detail, which is why EditPhotoItemJob treats Ghost Mannequin as a last
 * resort. But only a small part of the frame actually needed redrawing: the
 * mannequin showing through the neckline and armholes, where the inside of the
 * garment was never photographed and has to be invented.
 *
 * So the trade this makes is: take the invented region from the redraw at its
 * lower resolution, and keep every other pixel from the full-size photograph.
 * The neckline is shadowed inner fabric with almost no fine detail, so it
 * survives being upscaled; the chest print never leaves the original.
 *
 * ── Why this can fail, and how it says so ──────────────────────────────────
 *
 * The redraw builds its own canvas (see PhotoroomService::generatesOwnCanvas)
 * with its own size, padding and centring, so the garment is not aligned with
 * the original and has to be registered onto it first. Worse, the redraw is
 * free to change the garment — the tilted shirts that GHOST_MANNEQUIN_PROMPT
 * is worded to prevent. If it does, the boundary between original pixels and
 * redrawn pixels will not line up, and the result is a torn-looking collar:
 * worse than either input on its own.
 *
 * There is no way to composite around that, so this measures it instead and
 * reports a verdict. A rejected composite is still returned, for looking at —
 * refusing to produce the image would make the failure impossible to inspect —
 * but 'accepted' is false and the caller should fall back to a plain cutout.
 */
class GhostCompositeService
{
    /**
     * At or above this on every channel is background, not product. The same
     * threshold ImageProcessingService uses, for the same reason: these are
     * products shot on white, and the two have to agree about where the
     * subject ends or the boxes they measure will not be comparable.
     */
    private const BACKGROUND_WHITE = 252;

    /**
     * Where the difference between the two images starts and stops counting as
     * "this pixel was redrawn", as a fraction of the largest possible RGB
     * distance.
     *
     * A ramp rather than a threshold. The redraw shifts every fold slightly
     * even when it behaves, so a hard cutoff either lets that noise through as
     * speckle or has to be set so high it misses the edge of the mannequin.
     * Between the two bounds the pixel is a blend, which is also what feathers
     * the seam.
     */
    private const DIFF_LO = 0.10;
    private const DIFF_HI = 0.28;

    /**
     * Resolution the mask is worked out at, before being scaled up to the
     * photograph's own size.
     *
     * The mask does not need the original's detail — it needs to know roughly
     * where the neck hole was, and it is about to be blurred anyway. Deriving
     * it at 512 instead of 2400 is a 22x saving on the only part of this that
     * touches every pixel twice, and scaling a mask up is itself a feather.
     */
    private const MASK_EDGE = 512;

    /*
     * ── Why a difference alone is not enough ───────────────────────────────
     *
     * The redraw disagrees with the photograph in two completely different
     * ways, and they want opposite treatment:
     *
     *   1. It replaced the mannequin with invented fabric. Take the redraw.
     *   2. It lost or garbled detail it was told to leave alone — the Aigner
     *      horseshoe monogram that came back as rings. Keep the original.
     *
     * A difference cannot tell those apart: both are simply "changed". Driving
     * the mask off the difference alone would take the redraw's version of the
     * print precisely because the redraw ruined it, which is the one outcome
     * this whole exercise exists to avoid.
     *
     * So the difference only says where to *look*. What decides is the pixel
     * in the original: a stand is smooth moulded plastic with no weave, no
     * print and little colour, and a garment worth protecting is the opposite.
     * Both tests below are on the original, never on the redraw.
     */

    /**
     * Resolution the original's texture is measured at.
     *
     * It cannot be measured at MASK_EDGE: scaling a 2400-square down to 512
     * averages a fine print away, and the print would then read as smooth —
     * marking the very thing being protected as replaceable. 1024 keeps enough
     * structure for a print or a logo to register as texture while staying a
     * quarter of the pixels of a full-size pass.
     */
    private const TEXTURE_EDGE = 1024;

    /**
     * Gain applied to the high-pass before it is judged, because the raw
     * numbers are small: a hard two-pixel stripe against its background comes
     * out around 60 of 255, and everything softer is well below that.
     */
    private const TEXTURE_GAIN = 3.0;

    /** Where measured texture stops reading as smooth and starts reading as detail. */
    private const TEXTURE_LO = 0.05;
    private const TEXTURE_HI = 0.20;

    /**
     * How far the protection around detail reaches, in mask pixels.
     *
     * Detail near a pixel protects the pixel, not just detail on it. A fine
     * print is periodic, and sampling a periodic pattern at a lower resolution
     * beats against it — some samples land between the stripes, read as a flat
     * expanse, and get replaced. On the test fixture that left a moiré of thin
     * redrawn lines dotted across the print: the mask was right about each
     * individual pixel and wrong about the region.
     *
     * Taking the neighbourhood's strongest texture rather than its average
     * fixes it at the cause. The inside of a mannequin is nowhere near any
     * detail, so it loses nothing.
     */
    private const TEXTURE_REACH = 2;

    /**
     * Passes of a minimum filter to clear speckle out of the mask.
     *
     * Whatever survives the reach above arrives as isolated dots and hairlines,
     * and a mannequin never does — it is one solid region. One pass, because
     * two would also swallow a genuinely narrow stand: a hanger hook or a rail
     * behind a shoulder is only a few mask pixels wide.
     */
    private const ERODE_PASSES = 1;

    /**
     * Where colour stops reading as a stand and starts reading as a garment,
     * measured as max channel minus min.
     *
     * Generous on purpose at the low end. Dress forms are grey, black, white
     * and beige, but plenty are skin-toned, and a skin tone carries real colour
     * — around 65 of 255. Excluding it would leave the commonest form of all
     * unmasked. A saturated garment is far above this: navy sits near 120 and a
     * red near 150.
     */
    private const CHROMA_LO = 70;
    private const CHROMA_HI = 130;

    /**
     * Chroma above which a pixel is certainly garment and certainly not the
     * stand — the threshold the two images are lined up on.
     *
     * Registration cannot use the subject's bounding box, which was the first
     * thing tried and is wrong in principle: the original's box contains the
     * stand (legs, base, the hook above the shoulders) and the redraw's does
     * not, so the two boxes measure different objects. On the test fixture that
     * alone reported the garment's proportions as 20% changed when nothing had
     * changed at all.
     *
     * Lining up on the coloured pixels instead compares garment with garment.
     * 90 sits above the beige and skin tones a dress form comes in — around 55
     * to 65 — with room to spare, and below any real garment colour: a navy is
     * near 120 and a red near 150.
     *
     * A pastel or near-white garment has no pixels this colourful, and there is
     * nothing to be done about that here — such an image falls back to the
     * subject box and is usually rejected downstream by the coverage guard,
     * which is the honest outcome.
     */
    private const GARMENT_CHROMA = 90;

    /**
     * How much of the frame has to be that colourful before the coloured box is
     * trusted over the subject box, as a fraction of the analysis proxy.
     */
    private const MIN_GARMENT_SHARE = 0.02;

    /**
     * Passes of a max filter to grow the mask before it is feathered.
     *
     * Two things need the growth. The boundary between garment and stand is an
     * edge, so the texture test reads it as detail and declines to mask it; and
     * the feather itself, sitting on that boundary, blends the stand's own
     * colour back in at half strength.
     *
     * Both show up as a rim of mannequin tracing the neckline, which is the
     * most visible failure available — the eye finds a skin-toned halo round a
     * collar immediately. It has to be grown by more than the blur's reach, not
     * merely by a pixel or two, or the feather lands back on the stand.
     *
     * Measured on the test fixture, on the neckline's edge and the hem's:
     *
     *   2 passes — rgb(148,140,140) and rgb(238,226,215): the form's beige,
     *              still there at half strength.
     *   5 passes — rgb(43,70,109) and rgb(253,252,251): fabric and white.
     *
     * At 5 the garment eight pixels away is still an untouched rgb(40,90,160),
     * so this is not bought by dragging the redraw out over the product.
     */
    private const DILATE_PASSES = 5;

    /**
     * How many passes of GD's blur to feather the mask with.
     *
     * GD's gaussian is a fixed 3x3 kernel — one pass at mask resolution is
     * nearly nothing. Six passes at 512 is a feather of a few pixels there,
     * which becomes a couple of dozen once the mask is scaled to full size:
     * about right for hiding the small misregistration that survives even a
     * well-behaved redraw.
     */
    private const BLUR_PASSES = 6;

    /*
     * ── The three guards ───────────────────────────────────────────────────
     *
     * Deliberately separate, because they fail for different reasons and the
     * operator needs to know which one it was. "The redraw moved the dress" is
     * a prompt problem; "the redraw changed almost everything" means the
     * composite had nothing to gain and the plain cutout was always the answer.
     */

    /** How far the subject's width-to-height may drift before the shape changed. */
    private const MAX_ASPECT_SHIFT = 0.07;

    /**
     * How much of the redraw must land inside the original's silhouette.
     *
     * Not a symmetric overlap — see containment(). A redraw that correctly
     * dropped the stand scores 100% here; one that moved the garment does not.
     */
    private const MIN_CONTAINMENT = 0.90;

    /**
     * How much of the garment may be taken from the redraw before the whole
     * point is lost.
     *
     * A mannequin showing through a neckline and two armholes is a few percent
     * of the subject. If a third of it is being replaced, the redraw has
     * recoloured or reshaped the garment wholesale, and what comes out is the
     * 1000px redraw wearing the original as a border.
     */
    private const MAX_MASK_COVERAGE = 0.35;

    /**
     * Composite a redraw onto its original.
     *
     * @param  string  $original  the full-size photograph, garment on the stand
     * @param  string  $ghost     Photoroom's redraw of it, stand gone
     *
     * @return array{image: string, mask: string, accepted: bool, verdict: string, reason: string, metrics: array}
     *
     * @throws \RuntimeException  when either image cannot be read or has no
     *                            findable subject
     */
    public function composite(string $original, string $ghost): array
    {
        $orig  = $this->decode($original, 'original');
        $redraw = $this->decode($ghost, 'ghost');

        try {
            $oBox = $this->subjectBox($orig);
            $gBox = $this->subjectBox($redraw);

            if ($oBox === null || $gBox === null) {
                throw new \RuntimeException(
                    'No subject found in the ' . ($oBox === null ? 'original' : 'redraw')
                    . ' — it is either empty or was not shot on white.'
                );
            }

            /*
             * What the two images are lined up on: the garment, not the subject.
             * The original's subject includes the stand and the redraw's does
             * not, so aligning subject to subject aligns two different objects
             * and drags the redraw down over the hem by however much stand is
             * in shot. Falls back to the subject box for a garment with no
             * colour in it to find.
             */
            $oGarment = $this->colouredBox($orig) ?? $oBox;
            $gGarment = $this->colouredBox($redraw) ?? $gBox;

            $registered = $this->register($orig, $redraw, $oGarment, $gGarment);

            try {
                $metrics = $this->measure($orig, $registered, $oBox, $gBox, $oGarment, $gGarment);
                $mask    = $this->deriveMask($orig, $registered);

                $metrics['mask_coverage'] = $this->maskCoverage($orig, $mask, $oBox);

                [$verdict, $reason] = $this->judge($metrics);

                return [
                    'image'    => $this->blend($orig, $registered, $mask),
                    'mask'     => $this->encode($mask),
                    'accepted' => $verdict === 'ok',
                    'verdict'  => $verdict,
                    'reason'   => $reason,
                    'metrics'  => $metrics,
                ];
            } finally {
                imagedestroy($registered);
            }
        } finally {
            imagedestroy($orig);
            imagedestroy($redraw);
        }
    }

    // ── Reading ────────────────────────────────────────────────────────────

    /**
     * Bytes to an opaque truecolor image.
     *
     * Flattened onto white on the way in, on purpose. A cutout arrives with an
     * alpha channel, and every comparison below asks "is this pixel
     * background?" — laying it on white makes transparent answer yes to that
     * question by itself, with no alpha special case anywhere downstream.
     */
    private function decode(string $bytes, string $which): \GdImage
    {
        $img = @imagecreatefromstring($bytes);

        if (!$img) {
            throw new \RuntimeException("Could not read the {$which} image.");
        }

        $w = imagesx($img);
        $h = imagesy($img);

        $flat  = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($flat, 255, 255, 255);
        imagefilledrectangle($flat, 0, 0, $w - 1, $h - 1, $white);

        // Alpha blending is on by default for truecolor, which is what does
        // the flattening here.
        imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img);

        return $flat;
    }

    /**
     * Everything that is not background: garment and stand together.
     *
     * @return array{0:int,1:int,2:int,3:int}|null  [minX, minY, maxX, maxY]
     */
    private function subjectBox(\GdImage $img, int $proxyEdge = 240): ?array
    {
        return $this->boundingBox($img, false, $proxyEdge);
    }

    /**
     * Just the garment: the box around pixels too colourful to be a stand.
     *
     * Null when there is not enough of that to be going on with — a white shirt
     * on a white form gives this nothing to hold, and a bad alignment is worse
     * than an honest fallback.
     *
     * @return array{0:int,1:int,2:int,3:int}|null
     */
    private function colouredBox(\GdImage $img, int $proxyEdge = 240): ?array
    {
        return $this->boundingBox($img, true, $proxyEdge);
    }

    /**
     * A bounding box, measured on a small copy.
     *
     * Scanning a 2400-square pixel by pixel in PHP costs seconds an image, and
     * the box only has to be right to a few pixels — everything downstream
     * either scales by it or feathers over it.
     *
     * @return array{0:int,1:int,2:int,3:int}|null
     */
    private function boundingBox(\GdImage $img, bool $colouredOnly, int $proxyEdge): ?array
    {
        $w = imagesx($img);
        $h = imagesy($img);

        $proxy = $this->proxy($img, $proxyEdge);
        $pw    = imagesx($proxy);
        $ph    = imagesy($proxy);

        $minX = $pw; $minY = $ph; $maxX = -1; $maxY = -1;
        $counted = 0;

        for ($y = 0; $y < $ph; $y++) {
            for ($x = 0; $x < $pw; $x++) {
                $rgb = imagecolorat($proxy, $x, $y);

                if ($this->isBackground($rgb)) {
                    continue;
                }

                if ($colouredOnly && $this->chroma($rgb) < self::GARMENT_CHROMA) {
                    continue;
                }

                $counted++;

                if ($x < $minX) $minX = $x;
                if ($x > $maxX) $maxX = $x;
                if ($y < $minY) $minY = $y;
                if ($y > $maxY) $maxY = $y;
            }
        }

        imagedestroy($proxy);

        if ($maxX < 0) {
            return null;
        }

        if ($colouredOnly && $counted < self::MIN_GARMENT_SHARE * $pw * $ph) {
            return null;
        }

        // Rounded outwards, so the conversion back can only grow the box and
        // never clip the product.
        $sx = $w / $pw;
        $sy = $h / $ph;

        return [
            (int) floor($minX * $sx),
            (int) floor($minY * $sy),
            (int) min($w - 1, ceil(($maxX + 1) * $sx)),
            (int) min($h - 1, ceil(($maxY + 1) * $sy)),
        ];
    }

    private function isBackground(int $rgb): bool
    {
        return (($rgb >> 16) & 0xFF) >= self::BACKGROUND_WHITE
            && (($rgb >> 8) & 0xFF) >= self::BACKGROUND_WHITE
            && ($rgb & 0xFF) >= self::BACKGROUND_WHITE;
    }

    /** How much colour a pixel carries: the spread across its channels. */
    private function chroma(int $rgb): int
    {
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;

        return max($r, $g, $b) - min($r, $g, $b);
    }

    // ── Registration ───────────────────────────────────────────────────────

    /**
     * Scale and shift the redraw so its subject sits where the original's does,
     * on a canvas the original's size.
     *
     * Scaled by height alone rather than fitted to the box. A garment's height
     * is the stable measurement — sleeve spread and drape move the width about
     * between two renders of the same dress — and fitting both axes would
     * stretch the redraw to hide exactly the shape change the guards are
     * looking for.
     */
    private function register(\GdImage $orig, \GdImage $ghost, array $oBox, array $gBox): \GdImage
    {
        [$oMinX, $oMinY, $oMaxX, $oMaxY] = $oBox;
        [$gMinX, $gMinY, $gMaxX, $gMaxY] = $gBox;

        $ow = imagesx($orig);
        $oh = imagesy($orig);

        $scale = (($oMaxY - $oMinY + 1) / max(1, $gMaxY - $gMinY + 1));

        $sw = max(1, (int) round(imagesx($ghost) * $scale));
        $sh = max(1, (int) round(imagesy($ghost) * $scale));

        $scaled = imagescale($ghost, $sw, $sh);

        if ($scaled === false) {
            throw new \RuntimeException('Could not scale the redraw to the original.');
        }

        $canvas = imagecreatetruecolor($ow, $oh);
        $white  = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $ow - 1, $oh - 1, $white);

        // Box centre onto box centre.
        $dstX = (int) round((($oMinX + $oMaxX) / 2) - ((($gMinX + $gMaxX) / 2) * $scale));
        $dstY = (int) round((($oMinY + $oMaxY) / 2) - ((($gMinY + $gMaxY) / 2) * $scale));

        /*
         * imagecopy will not clip a negative destination for us, and a redraw
         * whose canvas is proportionally wider than the original's gives one
         * routinely. Moving the overhang onto the source offsets instead is
         * the same crop, expressed where GD will honour it.
         */
        $srcX = max(0, -$dstX);
        $srcY = max(0, -$dstY);
        $dx   = max(0, $dstX);
        $dy   = max(0, $dstY);

        $cw = min($sw - $srcX, $ow - $dx);
        $ch = min($sh - $srcY, $oh - $dy);

        if ($cw > 0 && $ch > 0) {
            imagecopy($canvas, $scaled, $dx, $dy, $srcX, $srcY, $cw, $ch);
        }

        imagedestroy($scaled);

        return $canvas;
    }

    // ── Measuring ──────────────────────────────────────────────────────────

    /**
     * Everything the verdict is decided on, plus what a person needs to see to
     * argue with it.
     */
    private function measure(
        \GdImage $orig,
        \GdImage $registered,
        array $oBox,
        array $gBox,
        array $oGarment,
        array $gGarment,
    ): array {
        // Garment against garment. The subject boxes are not comparable — one
        // of them has a mannequin in it.
        $oAspect = ($oGarment[2] - $oGarment[0] + 1) / max(1, $oGarment[3] - $oGarment[1] + 1);
        $gAspect = ($gGarment[2] - $gGarment[0] + 1) / max(1, $gGarment[3] - $gGarment[1] + 1);

        return [
            'original_size' => imagesx($orig) . 'x' . imagesy($orig),
            'original_box'  => $oBox,
            'ghost_box'     => $gBox,
            'garment_box'   => $oGarment,
            'aspect_shift'  => round(abs(($gAspect / max(0.0001, $oAspect)) - 1), 4),
            'containment'   => $this->containment($orig, $registered),
        ];
    }

    /**
     * How much of the redraw lands on something that was there before: the
     * fraction of the redraw's own silhouette that falls inside the original's.
     *
     * Asymmetric on purpose. A symmetric overlap was the first attempt and it
     * punishes the redraw for succeeding — the stand it correctly removed is in
     * the original's silhouette and not in its own, so a photograph with a
     * heavy base scores badly for no reason. Measured this way, removing the
     * stand costs nothing and the number only falls when the redraw puts
     * garment where there was background: which is what moving, tilting or
     * enlarging it does.
     *
     * This is also the measurement that catches a tilt. Aspect ratio barely
     * moves on a garment rotated a few degrees, while every edge inside the box
     * has left the silhouette it used to fill.
     */
    private function containment(\GdImage $orig, \GdImage $registered): float
    {
        $a = $this->proxy($orig, self::MASK_EDGE);
        $b = $this->proxy($registered, self::MASK_EDGE);

        $w = imagesx($a);
        $h = imagesy($a);

        $inside = 0;
        $redraw = 0;

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if ($this->isBackground(imagecolorat($b, $x, $y))) {
                    continue;
                }

                $redraw++;

                if (!$this->isBackground(imagecolorat($a, $x, $y))) {
                    $inside++;
                }
            }
        }

        imagedestroy($a);
        imagedestroy($b);

        return $redraw === 0 ? 0.0 : round($inside / $redraw, 4);
    }

    // ── The mask ───────────────────────────────────────────────────────────

    /**
     * Where to take the redraw's pixels instead of the original's: a greyscale
     * image at the original's size, black to keep and white to replace.
     *
     * Three tests, multiplied. A pixel is only replaced if all three agree, and
     * two of the three look only at the original:
     *
     *   changed  — the redraw disagrees here, so there is something to discuss.
     *   smooth   — the original has no weave, print or lettering to lose.
     *   colourless — the original is near-neutral, as moulded plastic is.
     *
     * The last two are what stop a garbled print being taken from the redraw:
     * a monogram is neither smooth nor colourless, so however badly the redraw
     * mangled it, the original's version is what survives.
     *
     * Note what this does not need: any notion of what a mannequin looks like
     * in particular. A grey dress form, a black one, a beige one, a wooden
     * hanger and a chrome rail all read the same way — as a smooth, near-
     * neutral region that the redraw disagreed with.
     */
    private function deriveMask(\GdImage $orig, \GdImage $registered): \GdImage
    {
        $a = $this->proxy($orig, self::MASK_EDGE);
        $b = $this->proxy($registered, self::MASK_EDGE);

        $w = imagesx($a);
        $h = imagesy($a);

        // Measured on the original at its own scale, then brought down to the
        // mask's — averaging on the way, which turns a per-pixel high-pass into
        // the local texture energy this wants.
        $texture = $this->textureMap($orig, $w, $h);

        $mask = imagecreatetruecolor($w, $h);

        // 441.67 is the distance from black to white in RGB, so this is the
        // difference expressed as a fraction of the largest one possible.
        $maxDistance = sqrt(3 * 255 * 255);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $p = imagecolorat($a, $x, $y);
                $q = imagecolorat($b, $x, $y);

                $pr = ($p >> 16) & 0xFF;
                $pg = ($p >> 8) & 0xFF;
                $pb = $p & 0xFF;

                $dr = $pr - (($q >> 16) & 0xFF);
                $dg = $pg - (($q >> 8) & 0xFF);
                $db = $pb - ($q & 0xFF);

                $changed = $this->smoothstep(
                    self::DIFF_LO,
                    self::DIFF_HI,
                    sqrt($dr * $dr + $dg * $dg + $db * $db) / $maxDistance,
                );

                $smooth = 1 - $this->smoothstep(
                    self::TEXTURE_LO,
                    self::TEXTURE_HI,
                    (imagecolorat($texture, $x, $y) & 0xFF) / 255,
                );

                $colourless = 1 - $this->smoothstep(
                    self::CHROMA_LO / 255,
                    self::CHROMA_HI / 255,
                    (max($pr, $pg, $pb) - min($pr, $pg, $pb)) / 255,
                );

                $v = (int) round($changed * $smooth * $colourless * 255);

                imagesetpixel($mask, $x, $y, ($v << 16) | ($v << 8) | $v);
            }
        }

        imagedestroy($a);
        imagedestroy($b);
        imagedestroy($texture);

        /*
         * Clean, then grow, then soften — in that order.
         *
         * The erosion has to come before the growth or it has nothing to bite
         * on: grow first and every speck of speckle is a blob too fat to
         * clear. And both have to come before the blur, because softening a rim
         * into a gradient and then growing that spreads the redraw much further
         * over the garment than intended.
         */
        for ($i = 0; $i < self::ERODE_PASSES; $i++) {
            $this->morph($mask, max: false);
        }

        for ($i = 0; $i < self::ERODE_PASSES + self::DILATE_PASSES; $i++) {
            $this->morph($mask, max: true);
        }

        for ($i = 0; $i < self::BLUR_PASSES; $i++) {
            imagefilter($mask, IMG_FILTER_GAUSSIAN_BLUR);
        }

        // Up to the photograph's own size. Bilinear on the way up is itself a
        // feather, which is why the blur above can afford to be modest.
        $full = imagescale($mask, imagesx($orig), imagesy($orig));
        imagedestroy($mask);

        if ($full === false) {
            throw new \RuntimeException('Could not scale the mask to the original.');
        }

        return $full;
    }

    /**
     * How much detail the original carries at each point, as a greyscale image
     * at the mask's size: black is a smooth expanse, white is print or weave.
     *
     * A high-pass, done the cheap way — the image minus a blurred copy of
     * itself is what the blur threw away, which is precisely its fine detail.
     * GD's blur is C, the subtraction is the only PHP loop, and it runs at
     * TEXTURE_EDGE rather than full size.
     *
     * Luma rather than per-channel: a print is a pattern of light and dark, and
     * two channels' worth of the same edge is not twice as much detail.
     */
    private function textureMap(\GdImage $orig, int $maskW, int $maskH): \GdImage
    {
        $sharp = $this->proxy($orig, self::TEXTURE_EDGE);

        $w = imagesx($sharp);
        $h = imagesy($sharp);

        $blurred = imagecreatetruecolor($w, $h);
        imagecopy($blurred, $sharp, 0, 0, 0, 0, $w, $h);

        // Twice, so the pass has a wide enough reach to count a soft print as
        // detail rather than only hard pixel-level edges.
        imagefilter($blurred, IMG_FILTER_GAUSSIAN_BLUR);
        imagefilter($blurred, IMG_FILTER_GAUSSIAN_BLUR);

        $map = imagecreatetruecolor($w, $h);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $p = imagecolorat($sharp, $x, $y);
                $q = imagecolorat($blurred, $x, $y);

                $lp = 0.299 * (($p >> 16) & 0xFF) + 0.587 * (($p >> 8) & 0xFF) + 0.114 * ($p & 0xFF);
                $lq = 0.299 * (($q >> 16) & 0xFF) + 0.587 * (($q >> 8) & 0xFF) + 0.114 * ($q & 0xFF);

                $v = (int) min(255, round(abs($lp - $lq) * self::TEXTURE_GAIN));

                imagesetpixel($map, $x, $y, ($v << 16) | ($v << 8) | $v);
            }
        }

        imagedestroy($sharp);
        imagedestroy($blurred);

        // Down to the mask's size. The averaging is the point: one pixel of the
        // result is how much detail that whole neighbourhood holds, which is
        // the question the mask actually asks.
        $small = imagescale($map, $maskW, $maskH);
        imagedestroy($map);

        if ($small === false) {
            throw new \RuntimeException('Could not scale the texture map to the mask.');
        }

        /*
         * Spread the detail outward, so a pixel is protected by detail near it
         * and not only by detail on it. Averaging alone leaves gaps in a
         * periodic pattern — see TEXTURE_REACH — and a gap in the middle of a
         * print is a hole punched in the thing being protected.
         */
        for ($i = 0; $i < self::TEXTURE_REACH; $i++) {
            $this->morph($small, max: true);
        }

        return $small;
    }

    /**
     * Grow or shrink the light parts of a greyscale image by one pixel, in
     * place: a 3x3 maximum or minimum.
     *
     * GD has blur and smooth but no morphology, and all three of the problems
     * left over at this stage are morphological — the rim of un-masked
     * mannequin the texture test leaves along a neckline, the speckle a fine
     * print aliases into, and the reach of the protection around detail.
     */
    private function morph(\GdImage $img, bool $max): void
    {
        $w = imagesx($img);
        $h = imagesy($img);

        // Read from a frozen copy, or the growth feeds on itself and travels
        // across the picture one row at a time instead of by one pixel.
        $src = imagecreatetruecolor($w, $h);
        imagecopy($src, $img, 0, 0, 0, 0, $w, $h);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $best = $max ? 0 : 255;

                for ($dy = -1; $dy <= 1; $dy++) {
                    $ny = $y + $dy;

                    if ($ny < 0 || $ny >= $h) {
                        continue;
                    }

                    for ($dx = -1; $dx <= 1; $dx++) {
                        $nx = $x + $dx;

                        if ($nx < 0 || $nx >= $w) {
                            continue;
                        }

                        $v = imagecolorat($src, $nx, $ny) & 0xFF;

                        if ($max ? $v > $best : $v < $best) {
                            $best = $v;
                        }
                    }
                }

                imagesetpixel($img, $x, $y, ($best << 16) | ($best << 8) | $best);
            }
        }

        imagedestroy($src);
    }

    /**
     * How much of the garment is being taken from the redraw.
     *
     * Measured inside the subject's own box rather than over the whole frame.
     * Over the frame the number is dominated by the background — the original's
     * studio white against the redraw's pure white differ enough to mask, which
     * is harmless and even wanted, but it would drown the figure that matters.
     */
    private function maskCoverage(\GdImage $orig, \GdImage $mask, array $oBox): float
    {
        [$minX, $minY, $maxX, $maxY] = $oBox;

        $ow = imagesx($orig);

        $step = max(1, (int) floor(($maxX - $minX + 1) / 200));

        $subject = 0;
        $replaced = 0;

        for ($y = $minY; $y <= $maxY; $y += $step) {
            for ($x = $minX; $x <= $maxX; $x += $step) {
                if ($this->isBackground(imagecolorat($orig, $x, $y))) {
                    continue;
                }

                $subject++;

                if ((imagecolorat($mask, $x, $y) & 0xFF) > 127) {
                    $replaced++;
                }
            }
        }

        unset($ow);

        return $subject === 0 ? 0.0 : round($replaced / $subject, 4);
    }

    // ── The blend ──────────────────────────────────────────────────────────

    /**
     * The original, with the redraw's pixels mixed in where the mask says to.
     *
     * Starts from a copy of the photograph and only writes where the mask is
     * not black. On a well-behaved redraw that is a few percent of the frame,
     * which turns a per-pixel PHP loop over a 2400-square from unbearable into
     * merely slow.
     */
    private function blend(\GdImage $orig, \GdImage $registered, \GdImage $mask): string
    {
        $w = imagesx($orig);
        $h = imagesy($orig);

        $out = imagecreatetruecolor($w, $h);
        imagecopy($out, $orig, 0, 0, 0, 0, $w, $h);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $m = imagecolorat($mask, $x, $y) & 0xFF;

                // Rounding to the same byte the original already holds, so
                // there is nothing to write.
                if ($m < 2) {
                    continue;
                }

                $p = imagecolorat($orig, $x, $y);
                $q = imagecolorat($registered, $x, $y);

                if ($m > 253) {
                    imagesetpixel($out, $x, $y, $q);

                    continue;
                }

                $t = $m / 255;

                $r = (int) round((($p >> 16) & 0xFF) * (1 - $t) + (($q >> 16) & 0xFF) * $t);
                $g = (int) round((($p >> 8) & 0xFF) * (1 - $t) + (($q >> 8) & 0xFF) * $t);
                $b = (int) round(($p & 0xFF) * (1 - $t) + ($q & 0xFF) * $t);

                imagesetpixel($out, $x, $y, ($r << 16) | ($g << 8) | $b);
            }
        }

        $bytes = $this->encode($out);
        imagedestroy($out);

        return $bytes;
    }

    // ── The verdict ────────────────────────────────────────────────────────

    /**
     * Whether the composite is worth using, and if not, which way it failed.
     *
     * @return array{0:string,1:string}  [verdict, reason]
     */
    private function judge(array $metrics): array
    {
        /*
         * Containment first, because it is the broader failure: a garment that
         * was moved or tilted also has slightly different proportions, and
         * "the redraw moved the dress" is the more useful thing to be told.
         */
        if ($metrics['containment'] < self::MIN_CONTAINMENT) {
            return ['moved', sprintf(
                'Only %.1f%% of the redraw lands where the garment actually was (needs %.0f%%). '
                . 'It was moved, tilted or enlarged — compositing would leave a seam along '
                . 'every edge.',
                $metrics['containment'] * 100,
                self::MIN_CONTAINMENT * 100,
            )];
        }

        if ($metrics['aspect_shift'] > self::MAX_ASPECT_SHIFT) {
            return ['reshaped', sprintf(
                'The redraw changed the garment\'s proportions by %.1f%% (limit %.0f%%). '
                . 'It was recut rather than merely lifted off the stand, so the original and the '
                . 'redraw cannot be made to line up.',
                $metrics['aspect_shift'] * 100,
                self::MAX_ASPECT_SHIFT * 100,
            )];
        }

        if ($metrics['mask_coverage'] > self::MAX_MASK_COVERAGE) {
            return ['redrawn', sprintf(
                '%.1f%% of the garment differs from the original (limit %.0f%%). '
                . 'This much is not a mannequin behind a neckline — the redraw reworked the garment '
                . 'itself, and the composite would be the low-resolution version wearing the '
                . 'original as a border.',
                $metrics['mask_coverage'] * 100,
                self::MAX_MASK_COVERAGE * 100,
            )];
        }

        return ['ok', sprintf(
            'The garment held still (%.1f%% of the redraw lands on it) and %.1f%% of it was taken from '
            . 'the redraw. Everything else is the original photograph at full resolution.',
            $metrics['containment'] * 100,
            $metrics['mask_coverage'] * 100,
        )];
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** A copy no longer than $edge on its longest side. Never enlarges. */
    private function proxy(\GdImage $img, int $edge): \GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);

        if (max($w, $h) <= $edge) {
            $copy = imagecreatetruecolor($w, $h);
            imagecopy($copy, $img, 0, 0, 0, 0, $w, $h);

            return $copy;
        }

        $scale = $edge / max($w, $h);

        $proxy = imagescale($img, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));

        if ($proxy === false) {
            throw new \RuntimeException('Could not scale an image down for analysis.');
        }

        return $proxy;
    }

    /** A ramp with no corners on it, so the seam has no visible edge. */
    private function smoothstep(float $lo, float $hi, float $x): float
    {
        if ($hi <= $lo) {
            return $x >= $hi ? 1.0 : 0.0;
        }

        $t = max(0.0, min(1.0, ($x - $lo) / ($hi - $lo)));

        return $t * $t * (3 - 2 * $t);
    }

    private function encode(\GdImage $img): string
    {
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }
}
