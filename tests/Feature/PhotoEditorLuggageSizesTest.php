<?php

namespace Tests\Feature;

use App\Services\PhotoroomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A large case has to look large.
 *
 * The Samsonite canvas is 1600 by 2400 with the case standing on a line 4.6%
 * up from the bottom, and the preset carries one size with it: 55.25% of the
 * height, measured off 156523_1830_hi-res_FRONT34_1. That reference turns out
 * to be a 55cm cabin case, which is the cm rule used everywhere else arrived
 * at from the other direction — so it is the cabin's number, not every case's.
 *
 * Until the typed size reached this canvas, a cabin, a medium and a large all
 * came back the same height and the size picked on the review screen changed
 * nothing. Side by side in a gallery, that is the one thing the brand notices.
 */
class PhotoEditorLuggageSizesTest extends TestCase
{
    use RefreshDatabase;

    private function fit(array $edits): array
    {
        $job = new \App\Jobs\EditPhotoItemJob(1);

        $height = (new \ReflectionMethod($job, 'baselineFitHeight'));
        $width  = (new \ReflectionMethod($job, 'baselineFitWidth'));
        $height->setAccessible(true);
        $width->setAccessible(true);

        return [$width->invoke($job, $edits), $height->invoke($job, $edits)];
    }

    private function preset(): array
    {
        return PhotoroomService::FRAMING_PRESETS['samsonite']['edits'];
    }

    public function test_each_size_stands_at_its_own_height(): void
    {
        foreach ([55 => 0.55, 65 => 0.65, 80 => 0.80] as $cm => $expected) {
            [, $fitHeight] = $this->fit($this->preset() + ['case_height_cm' => $cm]);

            $this->assertSame($expected, $fitHeight, "a {$cm}cm case fills {$cm}% of the canvas");
        }
    }

    /**
     * The cabin lands where the reference already had it.
     *
     * This is what makes the cm rule a generalisation of the measurement
     * rather than a departure from it: 55% against the 55.25% measured off the
     * finished frame, a quarter of a per cent apart on a 2400-tall canvas.
     */
    public function test_the_cm_rule_agrees_with_the_measured_reference(): void
    {
        [, $cabin] = $this->fit($this->preset() + ['case_height_cm' => 55]);

        $this->assertEqualsWithDelta($this->preset()['fit_height'], $cabin, 0.005);
    }

    /** With no size typed, the supplier's own files pass through as before. */
    public function test_without_a_size_the_preset_is_untouched(): void
    {
        [$fitWidth, $fitHeight] = $this->fit($this->preset());

        $this->assertSame(0.6044, $fitWidth);
        $this->assertSame(0.5525, $fitHeight);
    }

    /**
     * The width ceiling opens up once a size is driving the height.
     *
     * 60.44% by 55.25% is the cabin's own aspect, so a large case driven to
     * 80% of the height would hit the cabin's width ceiling first and come
     * back short — sized by a number that was never about it.
     */
    public function test_a_tall_case_is_not_held_back_by_the_cabins_width(): void
    {
        [$fitWidth] = $this->fit($this->preset() + ['case_height_cm' => 80]);

        $this->assertSame(0.92, $fitWidth);

        // A 80cm upright roughly 52cm wide needs 78% of 1600 to reach 80% of
        // 2400. The old ceiling would have stopped it at 60.44%.
        $this->assertGreaterThan(0.78, $fitWidth);
    }

    /** American Tourister is the same canvas, not a copy of it. */
    public function test_american_tourister_shares_the_samsonite_categories(): void
    {
        foreach (['American Tourister Qatar', 'americantourister.myshopify.com', 'american-tourister-qa.myshopify.com'] as $identity) {
            $this->assertSame(
                ['samsonite', 'samsonite_accessories'],
                array_keys(PhotoroomService::framingPresetsForStore($identity)),
                "{$identity} sees the luggage categories",
            );
        }
    }

    /** And Blue Salon still sees everything neither brand claimed. */
    public function test_blue_salon_is_unaffected(): void
    {
        $keys = array_keys(PhotoroomService::framingPresetsForStore('Blue Salon'));

        $this->assertContains('men', $keys);
        $this->assertNotContains('samsonite', $keys);
    }
}
