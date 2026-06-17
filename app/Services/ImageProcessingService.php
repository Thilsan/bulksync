<?php

namespace App\Services;

use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class ImageProcessingService
{
    private const START_QUALITY = 100;
    private const MIN_QUALITY   = 30;

    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver());
    }

    public function compressOnly(string $imageContent, int $maxBytes = 1_000_000): string
    {
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

        return $this->manager->decode($imageContent)
            ->encode(new JpegEncoder(quality: $lo))
            ->toString();
    }

    public function process(string $imageContent, int $width, int $height, int $maxBytes = 1_000_000): string
    {
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
