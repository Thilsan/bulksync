<?php

namespace App\Services;

/**
 * Put the photograph's real print back onto a Ghost Mannequin redraw.
 *
 * ── Why this exists, and why it is not GhostCompositeService ───────────────
 *
 * GhostCompositeService keeps the original photograph and borrows only the
 * hole the stand left. That is the better trade whenever the redraw put the
 * garment back where it found it — and on Photoroom's Ghost Mannequin it does
 * not. Measured on the Aigner tee: the redraw re-proportions the garment,
 * re-frames it on its own square canvas and moves the print, so there is no
 * scale-and-shift that lines the two up. Registration fails before the mask is
 * even reached.
 *
 * What the redraw is genuinely good at is the part only it can do: it rebuilt
 * the collar and the shirt's inside back from a hanger that was covering
 * almost all of it, label and all. What it is bad at is the part it was told
 * to leave alone. Measured across all three of Photoroom's quality tiers, the
 * Aigner horseshoe monogram came back as:
 *
 *   1K (Plus plan)  — a generic dotted grid; the motif is gone.
 *   4K (Enterprise) — sharp, detailed, and still not horseshoes.
 *
 * That is the finding this class is built on: resolution buys fidelity of
 * rendering, not fidelity to the product. Paying for a higher tier would buy a
 * crisp invented monogram, which on a licensed brand is worse than a soft one.
 *
 * So the geometry comes from the redraw, and the print comes from the
 * photograph. Neither image is asked for anything it cannot supply.
 *
 * ── What this costs ────────────────────────────────────────────────────────
 *
 * The garment's body is the redraw's, enlarged to the output canvas, and an
 * enlargement invents pixels. On plain jersey that is nearly free: there is no
 * fine detail in blank fabric to lose. The print, which is the only part of
 * the frame carrying detail a buyer would zoom into, is real photograph.
 */
class GhostPrintTransplantService
{
    /**
     * Resolution the print is hunted for at.
     *
     * Component labelling is the one step here that walks every pixel several
     * times, and the print's box only has to be right to a few pixels — it is
     * feathered over a wider margin than that afterwards.
     */
    private const DETECT_EDGE = 500;

    /**
     * How dark a pixel must be, below the frame's own light level, to count as
     * ink rather than fabric.
     *
     * Relative rather than absolute, because these garments are photographed
     * on backgrounds that are nowhere near white — the Aigner tee's backdrop
     * measures rgb(239,240,244) and the cream fabric rgb(238,238,240), one to
     * four levels apart. An absolute threshold that works on one shoot is
     * wrong on the next; the distance down from the light level is stable.
     *
     * The size of the drop is what took two goes to get right, and the first
     * value silently ruined the detection rather than failing it. At 70 the
     * threshold landed at 185 on a redraw whose background Photoroom returns as
     * pure white, and a redraw carries shading the flat photograph does not:
     * measured on one, the shirt's own sleeve and hem shadows reach luma 160,
     * so they read as ink, chained into one run across the whole garment, and
     * the "print" came out as 52% of the frame.
     *
     * The two populations are far apart once measured, so the threshold sits
     * between them with room on both sides:
     *
     *   ink      — the print 23..37, the neck label 38
     *   shading  — sleeve 160, hem 169, lit body 246
     *
     * 130 puts the line near 125 on a white-backed redraw and near 110 on the
     * photograph. Both leave a margin of about 90 levels either way, which is
     * what makes this safe across shoots rather than tuned to one.
     */
    private const INK_DROP = 130;

    /**
     * Smallest and largest share of the frame a print may occupy.
     *
     * Below the floor is a speck of dust or a stitch; above the ceiling is not
     * a print but a dark garment, and transplanting a whole garment is a
     * different operation with different risks.
     */
    private const MIN_PRINT_SHARE = 0.0008;
    private const MAX_PRINT_SHARE = 0.25;

    /**
     * How far apart two runs of ink may be and still be one print, as a
     * fraction of the cluster's own larger side.
     *
     * A print is almost never one connected shape. The Aigner tee's is three
     * teddy bears and six separate letters, and the first version of this
     * picked the best-scoring single run and transplanted one bear. Growing a
     * cluster outward instead is what turns nine pieces of artwork into one
     * print.
     *
     * Kept modest on purpose, because the same photograph also holds two other
     * dark things that must NOT be absorbed: measured on the Aigner tee, the
     * hanger sits about 300 px above the print and the neck label about 410 px,
     * against a cluster some 700 px across — so a quarter of the cluster
     * reaches the lettering and nothing else.
     */
    private const MERGE_GAP = 0.25;

    /**
     * Smallest reach the merge ever uses, as a share of the analysis proxy's
     * longer side.
     *
     * A reach derived only from the cluster cannot get started: seeded from one
     * 10-pixel mark it looks 2 pixels around itself, finds nothing, and stops
     * with a single letter. The floor is what lets the first neighbour in,
     * after which the cluster's own size takes over and the reach grows with
     * it.
     *
     * Measured room to spare: on the Aigner tee the nearest thing that must NOT
     * be absorbed — the hanger — sits about 50 proxy pixels from the print,
     * against a floor of 12.
     */
    private const MERGE_FLOOR = 0.025;

    /**
     * Smallest run of ink worth absorbing into a cluster, as a share of the
     * analysis proxy. Below this is sensor noise and JPEG mosquito artefacts
     * around the artwork's own edges.
     */
    private const NOISE_SHARE = 0.00004;

    /*
     * ── The woven label ───────────────────────────────────────────────────
     *
     * The redraw fabricates the neck label as surely as it fabricates the
     * print: measured on the Aigner tee, it reproduces the label's colour
     * almost exactly — rgb(105,53,57) against rgb(104,51,49) — and then writes
     * gibberish on it where the brand name should be, losing the size tab's
     * text entirely.
     *
     * It cannot be found the way the print is. The label is dark, and so is the
     * hanger sitting directly above it — about twelve proxy pixels away on the
     * photograph, close enough that any region grown from the label would drag
     * the hanger back into a picture Photoroom had just cleared of it. That is
     * the worst outcome available here, so darkness is not usable.
     *
     * Hue is. The label is maroon and the hanger is brown, and the two part
     * cleanly on how far red sits above green:
     *
     *   maroon label — red-green 52, green-blue  4
     *   brown hanger — red-green 23, green-blue 33
     *
     * A threshold of 30 excludes the hanger outright.
     */

    /** How far red must sit above green for a pixel to be label rather than stand. */
    private const LABEL_RED_OVER_GREEN = 30;

    /** How near green and blue must be, which is what makes maroon not brown. */
    private const LABEL_GREEN_BLUE_SPREAD = 20;

    /** Above this a pixel is too bright to be a woven label in shadow at the collar. */
    private const LABEL_MAX_RED = 200;

    /**
     * Smallest share of the analysis proxy a label may cover.
     *
     * Small: on the redraw the label is a nine-hundredth of the frame. Anything
     * under this is a stray warm pixel — a JPEG artefact at the collar's edge,
     * or a speck of the stand.
     */
    private const MIN_LABEL_SHARE = 0.0004;

    /**
     * How far the label's angle may differ between the two images before the
     * transplant is refused.
     *
     * The redraw rebuilds the collar, so the label lands on it at its own
     * angle — measured at 7 degrees against a level original. Rotating the
     * patch to match is the point. But a large disagreement means the two
     * labels are not the same object, and rotating a patch that far would
     * smear the lettering it exists to preserve.
     */
    private const MAX_LABEL_TILT = 20.0;

    /**
     * How much of the print's own size to feather around the transplanted
     * patch.
     *
     * The patch lands on plain fabric whose shading the redraw invented, so the
     * two are never quite the same brightness at the join. A hard edge on a
     * flat cream expanse is the most visible artefact available; a wide, soft
     * ramp on a region with no detail in it is invisible.
     */
    private const FEATHER = 0.10;

    /**
     * How far the patch may be pushed to match the redraw's local brightness.
     *
     * No longer what corrects the join — that is done locally now, in paste(),
     * by taking the redraw's own shading and carrying the photograph's detail
     * on it. This survives as a sanity check on the pair: a large gap means the
     * two images were lit so differently that they probably are not the same
     * garment, or the redraw invented a colour.
     *
     * Loosened from 18 accordingly. At 18 it was doing double duty as both the
     * correction's limit and the pair's sanity check, and a real pair measured
     * -12 with no visible join at all once the correction was local.
     */
    private const MAX_LEVEL_SHIFT = 45;

    /**
     * @param  string  $original    the photograph, stand and all
     * @param  string  $ghost       Photoroom's Ghost Mannequin redraw of it
     * @param  int     $targetEdge  longest edge of the output
     *
     * @return array{image: string, accepted: bool, reason: string, metrics: array}
     *
     * @throws \RuntimeException  when either image cannot be read, or no print
     *                            can be found in one of them
     */
    public function transplant(string $original, string $ghost, int $targetEdge = 2000): array
    {
        $photo  = $this->decode($original, 'original');
        $redraw = $this->decode($ghost, 'ghost');

        try {
            $photoPrint  = $this->findPrint($photo, 'original');
            $redrawPrint = $this->findPrint($redraw, 'ghost');

            // The redraw, enlarged to the canvas the catalogue wants. Done
            // first so every coordinate below is in output space and there is
            // only one scale factor in play.
            $canvas = $this->enlarge($redraw, $targetEdge);
            $scale  = imagesx($canvas) / imagesx($redraw);

            try {
                $target = $this->scaleBox($redrawPrint, $scale);

                $metrics = $this->measure($photoPrint, $redrawPrint, $target, $scale, $photo, $canvas);

                [$accepted, $reason] = $this->judge($metrics);

                $this->paste($canvas, $photo, $photoPrint, $target, $metrics['level_shift']);

                /*
                 * The label, if both images have one. Kept entirely separate
                 * from the print's verdict: a garment with no label, or a label
                 * the redraw put at an impossible angle, must not cost the
                 * print transplant that already succeeded.
                 */
                $metrics += $this->pasteTag($canvas, $photo, $redraw, $scale);

                return [
                    'image'    => $this->encode($canvas),
                    'accepted' => $accepted,
                    'reason'   => $reason,
                    'metrics'  => $metrics,
                ];
            } finally {
                imagedestroy($canvas);
            }
        } finally {
            imagedestroy($photo);
            imagedestroy($redraw);
        }
    }

    // ── Finding the print ──────────────────────────────────────────────────

    /**
     * The print's bounding box in the given image, in its own pixels.
     *
     * The hard part is not finding dark pixels — it is telling a print from a
     * hanger, which on the Aigner tee is darker than the print and larger. The
     * two are told apart by edge density rather than by position or size: a
     * hanger is one smooth slab, a print is lettering and pattern and is nearly
     * all edge. Position would have worked on this photograph and failed on the
     * next one, where the stand is at the hem.
     *
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function findPrint(\GdImage $img, string $which): array
    {
        $proxy = $this->proxy($img, self::DETECT_EDGE);

        $w = imagesx($proxy);
        $h = imagesy($proxy);

        $ink = $this->inkMask($proxy);

        $components = $this->components($ink, $w, $h);

        imagedestroy($proxy);

        $best      = null;
        $bestScore = -1.0;

        foreach ($components as $c) {
            $share = $c['area'] / ($w * $h);

            /*
             * The size limits belong to the print as a whole, not to each mark
             * of it — checked below, once the cluster has been grown. Applying
             * them here instead is what made the first version find nothing on
             * a print of many small pieces: no single letter of "AIGNER" is a
             * thousandth of the frame, so nothing was ever big enough to seed
             * from.
             *
             * A single run larger than a print can be, though, is not artwork:
             * it is a dark garment or a slab of stand, and seeding there would
             * grow a cluster over the whole picture.
             */
            if ($share < self::NOISE_SHARE || $share > self::MAX_PRINT_SHARE) {
                continue;
            }

            /*
             * Edge pixels over area. A solid slab scores near zero because
             * only its rim is edge; lettering and fine pattern score high
             * because almost every pixel of them is next to fabric.
             *
             * Multiplied by area so that, between two equally intricate
             * things, the larger is taken to be the print — a print is the
             * thing on a garment worth protecting, and a stray thread is not.
             */
            $score = ($c['edge'] / max(1, $c['area'])) * sqrt($c['area']);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best      = $c;
            }
        }

        if ($best === null) {
            throw new \RuntimeException(
                "No print found in the {$which} image. This transplant only helps a garment "
                . 'carrying a print, logo or lettering; a plain one has nothing to move and the '
                . 'redraw can be used as it is.'
            );
        }

        $box = $this->grow($best, $components, $w, $h);

        // Now that the whole print is gathered, its size can be judged. A
        // cluster below the floor is a stitch or a speck; one above the ceiling
        // has swallowed something that is not artwork.
        $share = (($box[2] - $box[0] + 1) * ($box[3] - $box[1] + 1)) / ($w * $h);

        if ($share < self::MIN_PRINT_SHARE || $share > self::MAX_PRINT_SHARE) {
            throw new \RuntimeException(sprintf(
                'The print found in the %s image covers %.2f%% of the frame, outside the %.2f%%–%.0f%% '
                . 'a print occupies. Too small is a speck of lint; too large means something that is '
                . 'not artwork — a dark garment, or the stand — was gathered in with it.',
                $which,
                $share * 100,
                self::MIN_PRINT_SHARE * 100,
                self::MAX_PRINT_SHARE * 100,
            ));
        }

        // Back to the image's own scale, rounded outwards.
        $sx = imagesx($img) / $w;
        $sy = imagesy($img) / $h;

        return [
            (int) floor($box[0] * $sx),
            (int) floor($box[1] * $sy),
            (int) min(imagesx($img) - 1, ceil(($box[2] + 1) * $sx)),
            (int) min(imagesy($img) - 1, ceil(($box[3] + 1) * $sy)),
        ];
    }

    /**
     * Grow the winning run of ink into the whole print by absorbing its
     * neighbours, repeatedly, until nothing else is close enough.
     *
     * Repeatedly rather than once, because the print's pieces are a chain: on
     * the Aigner tee the lettering reaches the bears, and the outer letters
     * reach the cluster only after the inner ones have widened it. One pass
     * transplants three bears and the letter G.
     *
     * @param  array{minX:int,minY:int,maxX:int,maxY:int,area:int,edge:int}  $seed
     * @param  list<array{minX:int,minY:int,maxX:int,maxY:int,area:int,edge:int}>  $components
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function grow(array $seed, array $components, int $w, int $h): array
    {
        $box = [$seed['minX'], $seed['minY'], $seed['maxX'], $seed['maxY']];

        $proxyArea = $w * $h;
        $floor     = self::MERGE_FLOOR * max($w, $h);

        $taken = [];

        do {
            $absorbed = false;

            $reach = max(
                self::MERGE_GAP * max($box[2] - $box[0] + 1, $box[3] - $box[1] + 1),
                $floor,
            );

            foreach ($components as $i => $c) {
                if (isset($taken[$i]) || $c['area'] / $proxyArea < self::NOISE_SHARE) {
                    continue;
                }

                if ($this->gapBetween($box, $c) > $reach) {
                    continue;
                }

                $box[0] = min($box[0], $c['minX']);
                $box[1] = min($box[1], $c['minY']);
                $box[2] = max($box[2], $c['maxX']);
                $box[3] = max($box[3], $c['maxY']);

                $taken[$i] = true;
                $absorbed  = true;
            }
        } while ($absorbed);

        return $box;
    }

    /**
     * How far a run of ink lies outside a box, in pixels: 0 when it overlaps.
     *
     * @param  array{0:int,1:int,2:int,3:int}  $box
     * @param  array{minX:int,minY:int,maxX:int,maxY:int}  $c
     */
    private function gapBetween(array $box, array $c): float
    {
        $dx = max(0, max($box[0] - $c['maxX'], $c['minX'] - $box[2]));
        $dy = max(0, max($box[1] - $c['maxY'], $c['minY'] - $box[3]));

        return sqrt($dx * $dx + $dy * $dy);
    }

    /**
     * Which pixels are ink, as a flat array of 0 and 1.
     *
     * The light level is measured rather than assumed — see INK_DROP. The 90th
     * percentile rather than the maximum, so one blown highlight cannot decide
     * what "light" means for the whole frame.
     *
     * @return array<int,int>
     */
    private function inkMask(\GdImage $proxy): array
    {
        $w = imagesx($proxy);
        $h = imagesy($proxy);

        $lumas = [];

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($proxy, $x, $y);

                $lumas[$y * $w + $x] = (int) round(
                    0.299 * (($c >> 16) & 0xFF) + 0.587 * (($c >> 8) & 0xFF) + 0.114 * ($c & 0xFF)
                );
            }
        }

        $sorted = $lumas;
        sort($sorted);

        $light     = $sorted[(int) floor(0.90 * (count($sorted) - 1))];
        $threshold = $light - self::INK_DROP;

        $ink = [];

        foreach ($lumas as $i => $l) {
            $ink[$i] = $l < $threshold ? 1 : 0;
        }

        return $ink;
    }

    /**
     * Connected runs of ink, with the area and edge count of each.
     *
     * Four-way, iterative. A recursive flood fill on a 500-square is a
     * stack overflow waiting for a garment with a large dark panel.
     *
     * @param  array<int,int>  $ink
     * @return list<array{minX:int,minY:int,maxX:int,maxY:int,area:int,edge:int}>
     */
    private function components(array $ink, int $w, int $h): array
    {
        $seen = [];
        $out  = [];

        for ($start = 0, $n = $w * $h; $start < $n; $start++) {
            if ($ink[$start] === 0 || isset($seen[$start])) {
                continue;
            }

            $stack = [$start];
            $seen[$start] = true;

            $minX = $w; $minY = $h; $maxX = -1; $maxY = -1;
            $area = 0; $edge = 0;

            while ($stack) {
                $i = array_pop($stack);

                $x = $i % $w;
                $y = intdiv($i, $w);

                $area++;

                if ($x < $minX) $minX = $x;
                if ($x > $maxX) $maxX = $x;
                if ($y < $minY) $minY = $y;
                if ($y > $maxY) $maxY = $y;

                $isEdge = false;

                foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                    $nx = $x + $dx;
                    $ny = $y + $dy;

                    if ($nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h) {
                        $isEdge = true;

                        continue;
                    }

                    $j = $ny * $w + $nx;

                    if ($ink[$j] === 0) {
                        // Touching fabric: this pixel is on the outline.
                        $isEdge = true;

                        continue;
                    }

                    if (!isset($seen[$j])) {
                        $seen[$j] = true;
                        $stack[]  = $j;
                    }
                }

                if ($isEdge) {
                    $edge++;
                }
            }

            $out[] = compact('minX', 'minY', 'maxX', 'maxY', 'area', 'edge');
        }

        return $out;
    }

    /**
     * Put the photograph's label over the redraw's, turned to match, and
     * report what happened.
     *
     * Never throws and never refuses the whole job: every way out of here
     * leaves the redraw's own label in place, which is what it looks like
     * today.
     *
     * @return array<string,mixed>  metrics, prefixed so they cannot collide
     */
    private function pasteTag(\GdImage $canvas, \GdImage $photo, \GdImage $redraw, float $scale): array
    {
        $photoTag  = $this->findTag($photo);
        $redrawTag = $this->findTag($redraw);

        if ($photoTag === null || $redrawTag === null) {
            return ['tag' => 'no label found in ' . ($photoTag === null ? 'the photograph' : 'the redraw')];
        }

        $turn = $redrawTag['angle'] - $photoTag['angle'];

        if (abs($turn) > self::MAX_LABEL_TILT) {
            return ['tag' => sprintf(
                'left alone: the redraw put the label %.1f degrees off the photograph\'s (limit %.0f)',
                $turn,
                self::MAX_LABEL_TILT,
            )];
        }

        $target = $this->scaleBox($redrawTag['box'], $scale);

        $tw = $target[2] - $target[0] + 1;
        $th = $target[3] - $target[1] + 1;

        $pw = $photoTag['box'][2] - $photoTag['box'][0] + 1;
        $ph = $photoTag['box'][3] - $photoTag['box'][1] + 1;

        // Nothing to gain if the redraw already renders the label from more
        // pixels than the photograph can supply.
        $gain = ($pw * $ph) / max(1, $tw * $th);

        if ($gain < 1.05) {
            return ['tag' => sprintf('left alone: only %.2fx the redraw\'s label pixels', $gain)];
        }

        try {
            $this->paste(
                $canvas,
                $photo,
                $photoTag['box'],
                $target,
                $this->levelShift($photo, $photoTag['box'], $canvas, $target),
                $turn,
            );
        } catch (\Throwable $e) {
            return ['tag' => 'left alone: ' . $e->getMessage()];
        }

        return [
            'tag'        => sprintf('real label kept, turned %+.1f degrees to match', $turn),
            'tag_gain'   => round($gain, 2),
            'tag_turn'   => round($turn, 2),
        ];
    }

    /**
     * The woven label's box and the angle it lies at, or null when there is no
     * label to be found.
     *
     * Keyed on hue rather than darkness — see LABEL_RED_OVER_GREEN — and the
     * angle comes from the second moments of the keyed pixels, which for a
     * rectangular label is the direction of its long side. Null rather than an
     * exception: a garment with no label is ordinary, and losing the print
     * transplant over it would be a poor trade.
     *
     * @return array{box: array{0:int,1:int,2:int,3:int}, angle: float, pixels: int}|null
     */
    private function findTag(\GdImage $img): ?array
    {
        $proxy = $this->proxy($img, self::DETECT_EDGE);

        $w = imagesx($proxy);
        $h = imagesy($proxy);

        $keyed = [];

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($proxy, $x, $y);

                $r = ($c >> 16) & 0xFF;
                $g = ($c >> 8) & 0xFF;
                $b = $c & 0xFF;

                $keyed[$y * $w + $x] = ($r < self::LABEL_MAX_RED
                    && $r - $g >= self::LABEL_RED_OVER_GREEN
                    && abs($g - $b) <= self::LABEL_GREEN_BLUE_SPREAD) ? 1 : 0;
            }
        }

        $components = $this->components($keyed, $w, $h);

        imagedestroy($proxy);

        // The largest run of label-coloured pixels. Unlike the print, a label
        // is one solid patch, so there is nothing to cluster.
        $best = null;

        foreach ($components as $c) {
            if ($best === null || $c['area'] > $best['area']) {
                $best = $c;
            }
        }

        if ($best === null || $best['area'] / ($w * $h) < self::MIN_LABEL_SHARE) {
            return null;
        }

        $sx = imagesx($img) / $w;
        $sy = imagesy($img) / $h;

        return [
            'box' => [
                (int) floor($best['minX'] * $sx),
                (int) floor($best['minY'] * $sy),
                (int) min(imagesx($img) - 1, ceil(($best['maxX'] + 1) * $sx)),
                (int) min(imagesy($img) - 1, ceil(($best['maxY'] + 1) * $sy)),
            ],
            'angle'  => $this->tagAngle($img),
            'pixels' => (int) round($best['area'] * $sx * $sy),
        ];
    }

    /**
     * Which way the label lies, in degrees, from the spread of its own pixels.
     *
     * Measured at full size rather than on the proxy: the label is small, and a
     * few pixels of quantisation on a short axis move the angle more than they
     * would move a box.
     */
    private function tagAngle(\GdImage $img): float
    {
        $w = imagesx($img);
        $h = imagesy($img);

        $n = 0;
        $mx = 0.0;
        $my = 0.0;
        $pts = [];

        $step = max(1, (int) floor(max($w, $h) / 600));

        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                $c = imagecolorat($img, $x, $y);

                $r = ($c >> 16) & 0xFF;
                $g = ($c >> 8) & 0xFF;
                $b = $c & 0xFF;

                if ($r >= self::LABEL_MAX_RED
                    || $r - $g < self::LABEL_RED_OVER_GREEN
                    || abs($g - $b) > self::LABEL_GREEN_BLUE_SPREAD) {
                    continue;
                }

                $pts[] = [$x, $y];
                $mx += $x;
                $my += $y;
                $n++;
            }
        }

        if ($n < 20) {
            return 0.0;
        }

        $mx /= $n;
        $my /= $n;

        $sxx = 0.0;
        $syy = 0.0;
        $sxy = 0.0;

        foreach ($pts as [$x, $y]) {
            $dx = $x - $mx;
            $dy = $y - $my;

            $sxx += $dx * $dx;
            $syy += $dy * $dy;
            $sxy += $dx * $dy;
        }

        return 0.5 * atan2(2 * $sxy, $sxx - $syy) * 180 / M_PI;
    }

    // ── Measuring ──────────────────────────────────────────────────────────

    private function measure(
        array $photoPrint,
        array $redrawPrint,
        array $target,
        float $scale,
        \GdImage $photo,
        \GdImage $canvas,
    ): array {
        $pw = $photoPrint[2] - $photoPrint[0] + 1;
        $ph = $photoPrint[3] - $photoPrint[1] + 1;
        $tw = $target[2] - $target[0] + 1;
        $th = $target[3] - $target[1] + 1;

        $photoAspect  = $pw / max(1, $ph);
        $targetAspect = $tw / max(1, $th);

        return [
            'output_size'    => imagesx($canvas) . 'x' . imagesy($canvas),
            'redraw_upscale' => round($scale, 3),
            'photo_print'    => $pw . 'x' . $ph,
            'target_print'   => $tw . 'x' . $th,

            /*
             * What the transplant actually buys, and the number to judge it on:
             * how many real photographed pixels the print is rendered from,
             * against what the redraw offered at the same output size.
             */
            'print_detail_gain' => round(($tw * $th) > 0
                ? min($pw, $tw) * min($ph, $th) / (($redrawPrint[2] - $redrawPrint[0] + 1) * ($redrawPrint[3] - $redrawPrint[1] + 1))
                : 0, 2),

            // How differently the redraw drew the print. A print is flat
            // artwork on a flat chest, so this should be small even though the
            // garment around it was re-proportioned.
            'print_aspect_shift' => round(abs($targetAspect / max(0.0001, $photoAspect) - 1), 4),

            'level_shift' => $this->levelShift($photo, $photoPrint, $canvas, $target),
        ];
    }

    /**
     * How far the photograph's fabric has to move to match the redraw's, in
     * levels, measured on the light pixels around the print.
     *
     * The light pixels only: the ink is the same near-black in both and would
     * dilute the very difference being measured.
     */
    private function levelShift(\GdImage $photo, array $from, \GdImage $canvas, array $to): int
    {
        $a = $this->lightMean($photo, $from);
        $b = $this->lightMean($canvas, $to);

        if ($a === null || $b === null) {
            return 0;
        }

        return (int) round($b - $a);
    }

    private function lightMean(\GdImage $img, array $box): ?float
    {
        [$x0, $y0, $x1, $y1] = $box;

        $sum = 0.0;
        $n   = 0;

        $step = max(1, (int) floor(($x1 - $x0 + 1) / 120));

        for ($y = $y0; $y <= $y1; $y += $step) {
            for ($x = $x0; $x <= $x1; $x += $step) {
                $c = imagecolorat($img, $x, $y);

                $l = 0.299 * (($c >> 16) & 0xFF) + 0.587 * (($c >> 8) & 0xFF) + 0.114 * ($c & 0xFF);

                // Fabric, not ink.
                if ($l < 170) {
                    continue;
                }

                $sum += $l;
                $n++;
            }
        }

        return $n === 0 ? null : $sum / $n;
    }

    /** @return array{0:bool,1:string} */
    private function judge(array $m): array
    {
        if ($m['print_aspect_shift'] > 0.12) {
            return [false, sprintf(
                'The redraw drew the print at %.1f%% different proportions (limit 12%%). '
                . 'Stretching the photograph\'s print to fit that would distort the artwork, '
                . 'which is the one thing this is meant to protect.',
                $m['print_aspect_shift'] * 100,
            )];
        }

        if (abs($m['level_shift']) > self::MAX_LEVEL_SHIFT) {
            return [false, sprintf(
                'The redraw\'s fabric is %d levels away from the photograph\'s (limit %d). '
                . 'The join would either show as a patch or need the print pushed far enough to '
                . 'change its colour.',
                $m['level_shift'],
                self::MAX_LEVEL_SHIFT,
            )];
        }

        if ($m['print_detail_gain'] < 1.2) {
            return [false, sprintf(
                'The transplant would render the print from only %.2fx the redraw\'s pixels. '
                . 'Not worth the seam — either the output canvas is too small to carry the '
                . 'photograph\'s detail, or the redraw was already close to it.',
                $m['print_detail_gain'],
            )];
        }

        return [true, sprintf(
            'The print is rendered from %.2fx the redraw\'s pixels and is the real artwork rather '
            . 'than a generated impression of it. Fabric matched to within %d levels.',
            $m['print_detail_gain'],
            abs($m['level_shift']),
        )];
    }

    // ── The transplant ─────────────────────────────────────────────────────

    /**
     * Lay the photograph's print over the redraw's, feathered, in place.
     *
     * Resampled in one step from the photograph's own pixels to the size the
     * redraw drew the print at. Going via an intermediate would soften the only
     * thing here worth having.
     */
    private function paste(
        \GdImage $canvas,
        \GdImage $photo,
        array $from,
        array $to,
        int $levelShift,
        float $turn = 0.0,
    ): void {
        [$sx0, $sy0, $sx1, $sy1] = $from;
        [$dx0, $dy0, $dx1, $dy1] = $to;

        $sw = $sx1 - $sx0 + 1;
        $sh = $sy1 - $sy0 + 1;
        $dw = $dx1 - $dx0 + 1;
        $dh = $dy1 - $dy0 + 1;

        // A margin of feather around the print, so the ramp sits on plain
        // fabric rather than on the artwork's own edge.
        $pad = (int) round(max($dw, $dh) * self::FEATHER);

        $patch = imagecreatetruecolor($dw + 2 * $pad, $dh + 2 * $pad);

        /*
         * The padded patch is taken from the photograph in the same one step,
         * so the fabric ring around the print is real fabric at the right
         * scale — it is what the ramp blends through.
         */
        $ratio = $dw / max(1, $sw);
        $sPad  = (int) round($pad / max(0.0001, $ratio));

        if (abs($turn) < 0.5) {
            imagecopyresampled(
                $patch,
                $photo,
                0,
                0,
                $sx0 - $sPad,
                $sy0 - $sPad,
                $dw + 2 * $pad,
                $dh + 2 * $pad,
                $sw + 2 * $sPad,
                $sh + 2 * $sPad,
            );
        } else {
            /*
             * A label has to be turned to lie the way the redraw's collar does,
             * and turning brings two problems that decide the order of work
             * here.
             *
             * A rotation leaves fill colour in the corners, and the corners are
             * exactly where the shading is measured from — so the turn happens
             * on a deliberately oversized crop, and the padded patch is cut
             * from the middle of the result. Everything the ramp and the field
             * touch is then real fabric.
             *
             * And the turn happens after the resample, not before: rotating the
             * photograph's own pixels first and resampling the result would
             * interpolate the lettering twice.
             */
            imagedestroy($patch);

            $margin = 1.6;

            $bigW = (int) round(($dw + 2 * $pad) * $margin);
            $bigH = (int) round(($dh + 2 * $pad) * $margin);

            $big = imagecreatetruecolor($bigW, $bigH);

            $srcW = (int) round(($sw + 2 * $sPad) * $margin);
            $srcH = (int) round(($sh + 2 * $sPad) * $margin);

            imagecopyresampled(
                $big,
                $photo,
                0,
                0,
                (int) round($sx0 - $sPad - ($srcW - ($sw + 2 * $sPad)) / 2),
                (int) round($sy0 - $sPad - ($srcH - ($sh + 2 * $sPad)) / 2),
                $bigW,
                $bigH,
                $srcW,
                $srcH,
            );

            // GD turns anticlockwise for a positive angle; the field of view
            // here is measured clockwise-positive, so the sign flips.
            $turned = imagerotate($big, -$turn, imagecolorallocate($big, 255, 255, 255));
            imagedestroy($big);

            if ($turned === false) {
                throw new \RuntimeException('Could not turn the label to match the redraw.');
            }

            $patch = imagecreatetruecolor($dw + 2 * $pad, $dh + 2 * $pad);

            imagecopy(
                $patch,
                $turned,
                0,
                0,
                (int) round((imagesx($turned) - ($dw + 2 * $pad)) / 2),
                (int) round((imagesy($turned) - ($dh + 2 * $pad)) / 2),
                $dw + 2 * $pad,
                $dh + 2 * $pad,
            );

            imagedestroy($turned);
        }

        $pw = imagesx($patch);
        $ph = imagesy($patch);

        $ox = $dx0 - $pad;
        $oy = $dy0 - $pad;

        $cw = imagesx($canvas);
        $ch = imagesy($canvas);

        /*
         * ── Matching the redraw's shading ──────────────────────────────────
         *
         * A single brightness offset for the whole patch is not enough, and the
         * first version proved it on a real pair: the redraw models the shirt
         * with sleeve and hem shading, while the photograph's chest is evenly
         * lit, so a flat patch of real fabric left a faint rectangle visible
         * around the print however well its average was matched.
         *
         * The fix is to keep the patch's fine detail — which is the artwork,
         * the only reason any of this exists — and take its *low* frequencies
         * from the redraw underneath. Where the two agree by construction at
         * every scale coarser than the print's own marks, there is no edge left
         * to see.
         */
        $under = imagecreatetruecolor($pw, $ph);
        imagefilledrectangle($under, 0, 0, $pw - 1, $ph - 1, imagecolorallocate($under, 255, 255, 255));

        $sx = max(0, $ox);
        $sy = max(0, $oy);

        $copyW = min($pw - ($sx - $ox), $cw - $sx);
        $copyH = min($ph - ($sy - $oy), $ch - $sy);

        if ($copyW > 0 && $copyH > 0) {
            imagecopy($under, $canvas, $sx - $ox, $sy - $oy, $sx, $sy, $copyW, $copyH);
        }

        $field = $this->shadingField($patch, $under);

        imagedestroy($under);

        for ($y = 0; $y < $ph; $y++) {
            $cy = $oy + $y;

            if ($cy < 0 || $cy >= $ch) {
                continue;
            }

            for ($x = 0; $x < $pw; $x++) {
                $cx = $ox + $x;

                if ($cx < 0 || $cx >= $cw) {
                    continue;
                }

                $a = $this->featherAt($x, $y, $pw, $ph, $pad);

                if ($a <= 0.0) {
                    continue;
                }

                $p = imagecolorat($patch, $x, $y);
                $q = imagecolorat($canvas, $cx, $cy);

                // The patch's detail, carried on the redraw's own shading.
                [$dr, $dg, $db] = $this->shadingAt($field, $x, $y, $pw, $ph);

                $pr = $this->clamp((($p >> 16) & 0xFF) + $dr);
                $pg = $this->clamp((($p >> 8) & 0xFF) + $dg);
                $pb = $this->clamp(($p & 0xFF) + $db);

                if ($a >= 1.0) {
                    imagesetpixel($canvas, $cx, $cy, ($pr << 16) | ($pg << 8) | $pb);

                    continue;
                }

                $r = (int) round($pr * $a + (($q >> 16) & 0xFF) * (1 - $a));
                $g = (int) round($pg * $a + (($q >> 8) & 0xFF) * (1 - $a));
                $b = (int) round($pb * $a + ($q & 0xFF) * (1 - $a));

                imagesetpixel($canvas, $cx, $cy, ($r << 16) | ($g << 8) | $b);
            }
        }

        imagedestroy($patch);
    }

    /**
     * How far the photograph's fabric sits from the redraw's, measured at the
     * patch's four corners: the field the print is carried on.
     *
     * ── Why corners and not a blur ─────────────────────────────────────────
     *
     * Blurring both sides and taking the difference was the obvious answer and
     * it was wrong. A blur of the patch includes the print, a blur of the
     * redraw includes the redraw's own differently-placed print, and where the
     * two disagree the correction blooms: on a real pair it put a white halo
     * around every bear and every letter. The very thing being corrected must
     * not be part of the measurement.
     *
     * The corners of the padded patch are the feather margin — plain fabric in
     * both images by construction, since the print is what the padding was
     * placed around. Measured there and interpolated across, the field can
     * describe a gradient without ever seeing a mark of ink.
     *
     * Light pixels only, and a corner with too little fabric in it borrows the
     * average of the others, so a print that runs close to one edge cannot drag
     * that corner.
     *
     * @return array{0:array{0:float,1:float,2:float},1:array{0:float,1:float,2:float},2:array{0:float,1:float,2:float},3:array{0:float,1:float,2:float}}
     *         top-left, top-right, bottom-left, bottom-right
     */
    private function shadingField(\GdImage $patch, \GdImage $under): array
    {
        $w = imagesx($patch);
        $h = imagesy($patch);

        // A fifth of each side: inside the feather margin on a typical print,
        // and large enough to average the fabric's own grain away.
        $bw = max(2, (int) round($w * 0.20));
        $bh = max(2, (int) round($h * 0.20));

        $corners = [
            [0, 0],
            [$w - $bw, 0],
            [0, $h - $bh],
            [$w - $bw, $h - $bh],
        ];

        $found = [];
        $sum   = [0.0, 0.0, 0.0];
        $seen  = 0;

        foreach ($corners as $k => [$x0, $y0]) {
            $acc = [0.0, 0.0, 0.0];
            $n   = 0;

            for ($y = $y0; $y < $y0 + $bh; $y += 2) {
                for ($x = $x0; $x < $x0 + $bw; $x += 2) {
                    $p = imagecolorat($patch, $x, $y);

                    $pr = ($p >> 16) & 0xFF;
                    $pg = ($p >> 8) & 0xFF;
                    $pb = $p & 0xFF;

                    // Fabric only. Ink here would measure the print, not the light.
                    if (0.299 * $pr + 0.587 * $pg + 0.114 * $pb < 150) {
                        continue;
                    }

                    $u = imagecolorat($under, $x, $y);

                    $acc[0] += (($u >> 16) & 0xFF) - $pr;
                    $acc[1] += (($u >> 8) & 0xFF) - $pg;
                    $acc[2] += ($u & 0xFF) - $pb;
                    $n++;
                }
            }

            if ($n > 8) {
                $found[$k] = [$acc[0] / $n, $acc[1] / $n, $acc[2] / $n];

                foreach ([0, 1, 2] as $c) {
                    $sum[$c] += $found[$k][$c];
                }

                $seen++;
            }
        }

        $fallback = $seen > 0
            ? [$sum[0] / $seen, $sum[1] / $seen, $sum[2] / $seen]
            : [0.0, 0.0, 0.0];

        return [
            $found[0] ?? $fallback,
            $found[1] ?? $fallback,
            $found[2] ?? $fallback,
            $found[3] ?? $fallback,
        ];
    }

    /**
     * The shading field at one pixel, interpolated between the four corners.
     *
     * @param  array{0:array{0:float,1:float,2:float},1:array{0:float,1:float,2:float},2:array{0:float,1:float,2:float},3:array{0:float,1:float,2:float}}  $field
     * @return array{0:int,1:int,2:int}
     */
    private function shadingAt(array $field, int $x, int $y, int $w, int $h): array
    {
        $u = $w > 1 ? $x / ($w - 1) : 0.0;
        $v = $h > 1 ? $y / ($h - 1) : 0.0;

        $out = [];

        foreach ([0, 1, 2] as $c) {
            $top    = $field[0][$c] * (1 - $u) + $field[1][$c] * $u;
            $bottom = $field[2][$c] * (1 - $u) + $field[3][$c] * $u;

            $out[$c] = (int) round($top * (1 - $v) + $bottom * $v);
        }

        return $out;
    }

    /**
     * How much of the patch to use at one of its pixels: 1 inside the print,
     * ramping to 0 across the padding.
     */
    private function featherAt(int $x, int $y, int $w, int $h, int $pad): float
    {
        if ($pad <= 0) {
            return 1.0;
        }

        // Distance into the patch from whichever edge is nearest.
        $d = min($x, $y, $w - 1 - $x, $h - 1 - $y);

        if ($d >= $pad) {
            return 1.0;
        }

        $t = $d / $pad;

        // Smooth at both ends, so neither the outer edge nor the point where
        // the ramp reaches full strength shows as a line.
        return $t * $t * (3 - 2 * $t);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function decode(string $bytes, string $which): \GdImage
    {
        $img = @imagecreatefromstring($bytes);

        if (!$img) {
            throw new \RuntimeException("Could not read the {$which} image.");
        }

        $w = imagesx($img);
        $h = imagesy($img);

        // Flattened onto white so a transparent redraw and an opaque
        // photograph can be measured the same way.
        $flat  = imagecreatetruecolor($w, $h);
        imagefilledrectangle($flat, 0, 0, $w - 1, $h - 1, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img);

        return $flat;
    }

    /** The redraw at the output canvas's size. */
    private function enlarge(\GdImage $img, int $targetEdge): \GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);

        $scale = $targetEdge / max($w, $h);

        $out = imagescale($img, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));

        if ($out === false) {
            throw new \RuntimeException('Could not enlarge the redraw to the output size.');
        }

        return $out;
    }

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

    /** @return array{0:int,1:int,2:int,3:int} */
    private function scaleBox(array $box, float $scale): array
    {
        return [
            (int) round($box[0] * $scale),
            (int) round($box[1] * $scale),
            (int) round($box[2] * $scale),
            (int) round($box[3] * $scale),
        ];
    }

    private function clamp(int $v): int
    {
        return max(0, min(255, $v));
    }

    private function encode(\GdImage $img): string
    {
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }
}
