<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Intervention\Image\Encoders\AvifEncoder;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;

class ImageProcessingService
{
    private const START_QUALITY = 100;

    /**
     * How hard to sharpen an enlarged image, on Intervention's 1-100 scale.
     * Mild on purpose: the subjects here are bright metal and stones on white,
     * where a halo shows long before a garment's would.
     */
    private const SHARPEN_LEVEL = 12;

    /**
     * At or above this on every channel, a pixel is background rather than
     * product. Deliberately close to pure white: these are pale rings on white,
     * where trimming a real edge is far worse than keeping a band of nothing.
     */
    private const BACKGROUND_WHITE = 252;
    private const MIN_QUALITY   = 30;

    /** Quarter and half turns offered for straightening an input photo. */
    public const INPUT_ROTATIONS = [
        ''      => 'Leave as it is',
        'right' => 'Turn right 90°',
        'left'  => 'Turn left 90°',
        '180'   => 'Turn upside down 180°',
    ];

    /**
     * Shopify rejects any image above 20 megapixels with a 422, independent of
     * file size — a heavily compressed 6000×4000 shot can sit well under the
     * byte limit and still be refused. Stay just under the line.
     */
    private const MAX_PIXELS = 19_500_000;

    private ImageManager $manager;

    public function __construct()
    {
        // Imagick handles CMYK, TIFF, WebP and all edge cases GD cannot.
        // Fall back to GD if the extension is absent.
        $driver = extension_loaded('imagick') ? new ImagickDriver() : new GdDriver();
        $this->manager = new ImageManager($driver);
    }

    /**
     * Decode bytes into an image, in a colorspace the rest of the library can
     * name.
     *
     * Intervention asks Imagick what colorspace an image is in and maps the
     * answer onto its own small list — CMYK, sRGB, RGB, HSL, HSB. An AVIF does
     * not decode into any of them: Imagick reports it as YCbCr, the analyzer
     * runs out of arms, and the whole operation dies with "Failed to analyze
     * colorspace" before a single line of our own work has run. That is what
     * put eleven .avif files on the failed list with an error that says nothing
     * about AVIF.
     *
     * Only the unmappable ones are touched, so CMYK still reaches the CMYK
     * handling that was deliberately put there. And it is a conversion, not a
     * relabel — transformImageColorspace moves the pixels, where setColorspace
     * would only rename what they claim to be and leave the colours wrong.
     *
     * GD has no such problem and no such API, so this is a no-op there.
     */
    private function decode(string $imageContent): ImageInterface
    {
        $img = $this->manager->decode($imageContent);

        if (!extension_loaded('imagick')) {
            return $img;
        }

        $native = $img->core()->native();

        if (!$native instanceof \Imagick) {
            return $img;
        }

        $mappable = [
            \Imagick::COLORSPACE_CMYK,
            \Imagick::COLORSPACE_SRGB,
            \Imagick::COLORSPACE_RGB,
            \Imagick::COLORSPACE_HSL,
            \Imagick::COLORSPACE_HSB,
        ];

        try {
            if (!in_array($native->getImageColorspace(), $mappable, true)) {
                $native->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
            }
        } catch (\Throwable $e) {
            // Better a picture in an odd colorspace than no picture: the
            // analyzer may still cope, and if it does not the caller's own
            // error is more informative than one thrown from in here.
            Log::warning('ImageProcessingService: could not normalise colorspace', ['error' => $e->getMessage()]);
        }

        return $img;
    }

    /**
     * Trim the empty background from around the product.
     *
     * A supplier photograph is mostly white: the ring in these files occupies
     * about a fifth of the frame, and the other four fifths are paid for twice
     * over. Once because Photoroom's upscaler refuses an input over a million
     * pixels, and a 1146-square of mostly nothing is over it — so the two
     * largest supplier files could not be upscaled at all, while the smallest
     * could. And again because Photoroom's fit will shrink a subject onto the
     * canvas but not enlarge one, so a product that is small in its own frame
     * stays small in the output however the canvas is set.
     *
     * Cropping to the product answers both: the pixel count falls inside the
     * best upscale model's ceiling, and the subject fills enough of the frame
     * to be framed rather than left where it was.
     *
     * The margin is generous and so is the white threshold. This runs on white
     * jewellery photographed on white, where the cost of trimming a pale edge
     * is a clipped product and the cost of leaving a band of background is
     * nothing at all.
     */
    public function cropToSubject(string $imageContent, float $margin = 0.25): string
    {
        try {
            $img = $this->decode($imageContent);
            $w   = $img->width();
            $h   = $img->height();

            if ($w < 50 || $h < 50) {
                return $imageContent;
            }

            $box = $this->subjectBox($imageContent, $w, $h);

            if ($box === null) {
                return $imageContent;
            }

            [$minX, $minY, $maxX, $maxY] = $box;

            $boxW = $maxX - $minX + 1;
            $boxH = $maxY - $minY + 1;
            $area = ($boxW * $boxH) / ($w * $h);

            /*
             * Two ways the detection can be wrong, and both look like a number
             * rather than an error. A box covering almost everything means the
             * background was not white and there is nothing to trim; a box
             * covering almost nothing means a speck of dust was mistaken for
             * the product. Neither is worth cropping on.
             */
            if ($area > 0.85 || $area < 0.005) {
                return $imageContent;
            }

            $pad = (int) round(max($boxW, $boxH) * max(0.0, $margin));

            $x0 = max(0, $minX - $pad);
            $y0 = max(0, $minY - $pad);
            $x1 = min($w - 1, $maxX + $pad);
            $y1 = min($h - 1, $maxY + $pad);

            return $img->crop($x1 - $x0 + 1, $y1 - $y0 + 1, $x0, $y0)
                ->encode(new PngEncoder())
                ->toString();
        } catch (\Throwable $e) {
            // An untrimmed picture is still a picture.
            Log::warning('ImageProcessingService: could not crop to subject', ['error' => $e->getMessage()]);

            return $imageContent;
        }
    }

    /**
     * The product's bounding box, or null when nothing stands out.
     *
     * Measured on a small copy. Scanning a 1146-square pixel by pixel in PHP
     * costs seconds per image and the box only has to be right to within a few
     * pixels, because a quarter of the subject's size is added around it
     * afterwards.
     *
     * @return array{0:int,1:int,2:int,3:int}|null
     */
    private function subjectBox(string $imageContent, int $width, int $height): ?array
    {
        $proxyEdge = 240;

        $proxy = @imagecreatefromstring(
            $this->decode($imageContent)->scaleDown($proxyEdge, $proxyEdge)->encode(new PngEncoder())->toString(),
        );

        if (!$proxy) {
            return null;
        }

        $pw = imagesx($proxy);
        $ph = imagesy($proxy);

        $minX = $pw; $minY = $ph; $maxX = -1; $maxY = -1;

        for ($y = 0; $y < $ph; $y++) {
            for ($x = 0; $x < $pw; $x++) {
                $rgba = imagecolorat($proxy, $x, $y);

                // Transparent counts as background, the same as white does.
                if ((($rgba >> 24) & 0x7F) > 100) {
                    continue;
                }

                if ((($rgba >> 16) & 0xFF) >= self::BACKGROUND_WHITE
                    && (($rgba >> 8) & 0xFF) >= self::BACKGROUND_WHITE
                    && ($rgba & 0xFF) >= self::BACKGROUND_WHITE) {
                    continue;
                }

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

        // Back to the original's scale, rounded outwards so the box can only
        // grow with the conversion, never clip.
        $sx = $width / $pw;
        $sy = $height / $ph;

        return [
            (int) floor($minX * $sx),
            (int) floor($minY * $sy),
            (int) min($width - 1, ceil(($maxX + 1) * $sx)),
            (int) min($height - 1, ceil(($maxY + 1) * $sy)),
        ];
    }

    /**
     * Restore the edge acuity an enlargement costs.
     *
     * Interpolation invents the pixels between the ones that existed, and it
     * makes them by averaging — which is why an enlarged photograph looks soft
     * even when nothing is technically lost. Sharpening does not put detail
     * back, and nothing can; it restores the local contrast at edges that the
     * averaging flattened, which is what the eye reads as sharpness.
     *
     * Deliberately mild. Past about 15 the halo around a bright stone on white
     * starts to show, and a ring is mostly bright edges on white.
     *
     * Only worth spending on an image that was enlarged. Sharpening one that
     * came back at or above its original size is amplifying grain and JPEG
     * artefacts for nothing.
     */
    public function sharpenAfterEnlargement(string $imageContent, int $level = self::SHARPEN_LEVEL): string
    {
        try {
            return $this->decode($imageContent)
                ->sharpen(max(1, min(20, $level)))
                ->encode(new PngEncoder())
                ->toString();
        } catch (\Throwable $e) {
            // A soft picture beats no picture.
            Log::warning('ImageProcessingService: could not sharpen', ['error' => $e->getMessage()]);

            return $imageContent;
        }
    }

    /**
     * Was this image enlarged on its way to the canvas, and therefore worth
     * sharpening? Compares what went out with what came back.
     */
    public function wasEnlarged(string $before, string $after): bool
    {
        $in  = @getimagesizefromstring($before);
        $out = @getimagesizefromstring($after);

        if (!$in || !$out) {
            return false;
        }

        return max((int) $out[0], (int) $out[1]) > max((int) $in[0], (int) $in[1]);
    }

    /**
     * Bring anything deeper than 8 bits per channel back down to 8.
     *
     * Photoroom refuses more than 8-bit outright: "Images deeper than 8-bit are
     * not supported (received 10-bit)". Some of the AVIFs suppliers send are
     * 10-bit HDR, and depth is invisible to every other check we make — the
     * file is small, its dimensions are fine, it decodes cleanly, and it is
     * rejected anyway.
     *
     * Re-encoded as PNG rather than JPEG. Several of these carry transparency,
     * and a JPEG would flatten it onto black before Photoroom ever saw the
     * picture. PNG is bigger, but the caller's size check runs after this and
     * will shrink it if it has to.
     *
     * GD cannot produce a file deeper than 8 bits, so without Imagick there is
     * nothing here to do.
     */
    public function capBitDepth(string $imageContent, int $maxDepth = 8): string
    {
        if (!extension_loaded('imagick')) {
            return $imageContent;
        }

        $imagick = null;

        try {
            $imagick = new \Imagick();
            $imagick->readImageBlob($imageContent);

            if ($imagick->getImageDepth() <= $maxDepth) {
                return $imageContent;
            }

            $imagick->setImageDepth($maxDepth);
            $imagick->setImageFormat('png');

            return $imagick->getImageBlob();
        } catch (\Throwable $e) {
            // Worth trying the original: Photoroom's own rejection names the
            // problem more usefully than a failure thrown from in here.
            Log::warning('ImageProcessingService: could not cap bit depth', ['error' => $e->getMessage()]);

            return $imageContent;
        } finally {
            $imagick?->clear();
        }
    }

    /**
     * Shrink an image for sending to a vision AI API — vision models don't need
     * full resolution to identify colors/textures/details, and smaller images
     * mean fewer tokens and faster uploads. Never upscales. Does not touch the
     * original file — caller must have their own copy of $imageContent.
     */
    public function scaleDownForAnalysis(string $imageContent, int $maxDimension = 1024, int $maxBytes = 1_500_000): string
    {
        $img = $this->decode($imageContent);
        $img->scaleDown($maxDimension, $maxDimension);
        $result = $img->encode(new JpegEncoder(quality: 85))->toString();

        if (strlen($result) <= $maxBytes) {
            return $result;
        }

        return $this->compressOnly($result, $maxBytes);
    }

    /**
     * Rotate an image so its pixels sit the way the photographer saw them.
     *
     * Cameras and phones very often leave the sensor data landscape and record
     * the rotation as an EXIF flag instead. Viewers honour that flag, so the
     * file looks upright on a laptop — but the moment anything decodes the
     * pixels and writes them out again the flag is dropped and the picture
     * turns on its side. That is why a shirt shot upright arrives lying down.
     *
     * Returns the original bytes untouched when there is nothing to correct, so
     * an already-upright photo is never re-encoded for no reason.
     */
    public function normalizeOrientation(string $imageContent): string
    {
        $orientation = $this->readOrientation($imageContent);

        // 1 is "already upright"; null means no EXIF to read (PNG, WebP, or a
        // JPEG that never carried the tag).
        if ($orientation === null || $orientation === 1) {
            return $imageContent;
        }

        // orient() applies the flag to the pixels and clears it, so every later
        // decode in the pipeline sees an image that needs no interpretation.
        return $this->decode($imageContent)
            ->orient()
            ->encode(new JpegEncoder(quality: 95))
            ->toString();
    }

    /**
     * Turn an image a quarter or a half circle.
     *
     * normalizeOrientation() can only undo a rotation the camera bothered to
     * record. A garment photographed lying across the frame — a mannequin on
     * its side, a rail shot sideways so the whole length fits — produces
     * genuinely landscape pixels with no flag to read, and everything
     * downstream then has to guess which way is up. Ghost mannequin and flat
     * lay guess worst of all: they redraw the garment, and a sideways subject
     * is what makes them invent a front where the photo showed a back.
     *
     * $onlyWhenWide leaves portrait shots alone, so one setting can be applied
     * to a folder where only some of the photos are on their side.
     */
    public function rotate(string $imageContent, string $direction, bool $onlyWhenWide = false): string
    {
        // A positive angle turns the picture clockwise — Intervention negates
        // it for GD, whose own imagerotate() goes the other way. Verified by
        // where a corner lands, not by reading either set of docs.
        $degrees = match ($direction) {
            'right' => 90,
            'left'  => -90,
            '180'   => 180,
            default => 0,
        };

        if ($degrees === 0 || ($onlyWhenWide && !$this->isWide($imageContent))) {
            return $imageContent;
        }

        // No background is exposed by a quarter or half turn, so the fill
        // colour rotate() would use never reaches a pixel.
        $img = $this->decode($imageContent)->rotate($degrees);

        // A cutout has to stay a PNG on the way out: JPEG has no alpha, so
        // re-encoding one here would hand Photoroom a subject sitting on
        // black before it ever got the chance to mask it.
        return $this->isPng($imageContent)
            ? $img->encode(new PngEncoder())->toString()
            : $img->encode(new JpegEncoder(quality: 95))->toString();
    }

    /**
     * Cut a band off the top and/or bottom of a photo before it is edited.
     *
     * This is the non-generative answer to a mannequin in shot. Background
     * removal keeps the garment pixel-for-pixel, but it cuts out the mannequin
     * along with it — the torso form, the legs, the stand. Ghost mannequin does
     * remove those, at the price of redrawing the garment into something that
     * is no longer the product photographed.
     *
     * A studio batch is shot at one distance against one mannequin, so where
     * the garment ends is the same fraction down every frame. Setting that
     * fraction once trims the whole folder, and nothing is invented.
     *
     * Fractions of the height, capped at 0.4 each so a trim can never take more
     * of the picture than it leaves.
     */
    public function trimEdges(string $imageContent, float $top = 0.0, float $bottom = 0.0): string
    {
        $top    = max(0.0, min(0.4, $top));
        $bottom = max(0.0, min(0.4, $bottom));

        if ($top + $bottom <= 0.0) {
            return $imageContent;
        }

        $img    = $this->decode($imageContent);
        $height = $img->height();
        $offset = (int) round($height * $top);
        $keep   = $height - $offset - (int) round($height * $bottom);

        // Unreachable at a 0.4 cap on each side, but cropping to nothing throws
        // and a guard costs less than the exception it prevents.
        if ($keep < 1) {
            return $imageContent;
        }

        // Offsets run from the top-left, so this keeps the band between the two
        // trims rather than a centred crop of that height.
        $img->crop($img->width(), $keep, 0, $offset);

        return $this->isPng($imageContent)
            ? $img->encode(new PngEncoder())->toString()
            : $img->encode(new JpegEncoder(quality: 95))->toString();
    }

    private function isPng(string $imageContent): bool
    {
        return str_starts_with($imageContent, "\x89PNG\r\n\x1a\n");
    }

    private function isWide(string $imageContent): bool
    {
        // Header only — most of a portrait batch is answered here without ever
        // decoding a full-size photo.
        $info = @getimagesizefromstring($imageContent);

        if ($info) {
            return (int) $info[0] > (int) $info[1];
        }

        // Unreadable header (CMYK TIFF and friends) — pay for the decode.
        $img = $this->decode($imageContent);

        return $img->width() > $img->height();
    }

    private function readOrientation(string $imageContent): ?int
    {
        if (!function_exists('exif_read_data')) {
            return null;
        }

        // A stream rather than a data: URI — the URI form base64-encodes the
        // whole file to read two bytes, which on a 10 MB photo is 13 MB of
        // pointless memory.
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            return null;
        }

        try {
            fwrite($stream, $imageContent);
            rewind($stream);

            $exif = @exif_read_data($stream);
        } catch (\Throwable) {
            return null;
        } finally {
            fclose($stream);
        }

        return isset($exif['Orientation']) ? (int) $exif['Orientation'] : null;
    }

    /**
     * A small preview copy for the Photoroom editor's review grid — a page of
     * 300 results cannot pull 300 full-size images.
     *
     * $preserveAlpha keeps a transparent cutout as PNG. Encoding one as JPEG
     * fills the transparency with black, which in a before/after comparison
     * reads as a botched edit rather than as a working cutout.
     */
    public function thumbnail(string $imageContent, int $maxDimension = 420, bool $preserveAlpha = false): string
    {
        $img = $this->decode($imageContent);
        $img->scaleDown($maxDimension, $maxDimension);

        return $preserveAlpha
            ? $img->encode(new PngEncoder())->toString()
            : $img->encode(new JpegEncoder(quality: 82))->toString();
    }

    /**
     * Turn any image into the best JPEG that fits inside $maxBytes.
     *
     * The difference from compressOnly() is that this always re-encodes.
     * compressOnly leaves a file alone when it is already small enough, which
     * is right for a JPEG that arrived and wrong for a lossless PNG that has
     * to become one — a 500 KB PNG is under any sensible ceiling and still not
     * the JPEG the catalogue wants.
     *
     * Quality starts at the top and only comes down as far as the ceiling
     * forces. On a product against a plain background that is usually not at
     * all: quality 100 of a 2000 square lands near 770 KB, where the ceiling is
     * a megabyte. Pixels are never traded for bytes — the canvas is what a
     * category preset promises, and a photo quietly returned narrower would
     * sit out of line with every other photo in its category. A file that
     * cannot fit even at the lowest quality comes back oversized instead,
     * which is a problem someone can see rather than one they cannot.
     */
    public function toJpegUnderLimit(string $imageContent, int $maxBytes = 1_000_000): string
    {
        $imageContent = $this->capPixelCount($imageContent);

        $encode = fn (int $quality) => $this->decode($imageContent)
            ->encode(new JpegEncoder(quality: $quality))
            ->toString();

        $best = $encode(self::START_QUALITY);

        if (strlen($best) <= $maxBytes) {
            return $best;
        }

        // Highest quality that fits. Binary search rather than stepping down,
        // because each attempt is a full re-encode of a 4-megapixel image.
        $lo = self::MIN_QUALITY;
        $hi = self::START_QUALITY - 1;

        while ($lo < $hi) {
            $mid = (int) ceil(($lo + $hi) / 2);

            if (strlen($encode($mid)) <= $maxBytes) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }

        $result = $encode($lo);

        if (strlen($result) > $maxBytes) {
            Log::warning(sprintf(
                'toJpegUnderLimit could not reach %d bytes; kept %d bytes at quality %d rather than lose pixels.',
                $maxBytes, strlen($result), $lo,
            ));
        }

        return $result;
    }

    /**
     * Squeeze a file under $maxBytes without changing what it shows.
     *
     * $allowShrink is the escape hatch for the one case quality cannot solve:
     * a detailed shot still over the limit at the lowest quality allowed. Pass
     * false where the pixel dimensions are part of the specification — the
     * photo editor asks Photoroom for an exact canvas, and silently returning
     * something smaller would break the alignment every category preset
     * exists to hold.
     */
    public function compressOnly(string $imageContent, int $maxBytes = 1_000_000, bool $allowShrink = true): string
    {
        // "Keep original size" still means keeping it inside Shopify's pixel
        // ceiling — compression alone never lowers the pixel count, so a huge
        // original would otherwise be refused on upload no matter how small
        // we squeeze the file.
        $imageContent = $this->capPixelCount($imageContent);

        // Already within the size limit — return original bytes untouched.
        // Re-encoding at quality 100 would only make the file larger.
        if (strlen($imageContent) <= $maxBytes) {
            return $imageContent;
        }

        $result = $this->decode($imageContent)
            ->encode(new JpegEncoder(quality: self::START_QUALITY))
            ->toString();

        if (strlen($result) <= $maxBytes) {
            return $result;
        }

        $lo = self::MIN_QUALITY;
        $hi = self::START_QUALITY - 1;

        while ($lo < $hi) {
            $mid  = (int) ceil(($lo + $hi) / 2);
            $size = strlen(
                $this->decode($imageContent)
                    ->encode(new JpegEncoder(quality: $mid))
                    ->toString()
            );
            if ($size <= $maxBytes) { $lo = $mid; } else { $hi = $mid - 1; }
        }

        $result = $this->decode($imageContent)
            ->encode(new JpegEncoder(quality: $lo))
            ->toString();

        // Detailed shots — fabric texture, patterned prints — can still sit
        // over the limit at the lowest quality we allow. Quality is spent by
        // this point, so start giving up pixels instead, the same way
        // process() does rather than shipping an oversized file.
        if (strlen($result) <= $maxBytes || !$allowShrink) {
            return $result;
        }

        return $this->shrinkUntilUnderLimit($imageContent, $result, $maxBytes);
    }

    /**
     * Last resort for a file that won't compress far enough: scale it down in
     * 10% steps at minimum quality. Returns the smallest result it reached,
     * even if that is still over $maxBytes — an oversized image beats no image.
     */
    private function shrinkUntilUnderLimit(string $imageContent, string $result, int $maxBytes): string
    {
        $scale = 0.9;

        while (strlen($result) > $maxBytes && $scale > 0.3) {
            $img    = $this->decode($imageContent);
            $result = $img
                ->scaleDown((int) ($img->width() * $scale), (int) ($img->height() * $scale))
                ->encode(new JpegEncoder(quality: self::MIN_QUALITY))
                ->toString();
            $scale -= 0.1;
        }

        return $result;
    }

    public function process(string $imageContent, int $width, int $height, int $maxBytes = 1_000_000): string
    {
        // Custom dimensions can be asked for well past Shopify's pixel ceiling
        // (5000 × 5000 is 25 MP) — shrink the target, keeping its aspect ratio.
        [$width, $height] = $this->clampToPixelLimit($width, $height);

        $img = $this->decode($imageContent);
        $img->cover($width, $height);
        $result = $img->encode(new JpegEncoder(quality: self::START_QUALITY))->toString();

        if (strlen($result) <= $maxBytes) {
            return $result;
        }

        $lo = self::MIN_QUALITY;
        $hi = self::START_QUALITY - 1;

        while ($lo < $hi) {
            $mid  = (int) ceil(($lo + $hi) / 2);
            $img  = $this->decode($imageContent);
            $img->cover($width, $height);
            $size = strlen($img->encode(new JpegEncoder(quality: $mid))->toString());

            if ($size <= $maxBytes) { $lo = $mid; } else { $hi = $mid - 1; }
        }

        $final = $this->decode($imageContent);
        $final->cover($width, $height);
        $result = $final->encode(new JpegEncoder(quality: $lo))->toString();

        $scale = 0.9;
        while (strlen($result) > $maxBytes && $scale > 0.3) {
            $img    = $this->decode($imageContent);
            $img->cover((int) ($width * $scale), (int) ($height * $scale));
            $result = $img->encode(new JpegEncoder(quality: self::MIN_QUALITY))->toString();
            $scale -= 0.1;
        }

        return $result;
    }

    /**
     * Scale an image down until it fits under Shopify's megapixel ceiling,
     * preserving its aspect ratio. Images already under it are returned
     * byte-for-byte, so nothing is re-encoded needlessly.
     */
    private function capPixelCount(string $imageContent): string
    {
        // Reads the header only — far cheaper than decoding the full image,
        // and the vast majority of files pass here and go no further.
        $info = @getimagesizefromstring($imageContent);
        if ($info && ($info[0] * $info[1]) <= self::MAX_PIXELS) {
            return $imageContent;
        }

        // Unreadable header (CMYK TIFF and friends) — fall back to decoding.
        $img    = $this->decode($imageContent);
        $pixels = $img->width() * $img->height();

        if ($pixels <= self::MAX_PIXELS) {
            return $imageContent;
        }

        [$width, $height] = $this->clampToPixelLimit($img->width(), $img->height());

        return $img->scaleDown($width, $height)
            ->encode(new JpegEncoder(quality: self::START_QUALITY))
            ->toString();
    }

    /**
     * Bring a transparent image under Shopify's megapixel ceiling without
     * flattening it.
     *
     * capPixelCount() re-encodes as JPEG, which is fine for the formats that
     * were never going to carry an alpha channel and ruinous for the ones
     * that were — a cutout comes back on a black rectangle. PNG and WebP
     * results go through here instead, and are returned byte-for-byte when
     * they already fit.
     */
    public function capPixelCountPreservingAlpha(string $imageContent, string $format = 'png'): string
    {
        $info = @getimagesizefromstring($imageContent);

        if ($info && ($info[0] * $info[1]) <= self::MAX_PIXELS) {
            return $imageContent;
        }

        try {
            $img = $this->decode($imageContent);
        } catch (\Throwable $e) {
            // Better an image Shopify may refuse than no image at all: the
            // caller still has bytes that a person can look at and re-run.
            Log::warning('capPixelCountPreservingAlpha could not decode: ' . $e->getMessage());

            return $imageContent;
        }

        if (($img->width() * $img->height()) <= self::MAX_PIXELS) {
            return $imageContent;
        }

        [$width, $height] = $this->clampToPixelLimit($img->width(), $img->height());

        $encoder = match ($format) {
            'webp'  => new WebpEncoder(quality: self::START_QUALITY),
            'avif'  => new AvifEncoder(quality: self::START_QUALITY),
            default => new PngEncoder(),
        };

        return $img->scaleDown($width, $height)->encode($encoder)->toString();
    }

    /**
     * @return array{0:int,1:int} the given dimensions, shrunk on the same
     *                            aspect ratio until they fit MAX_PIXELS
     */
    private function clampToPixelLimit(int $width, int $height): array
    {
        $pixels = $width * $height;

        if ($pixels <= self::MAX_PIXELS || $pixels <= 0) {
            return [$width, $height];
        }

        $ratio = sqrt(self::MAX_PIXELS / $pixels);

        return [
            max(1, (int) floor($width * $ratio)),
            max(1, (int) floor($height * $ratio)),
        ];
    }

    public function outputFilename(string $originalFilename): string
    {
        return pathinfo($originalFilename, PATHINFO_FILENAME) . '.jpg';
    }

    public function dimensionPresets(): array
    {
        return [
            ['width' => 2048, 'height' => 2048, 'label' => '2048 × 2048 (Shopify recommended)'],
            ['width' => 1200, 'height' => 1200, 'label' => '1200 × 1200'],
            ['width' => 1000, 'height' => 1000, 'label' => '1000 × 1000'],
            ['width' => 800,  'height' => 800,  'label' => '800 × 800'],
            ['width' => 600,  'height' => 600,  'label' => '600 × 600'],
        ];
    }

    public function sizeLimitOptions(): array
    {
        return [
            ['bytes' => 1_000_000,  'label' => '1 MB',  'note' => 'Fastest page load'],
            ['bytes' => 2_000_000,  'label' => '2 MB',  'note' => 'Good balance'],
            ['bytes' => 4_000_000,  'label' => '4 MB',  'note' => 'Higher quality'],
            ['bytes' => 10_000_000, 'label' => '10 MB', 'note' => 'Near-original quality'],
            ['bytes' => 20_000_000, 'label' => '20 MB', 'note' => 'Shopify max — original quality'],
        ];
    }
}
