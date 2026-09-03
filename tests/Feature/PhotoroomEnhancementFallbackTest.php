<?php

namespace Tests\Feature;

use App\Services\PhotoroomService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * An enhancement the API will not take should cost the enhancement, not the edit.
 *
 * Upscale proved the point. Its mode value had sat unexercised behind a
 * checkbox nobody ticked, so the first run that switched it on failed images
 * that had been editing fine for months — a cutout lost to a refinement that
 * was only ever a nice-to-have.
 */
class PhotoroomEnhancementFallbackTest extends TestCase
{
    private const REFUSAL = '{"error":{"message":"upscale/mode must be equal to one of the allowed values"}}';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.photoroom.api_key' => 'live_test_key']);
    }

    /** @return list<array<string,string>> the fields of each request, in order */
    private function sentFields(): array
    {
        return array_map(
            fn ($r) => collect($r->data())->pluck('contents', 'name')->all(),
            Http::recorded()->pluck(0)->all(),
        );
    }

    public function test_a_refused_upscale_is_dropped_and_the_edit_still_happens(): void
    {
        $calls = 0;

        Http::fake([
            'image-api.photoroom.com/*' => function () use (&$calls) {
                return ++$calls === 1
                    ? Http::response(self::REFUSAL, 400)
                    : Http::response('IMAGEBYTES', 200, ['Content-Type' => 'image/png']);
            },
        ]);

        $result = app(PhotoroomService::class)->edit('rawbytes', [
            'remove_background'  => true,
            'width'              => 2000,
            'height'             => 2000,
            'upscale'            => true,
            'upscale_resolution' => 2000,
        ]);

        $this->assertSame('IMAGEBYTES', $result, 'the edit should have survived losing the upscale');

        [$first, $second] = $this->sentFields();

        $this->assertArrayHasKey('upscale.mode', $first);
        $this->assertArrayNotHasKey('upscale.mode', $second, 'the refused field went back out');
        $this->assertArrayNotHasKey('upscale.targetResolution', $second);

        // The edit itself must survive intact — dropping the cutout or the
        // canvas to force a request through would return an image nobody asked for.
        $this->assertSame('true', $second['removeBackground'] ?? null);
        $this->assertSame('2000x2000', $second['outputSize'] ?? null);
    }

    /** A refusal about the picture itself is the caller's to hear. */
    public function test_a_refusal_that_names_nothing_optional_is_not_swallowed(): void
    {
        Http::fake([
            'image-api.photoroom.com/*' => Http::response(
                '{"error":{"message":"Images deeper than 8-bit are not supported"}}',
                400,
            ),
        ]);

        $this->expectException(\RuntimeException::class);

        app(PhotoroomService::class)->edit('rawbytes', [
            'remove_background' => true,
            'upscale'           => true,
        ]);
    }

    /** Dropping an enhancement is not a licence to retry forever. */
    public function test_the_retry_happens_once(): void
    {
        Http::fake([
            'image-api.photoroom.com/*' => Http::response(self::REFUSAL, 400),
        ]);

        try {
            app(PhotoroomService::class)->edit('rawbytes', ['upscale' => true, 'width' => 2000, 'height' => 2000]);
        } catch (\Throwable) {
            // expected — the second attempt has nothing left to drop
        }

        $this->assertLessThanOrEqual(2, count($this->sentFields()));
    }
}
