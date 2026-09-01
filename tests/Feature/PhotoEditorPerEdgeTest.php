<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PhotoroomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-edge padding has two units, and the form only accepts one of them.
 *
 * A preset stores it as a fraction of the canvas, because a fraction survives
 * the canvas changing. The box on screen is in whole pixels. Put 0.1 into a
 * number input with a step of 1 and the browser refuses to submit the entire
 * form — silently, because the field lives inside a collapsed section and an
 * error cannot be shown on something nobody can see.
 *
 * That is what "the Fetch photos button does nothing" turned out to be, and
 * nothing in the app could have reported it. Hence a test.
 */
class PhotoEditorPerEdgeTest extends TestCase
{
    use RefreshDatabase;

    private function render(array $edits): string
    {
        return view('photo-editor.partials.group-settings', [
            'prefix'        => 'edits',
            'uid'           => 'run',
            'edits'         => $edits,
            'beautifyModes' => ['' => 'None'],
        ])->render();
    }

    /** A fraction on the way in becomes whole pixels in the box. */
    public function test_a_preset_fraction_reaches_the_form_as_pixels(): void
    {
        $html = $this->render(
            PhotoroomService::applyFramingPreset(PhotoroomService::defaultEdits(), 'perfume'),
        );

        preg_match('/padBottom:\s*([^,\s]+)/', $html, $m);

        $this->assertSame('200', $m[1] ?? null,
            '10% of a 2000 canvas should reach the box as 200 pixels, not as 0.1');
    }

    /** Bags carry one too, and 20% of 2000 is 400. */
    public function test_the_bags_baseline_reaches_the_form_as_pixels(): void
    {
        $html = $this->render(
            PhotoroomService::applyFramingPreset(PhotoroomService::defaultEdits(), 'women/bags'),
        );

        preg_match('/padBottom:\s*([^,\s]+)/', $html, $m);

        $this->assertSame('400', $m[1] ?? null);
    }

    /** A value already in pixels is left as it is. */
    public function test_a_stored_pixel_value_is_not_converted_again(): void
    {
        $html = $this->render(['padding_bottom' => '250px', 'height' => 2000]);

        preg_match('/padBottom:\s*([^,\s]+)/', $html, $m);

        $this->assertSame('250', $m[1] ?? null);
    }

    /**
     * The screen a person actually loads must contain no unsubmittable field.
     *
     * A number input whose value is not a whole multiple of its step is invalid,
     * and one invalid field stops the form — so this walks every per-edge box on
     * the real page and checks the value against the step.
     */
    public function test_the_run_form_has_no_field_that_would_block_submission(): void
    {
        config(['services.photoroom.api_key' => 'sandbox_test_key']);

        $html = $this->actingAs(User::factory()->create([
            'is_active' => true, 'perm_photo_editor' => true,
        ]))->get(route('photo-editor.index'))->assertOk()->getContent();

        preg_match_all('/<input[^>]*type="number"[^>]*>/', $html, $inputs);

        foreach ($inputs[0] as $input) {
            preg_match('/name="([^"]*)"/', $input, $name);
            preg_match('/step="([^"]*)"/', $input, $step);
            preg_match('/value="([^"]*)"/', $input, $value);

            if (($step[1] ?? 'any') === 'any' || !isset($value[1]) || $value[1] === '') {
                continue;
            }

            $this->assertSame(
                0.0,
                fmod((float) $value[1], (float) $step[1]),
                "{$name[1]} holds {$value[1]}, which its step of {$step[1]} rejects — the form will not submit",
            );
        }
    }
}
