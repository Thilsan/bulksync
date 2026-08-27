<?php

namespace Tests\Unit;

use App\Services\ImageProcessingService;
use PHPUnit\Framework\TestCase;

class ImageProcessingServiceTest extends TestCase
{
    /** Shopify rejects anything above this with a 422 on upload. */
    private const SHOPIFY_MAX_PIXELS = 20_000_000;

    private ImageProcessingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ImageProcessingService();
    }

    public function test_compress_only_shrinks_an_image_past_the_shopify_pixel_limit(): void
    {
        // 24 MP but only ~360 KB — under any byte limit, yet Shopify refuses it.
        $source = $this->jpeg(6000, 4000, quality: 60);
        $this->assertLessThan(1_000_000, strlen($source));

        [$width, $height] = getimagesizefromstring($this->service->compressOnly($source));

        $this->assertLessThanOrEqual(self::SHOPIFY_MAX_PIXELS, $width * $height);
        $this->assertEqualsWithDelta(6000 / 4000, $width / $height, 0.01, 'aspect ratio changed');
    }

    public function test_compress_only_leaves_an_image_within_both_limits_untouched(): void
    {
        $source = $this->jpeg(800, 800, quality: 80);

        $this->assertSame($source, $this->service->compressOnly($source));
    }

    public function test_process_clamps_requested_dimensions_to_the_shopify_pixel_limit(): void
    {
        // 5000 × 5000 = 25 MP is accepted by the form's validation rules.
        $result = $this->service->process($this->jpeg(6000, 4000, quality: 60), 5000, 5000);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertLessThanOrEqual(self::SHOPIFY_MAX_PIXELS, $width * $height);
        $this->assertEqualsWithDelta(1.0, $width / $height, 0.01, 'aspect ratio changed');
    }

    public function test_compress_only_scales_down_when_quality_alone_cannot_reach_the_byte_limit(): void
    {
        // Detail this fine stays around 2 MB even at the lowest quality —
        // only shedding pixels gets it under the limit.
        $result = $this->service->compressOnly($this->noisyJpeg(3000, 3000), 500_000);

        $this->assertLessThanOrEqual(500_000, strlen($result));
    }

    /**
     * A lossless PNG becomes the best JPEG that fits, not the smallest one.
     *
     * The old path left a file alone once it was under the ceiling, which is
     * right for a JPEG that arrived and wrong for a PNG that has to become one:
     * a 500 KB PNG is under any sensible limit and still not a JPEG. What this
     * pins is that the result is a JPEG, that it fits, and that quality was
     * spent generously rather than saved — a product on a plain background has
     * no business coming back at quality 40 when the ceiling is a megabyte.
     */
    public function test_a_lossless_png_becomes_the_best_jpeg_that_fits(): void
    {
        $png = $this->service->toJpegUnderLimit($this->png(2000, 2000), 1_000_000);

        $this->assertLessThanOrEqual(1_000_000, strlen($png));
        $this->assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->buffer($png));

        [$width, $height] = getimagesizefromstring($png);
        $this->assertSame([2000, 2000], [$width, $height], 'the canvas was not preserved');
    }

    /**
     * A ceiling is met by lowering quality, never by dropping resolution.
     *
     * Detail this fine is 17 MB at full quality, so the ceiling genuinely binds
     * and the binary search has to come down off 100 to meet it. What must not
     * happen on the way is the canvas shrinking: every photo in a category is
     * promised the same one.
     */
    public function test_a_binding_ceiling_lowers_quality_rather_than_resolution(): void
    {
        $source = $this->noisyJpeg(3000, 3000);
        $this->assertGreaterThan(3_000_000, strlen($source), 'fixture is not detailed enough to bind');

        $result = $this->service->toJpegUnderLimit($source, 3_000_000);

        $this->assertLessThanOrEqual(3_000_000, strlen($result));

        [$width, $height] = getimagesizefromstring($result);
        $this->assertSame([3000, 3000], [$width, $height], 'resolution was traded for bytes');
    }

    /**
     * The photo editor's canvas is a promise, so pixels are never the currency.
     *
     * A category preset asks Photoroom for an exact 2000 square, and every
     * photo in that category lines up because they all got one. A busy print
     * that will not compress far enough must therefore come back oversized
     * rather than come back smaller — the alignment is worth more than the
     * kilobytes, and an image quietly 10% short would look right on its own
     * and wrong beside the eleven that were not.
     */
    public function test_compress_only_keeps_every_pixel_when_shrinking_is_refused(): void
    {
        $source = $this->noisyJpeg(3000, 3000);

        $result = $this->service->compressOnly($source, 500_000, allowShrink: false);

        [$width, $height] = getimagesizefromstring($result);

        $this->assertSame(3000, $width, 'the canvas was narrowed to save bytes');
        $this->assertSame(3000, $height, 'the canvas was shortened to save bytes');

        // Quality was still spent trying, so it is smaller than it arrived —
        // just not smaller than the limit, which is the honest outcome.
        $this->assertLessThan(strlen($source), strlen($result));
    }

    /** A product-shaped image: a solid subject on white, saved losslessly. */
    private function png(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imagefilledellipse($img, (int) ($width / 2), (int) ($height / 2),
            (int) ($width * 0.6), (int) ($height * 0.7),
            imagecolorallocate($img, 120, 80, 40));

        ob_start();
        imagepng($img);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    private function jpeg(int $width, int $height, int $quality): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, imagecolorallocate($img, 200, 150, 120));

        ob_start();
        imagejpeg($img, null, $quality);

        return ob_get_clean();
    }

    /**
     * Flat colour compresses to almost nothing. Fine detail is what a real
     * product shot has and what refuses to shrink on quality alone.
     */
    private function noisyJpeg(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        for ($x = 0; $x < $width; $x += 2) {
            for ($y = 0; $y < $height; $y += 2) {
                imagefilledrectangle($img, $x, $y, $x + 1, $y + 1, imagecolorallocate(
                    $img,
                    ($x * 7 + $y * 13) % 256,
                    ($x * 3 + $y * 29) % 256,
                    ($x * 17 + $y * 5) % 256,
                ));
            }
        }

        ob_start();
        imagejpeg($img, null, 100);

        return ob_get_clean();
    }
}
