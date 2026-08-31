<?php

namespace Tests\Feature;

use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Asking Gemini where the stand is.
 *
 * This replaced guessing by colour, which worked on a dark hanger against a
 * pale wall and failed on a white plastic one. What has to hold is that a bad
 * or missing answer is read as "change nothing" rather than acted on — the
 * boxes decide which pixels may be overwritten, so a wrong one is the only way
 * this process can damage a photograph.
 */
class GeminiLocateStandTest extends TestCase
{
    private function reply(array $payload): void
    {
        Http::fake([
            '*generativelanguage*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode($payload)]]],
                ]],
            ], 200),
        ]);
    }

    private function jpeg(): string
    {
        $img = imagecreatetruecolor(400, 600);
        imagefill($img, 0, 0, imagecolorallocate($img, 240, 240, 244));

        ob_start();
        imagejpeg($img, null, 90);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    private function service(): GeminiService
    {
        config(['services.gemini.api_key' => 'test-key']);

        return app(GeminiService::class);
    }

    /** Gemini reports [ymin, xmin, ymax, xmax] on a 0–1000 scale. */
    public function test_a_box_is_converted_from_geminis_scale_to_fractions(): void
    {
        $this->reply(['found' => true, 'boxes' => [[100, 300, 260, 700]]]);

        $boxes = $this->service()->locateStand($this->jpeg());

        $this->assertCount(1, $boxes);
        $this->assertEqualsWithDelta(0.30, $boxes[0]['x0'], 0.001);
        $this->assertEqualsWithDelta(0.10, $boxes[0]['y0'], 0.001);
        $this->assertEqualsWithDelta(0.70, $boxes[0]['x1'], 0.001);
        $this->assertEqualsWithDelta(0.26, $boxes[0]['y1'], 0.001);
    }

    /** A garment alone in frame has nothing to erase. */
    public function test_nothing_found_means_no_boxes(): void
    {
        $this->reply(['found' => false, 'boxes' => []]);

        $this->assertSame([], $this->service()->locateStand($this->jpeg()));
    }

    /**
     * A box covering most of the photo is the model giving up, not a hanger.
     *
     * Acting on one would put the whole garment up for erasure, which is the
     * worst thing this feature could do — so it is discarded rather than
     * trusted, and discarding every box means nothing is touched.
     */
    public function test_a_box_covering_most_of_the_photo_is_discarded(): void
    {
        $this->reply(['found' => true, 'boxes' => [[0, 0, 950, 950]]]);

        $this->assertSame([], $this->service()->locateStand($this->jpeg()));
    }

    /** Nonsense in the reply must not become a box. */
    public function test_malformed_boxes_are_dropped(): void
    {
        $this->reply(['found' => true, 'boxes' => [
            [100, 300, 260, 700],   // good
            [500, 500, 400, 400],   // inverted, impossible
            [1, 2],                 // too few numbers
            'not a box',
        ]]);

        $boxes = $this->service()->locateStand($this->jpeg());

        $this->assertCount(1, $boxes, 'a malformed box survived into the mask');
    }

    /** An API failure is read as "change nothing". */
    public function test_an_api_failure_returns_no_boxes(): void
    {
        Http::fake(['*generativelanguage*' => Http::response('nope', 500)]);

        $this->assertSame([], $this->service()->locateStand($this->jpeg()));
    }
}
