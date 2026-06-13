<?php

namespace App\Services;

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class ImageProcessingService
{
    private const START_QUALITY = 100; // always attempt maximum quality first
    private const MIN_QUALITY   = 30;  // never go below this

    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver());
    }

    /**
     * Resize image to exact $width × $height, then find the highest JPEG quality
     * that keeps the file under $maxBytes.
     *
     * Key point: quality is ONLY reduced when the resized image is still larger
     * than $maxBytes at 100%. If resizing alone brings the file under the limit,
     * the image is returned at 100% quality — no degradation at all.
     *
     * Strategy:
     *   1. Resize to exact dimensions (cover + centre-crop).
     *   2. Try 100% quality — if ≤ $maxBytes, return immediately (no degradation).
     *   3. Binary-search for the highest quality that fits (maximises quality).
     *   4. Last resort: scale dimensions further until it fits at MIN_QUALITY.
     *
     * @param  int  $maxBytes  e.g. 1_000_000 (1MB), 2_000_000 (2MB), 4_000_000 (4MB)
     */
    /**
     * Compress image to fit under $maxBytes without resizing (original dimensions kept).
     */
    public function compressOnly(string $imageContent, int $maxBytes = 1_000_000): string
    {
        $img    = $this->manager->read($imageContent);
        $result = $img->toJpeg(self::START_QUALITY)->toString();

        if (strlen($result) <= $maxBytes) {
            return $result;
        }

        $lo = self::MIN_QUALITY;
        $hi = self::START_QUALITY - 1;

        while ($lo < $hi) {
            $mid  = (int) ceil(($lo + $hi) / 2);
            $size = strlen($this->manager->read($imageContent)->toJpeg($mid)->toString());
            if ($size <= $maxBytes) { $lo = $mid; } else { $hi = $mid - 1; }
        }

        return $this->manager->read($imageContent)->toJpeg($lo)->toString();
    }

    public function process(string $imageContent, int $width, int $height, int $maxBytes = 1_000_000): string
    {
        // Step 1 — resize to target dimensions only (never upscale)
        $resized = $this->manager->read($imageContent);
        $resized->cover($width, $height);

        // Step 2 — try 100% quality
        $result = $resized->toJpeg(self::START_QUALITY)->toString();

        if (strlen($result) <= $maxBytes) {
            return $result; // full quality — no degradation
        }

        // Step 3 — binary-search for the highest quality that fits
        $lo = self::MIN_QUALITY;
        $hi = self::START_QUALITY - 1;

        while ($lo < $hi) {
            $mid  = (int) ceil(($lo + $hi) / 2);
            $img  = $this->manager->read($imageContent);
            $img->cover($width, $height);
            $size = strlen($img->toJpeg($mid)->toString());

            if ($size <= $maxBytes) {
                $lo = $mid;      // fits — try higher
            } else {
                $hi = $mid - 1;  // too big — try lower
            }
        }

        // Encode at the best quality found
        $final = $this->manager->read($imageContent);
        $final->cover($width, $height);
        $result = $final->toJpeg($lo)->toString();

        // Step 4 — last resort: shrink dimensions further (extremely rare)
        $scale = 0.9;
        while (strlen($result) > $maxBytes && $scale > 0.3) {
            $img    = $this->manager->read($imageContent);
            $img->cover((int) ($width * $scale), (int) ($height * $scale));
            $result = $img->toJpeg(self::MIN_QUALITY)->toString();
            $scale -= 0.1;
        }

        return $result;
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

    /** Size limit presets shown in the upload form */
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
