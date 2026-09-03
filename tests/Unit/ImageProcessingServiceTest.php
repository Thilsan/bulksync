<?php

namespace Tests\Unit;

use App\Services\ImageProcessingService;
use Tests\TestCase;

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

    /**
     * Photoroom refuses more than 8 bits per channel, and supplier AVIFs
     * sometimes arrive at 10. Nothing else we check can see it: the file is
     * small, the dimensions are fine, it decodes cleanly, and the API rejects
     * it anyway with "Images deeper than 8-bit are not supported".
     */
    public function test_a_deeper_than_8_bit_image_is_brought_down_to_8(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('bit depth is an Imagick concern; GD cannot make a file deeper than 8 bits');
        }

        $deep = new \Imagick();
        $deep->newImage(400, 400, new \ImagickPixel('rgb(180,120,90)'));
        $deep->setImageDepth(16);
        $deep->setImageFormat('png');
        // Without this the encoder quietly writes 8-bit and the fixture is not
        // a fixture at all — which is exactly how this test first failed.
        $deep->setOption('png:bit-depth', '16');
        $source = $deep->getImageBlob();
        $deep->clear();

        $probe = new \Imagick();
        $probe->readImageBlob($source);
        $depth = $probe->getImageDepth();
        $probe->clear();

        if ($depth <= 8) {
            $this->markTestSkipped('this ImageMagick build will not write a deeper-than-8-bit PNG to test against');
        }

        $capped = new \Imagick();
        $capped->readImageBlob($this->service->capBitDepth($source));

        $this->assertLessThanOrEqual(8, $capped->getImageDepth());
        $this->assertSame([400, 400], array_slice(getimagesizefromstring($this->service->capBitDepth($source)), 0, 2),
            'the picture itself should be unchanged');
        $capped->clear();
    }

    /**
     * Re-encoding costs quality and time, so an image that is already within
     * the limit must come back as the very bytes that went in.
     */
    public function test_an_image_already_within_the_limit_is_returned_untouched(): void
    {
        $source = $this->jpeg(300, 300, quality: 85);

        $this->assertSame($source, $this->service->capBitDepth($source));
    }

    /** Nothing to do, and nothing thrown, when the bytes cannot be read. */
    public function test_unreadable_bytes_are_handed_back_rather_than_throwing(): void
    {
        $this->assertSame('not an image at all', $this->service->capBitDepth('not an image at all'));
    }

    /**
     * Sharpening is judged against what was actually sent, not against the
     * preset — a photograph that already had the pixels gains nothing from it
     * and would only have its grain and JPEG artefacts amplified.
     */
    public function test_only_an_enlarged_image_counts_as_enlarged(): void
    {
        $small = $this->jpeg(500, 500, quality: 90);
        $large = $this->jpeg(2000, 2000, quality: 90);

        $this->assertTrue($this->service->wasEnlarged($small, $large));
        $this->assertFalse($this->service->wasEnlarged($large, $small), 'a shrink is not an enlargement');
        $this->assertFalse($this->service->wasEnlarged($large, $large), 'same size is not an enlargement');
        $this->assertFalse($this->service->wasEnlarged('not an image', $large));
    }

    /** It changes the picture without changing its shape. */
    public function test_sharpening_keeps_the_dimensions_and_alters_the_pixels(): void
    {
        $source    = $this->jpeg(600, 400, quality: 95);
        $sharpened = $this->service->sharpenAfterEnlargement($source);

        $this->assertSame([600, 400], array_slice(getimagesizefromstring($sharpened), 0, 2));
        $this->assertNotSame($source, $sharpened);
    }

    /**
     * Kept mild on purpose. A ring is bright metal and stones on white, where a
     * halo shows long before it would on a garment — so the level is clamped
     * however it is called.
     */
    public function test_the_sharpening_level_is_clamped(): void
    {
        $source = $this->jpeg(400, 400, quality: 95);

        foreach ([-50, 0, 500] as $absurd) {
            $out = $this->service->sharpenAfterEnlargement($source, $absurd);

            $this->assertSame([400, 400], array_slice(getimagesizefromstring($out), 0, 2));
        }
    }

    /** A picture it cannot read comes back untouched rather than throwing. */
    public function test_unsharpenable_bytes_are_returned_as_they_came(): void
    {
        $this->assertSame('not an image', $this->service->sharpenAfterEnlargement('not an image'));
    }

    /**
     * The case this was written for: a small product on a large white sheet.
     *
     * Four fifths of a supplier ring photograph is background, and it is paid
     * for twice — once by putting the file over the upscaler's ceiling, and
     * again by leaving the product too small in frame for Photoroom to enlarge.
     */
    public function test_a_small_product_on_a_large_white_sheet_is_cropped_in(): void
    {
        $source = $this->productOnWhite(1200, 1200, 200, 200);

        $cropped = $this->service->cropToSubject($source);

        [$w, $h] = array_slice(getimagesizefromstring($cropped), 0, 2);

        $this->assertLessThan(1200, $w, 'the background was not trimmed');
        $this->assertGreaterThan(200, $w, 'the product itself was clipped');
        $this->assertLessThan(1_000_000, $w * $h, 'still too big for the upscaler');
    }

    /** Nothing to trim, nothing trimmed. */
    public function test_a_product_that_already_fills_the_frame_is_left_alone(): void
    {
        $source = $this->productOnWhite(400, 400, 380, 380);

        $this->assertSame($source, $this->service->cropToSubject($source));
    }

    /**
     * A blank frame means the detection found nothing, not that the product is
     * infinitely small — so the picture is handed back rather than cropped to
     * a speck.
     */
    public function test_an_empty_frame_is_returned_untouched(): void
    {
        $blank = $this->productOnWhite(400, 400, 0, 0);

        $this->assertSame($blank, $this->service->cropToSubject($blank));
    }

    /** Unreadable bytes come back as they came. */
    public function test_uncroppable_bytes_are_returned_as_they_came(): void
    {
        $this->assertSame('not an image', $this->service->cropToSubject('not an image'));
    }

    /**
     * The margin is what protects a pale edge from a threshold that cannot see
     * it, so the crop must sit clear of the product on every side.
     */
    public function test_the_crop_keeps_a_margin_around_the_product(): void
    {
        $cropped = $this->service->cropToSubject($this->productOnWhite(1000, 1000, 200, 300));

        [$w, $h] = array_slice(getimagesizefromstring($cropped), 0, 2);

        $this->assertGreaterThanOrEqual(280, $w, 'no margin left or right');
        $this->assertGreaterThanOrEqual(400, $h, 'no margin top or bottom');
    }

    /** A dark shape centred on a white canvas. */
    private function productOnWhite(int $w, int $h, int $productW, int $productH): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 255, 255, 255));

        if ($productW > 0 && $productH > 0) {
            imagefilledellipse(
                $im,
                intdiv($w, 2),
                intdiv($h, 2),
                $productW,
                $productH,
                imagecolorallocate($im, 60, 60, 70),
            );
        }

        ob_start();
        imagepng($im);

        return ob_get_clean();
    }

    /**
     * A measured standard is only a standard if it is actually hit. Photoroom's
     * own framing put one ring at 59.7% of its canvas and another at 65.5% from
     * identical settings, so the size is settled here instead.
     */
    public function test_the_product_is_framed_to_the_padding_it_was_given(): void
    {
        $source = $this->productOnWhite(1000, 1000, 700, 500);

        // A list of pairs, not a keyed array: PHP casts a float array key to
        // an int, so 0.22 and 0.10 would both become 0.
        foreach ([[0.22, 0.56], [0.10, 0.80]] as [$padding, $expectedFill]) {
            $framed = $this->service->frameToStandard($source, 2000, $padding);

            [$w, $h] = array_slice(getimagesizefromstring($framed), 0, 2);
            $this->assertSame([2000, 2000], [$w, $h], 'the canvas is not the size asked for');

            $box = $this->subjectFillOf($framed);

            $this->assertEqualsWithDelta($expectedFill, $box, 0.02,
                "padding {$padding} should leave the product filling {$expectedFill} of the canvas");
        }
    }

    /** Bottom-aligned categories keep standing on their line. */
    public function test_a_bottom_aligned_product_sits_on_its_base_line(): void
    {
        $framed = $this->service->frameToStandard(
            $this->productOnWhite(1000, 1000, 400, 600),
            2000,
            0.103,
            0.106,
            'bottom',
        );

        $im = imagecreatefromstring($framed);
        $h  = imagesy($im);

        // Find the lowest non-transparent row.
        $lowest = 0;
        for ($y = $h - 1; $y >= 0; $y--) {
            for ($x = 0; $x < imagesx($im); $x++) {
                if (((imagecolorat($im, $x, $y) >> 24) & 0x7F) < 100) { $lowest = $y; break 2; }
            }
        }

        $gap = ($h - 1 - $lowest) / $h;

        $this->assertEqualsWithDelta(0.106, $gap, 0.02, 'the product is not standing on its base line');
    }

    /** Already right, so left alone rather than resampled for nothing. */
    public function test_an_image_already_on_standard_is_not_reframed(): void
    {
        $onStandard = $this->service->frameToStandard($this->productOnWhite(1000, 1000, 600, 400), 2000, 0.22);

        $this->assertSame($onStandard, $this->service->frameToStandard($onStandard, 2000, 0.22));
    }

    /** Nonsense in, the picture back out. */
    public function test_framing_refuses_impossible_padding(): void
    {
        $source = $this->productOnWhite(400, 400, 200, 200);

        $this->assertSame($source, $this->service->frameToStandard($source, 2000, 0.7));
        $this->assertSame($source, $this->service->frameToStandard($source, 10, 0.2));
        $this->assertSame('not an image', $this->service->frameToStandard('not an image', 2000, 0.2));
    }

    /** What fraction of the canvas the product's longest edge covers. */
    private function subjectFillOf(string $bytes): float
    {
        $im = imagecreatefromstring($bytes);
        $w = imagesx($im); $h = imagesy($im);
        $minX = $w; $minY = $h; $maxX = -1; $maxY = -1;

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($im, $x, $y);
                if ((($c >> 24) & 0x7F) > 100) continue;
                if ((($c >> 16) & 255) >= 252 && (($c >> 8) & 255) >= 252 && ($c & 255) >= 252) continue;
                if ($x < $minX) $minX = $x;
                if ($x > $maxX) $maxX = $x;
                if ($y < $minY) $minY = $y;
                if ($y > $maxY) $maxY = $y;
            }
        }

        return $maxX < 0 ? 0.0 : max($maxX - $minX + 1, $maxY - $minY + 1) / max($w, $h);
    }
}
