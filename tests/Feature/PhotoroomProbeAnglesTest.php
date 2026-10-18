<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Asking Photoroom for views of a product it has only seen from one side.
 *
 * Their documentation offers it — a prompt like "show the product from the
 * side", one request per angle — and warns in the same breath that it
 * "generates new images rather than rotating existing ones". A generated back
 * view is a photograph of a product that does not exist, and whether that
 * matters depends on how much of the product is on the side nobody shot.
 *
 * So this is a probe and not a feature: it writes files to be looked at next
 * to the real thing, and says what it spent. The same generative pass has
 * already reinvented a printed monogram on this account, on a job that only
 * had to remove a stand from a photo it could see.
 */
class PhotoroomProbeAnglesTest extends TestCase
{
    private function photo(): string
    {
        $path = sys_get_temp_dir() . '/probe-angles-' . uniqid() . '.jpg';
        $im   = imagecreatetruecolor(400, 300);

        imagefilledrectangle($im, 0, 0, 400, 300, imagecolorallocate($im, 255, 255, 255));
        imagefilledrectangle($im, 80, 80, 320, 220, imagecolorallocate($im, 30, 60, 120));
        imagejpeg($im, $path);
        imagedestroy($im);

        return $path;
    }

    /**
     * A live key is not spent on a speculative command by accident.
     *
     * This exists to be run on a hunch, which is exactly the combination that
     * bills somebody for an experiment they thought was free.
     */
    public function test_a_live_key_needs_saying_so_out_loud(): void
    {
        config(['services.photoroom.api_key' => 'live_sk_test']);

        Http::fake(['image-api.photoroom.com/*' => Http::response('nope', 500)]);

        $this->artisan('photoroom:probe-angles', ['image' => $this->photo()])
            ->expectsOutputToContain('4 angles will cost 4 credits')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_it_asks_for_one_angle_per_request_and_says_what_it_spent(): void
    {
        config(['services.photoroom.api_key' => 'sandbox_test']);

        $prompts = [];

        Http::fake(function ($request) use (&$prompts) {
            foreach ($request->data() as $part) {
                if (($part['name'] ?? '') === 'editWithAI.prompt') {
                    $prompts[] = (string) $part['contents'];
                }
            }

            return Http::response(file_get_contents(__DIR__ . '/../../public/favicon-32x32.png'), 200);
        });

        $out = sys_get_temp_dir() . '/angles-' . uniqid();

        $this->artisan('photoroom:probe-angles', ['image' => $this->photo(), '--out' => $out])
            ->expectsOutputToContain('Spent: 4 credits')
            ->assertExitCode(0);

        $this->assertCount(4, $prompts, 'one request per angle');

        foreach (['side', 'behind', 'front', 'three-quarter'] as $view) {
            $this->assertTrue(
                collect($prompts)->contains(fn ($p) => str_contains($p, $view)),
                "no request asked for the {$view} view",
            );
        }

        // Every prompt insists it is the same product, which is the only thing
        // standing between a new angle and a new product.
        foreach ($prompts as $prompt) {
            $this->assertStringContainsString('same product', $prompt);
            $this->assertStringContainsString('same markings', $prompt);
        }

        $this->assertCount(4, glob($out . '/*.jpg'));
    }
}
