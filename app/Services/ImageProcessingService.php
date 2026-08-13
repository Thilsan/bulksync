<?php

namespace App\Services;

use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;

class ImageProcessingService
{
    private const START_QUALITY = 100;
    private const MIN_QUALITY   = 30;

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
     * Shrink an image for sending to a vision AI API — vision models don't need
     * full resolution to identify colors/textures/details, and smaller images
     * mean fewer tokens and faster uploads. Never upscales. Does not touch the
     * original file — caller must have their own copy of $imageContent.
     */
    public function scaleDownForAnalysis(string $imageContent, int $maxDimension = 1024, int $maxBytes = 1_500_000): string
    {
        $img = $this->manager->decode($imageContent);
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
        return $this->manager->decode($imageContent)
            ->orient()
            ->encode(new JpegEncoder(quality: 95))
            ->toString();
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
        $img = $this->manager->decode($imageContent);
        $img->scaleDown($maxDimension, $maxDimension);

        return $preserveAlpha
            ? $img->encode(new PngEncoder())->toString()
            : $img->encode(new JpegEncoder(quality: 82))->toString();
    }

    public function compressOnly(string $imageContent, int $maxBytes = 1_000_000): string
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

        $result = $this->manager->decode($imageContent)
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
                $this->manager->decode($imageContent)
                    ->encode(new JpegEncoder(quality: $mid))
                    ->toString()
            );
            if ($size <= $maxBytes) { $lo = $mid; } else { $hi = $mid - 1; }
        }

        $result = $this->manager->decode($imageContent)
            ->encode(new JpegEncoder(quality: $lo))
            ->toString();

        // Detailed shots — fabric texture, patterned prints — can still sit
        // over the limit at the lowest quality we allow. Quality is spent by
        // this point, so start giving up pixels instead, the same way
        // process() does rather than shipping an oversized file.
        return strlen($result) <= $maxBytes
            ? $result
            : $this->shrinkUntilUnderLimit($imageContent, $result, $maxBytes);
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
            $img    = $this->manager->decode($imageContent);
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

        $img = $this->manager->decode($imageContent);
        $img->cover($width, $height);
        $result = $img->encode(new JpegEncoder(quality: self::START_QUALITY))->toString();

        if (strlen($result) <= $maxBytes) {
            return $result;
        }

        $lo = self::MIN_QUALITY;
        $hi = self::START_QUALITY - 1;

        while ($lo < $hi) {
            $mid  = (int) ceil(($lo + $hi) / 2);
            $img  = $this->manager->decode($imageContent);
            $img->cover($width, $height);
            $size = strlen($img->encode(new JpegEncoder(quality: $mid))->toString());

            if ($size <= $maxBytes) { $lo = $mid; } else { $hi = $mid - 1; }
        }

        $final = $this->manager->decode($imageContent);
        $final->cover($width, $height);
        $result = $final->encode(new JpegEncoder(quality: $lo))->toString();

        $scale = 0.9;
        while (strlen($result) > $maxBytes && $scale > 0.3) {
            $img    = $this->manager->decode($imageContent);
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
        $img    = $this->manager->decode($imageContent);
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
