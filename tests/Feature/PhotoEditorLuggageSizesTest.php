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
        $fills = [];

        foreach ([55, 65, 80] as $cm) {
            [, $fills[$cm]] = $this->fit($this->preset() + ['case_height_cm' => $cm]);
        }

        $this->assertEqualsWithDelta(0.80,  $fills[55], 0.001, 'a cabin fills 80% of the height');
        $this->assertEqualsWithDelta(0.848, $fills[65], 0.001, 'a medium sits between the two');
        $this->assertEqualsWithDelta(0.92,  $fills[80], 0.001, 'a large fills 92%');
    }

    /**
     * Far enough apart to see, close enough not to float.
     *
     * Both ends of this were rejected by looking at them. The plain cm rule —
     * 55% for a cabin, 80% for a large — is what Samsonite's own site does,
     * and it leaves a cabin with 40% of the canvas empty above it. The brand
     * reference files are the opposite: each fills its own frame at 89.1%,
     * 92.9% and 94.2%, five points across the whole range, so a cabin and a
     * large look the same size standing next to each other.
     *
     * Blue Salon's 2000 square still uses the plain rule. This is the
     * baseline canvas only, which is why the change lives beside it and not
     * in caseFill.
     */
    public function test_the_sizes_stay_visibly_apart_without_floating(): void
    {
        [, $cabin] = $this->fit($this->preset() + ['case_height_cm' => 55]);
        [, $large] = $this->fit($this->preset() + ['case_height_cm' => 80]);

        $spread = $large - $cabin;

        $this->assertGreaterThan(0.051, $spread, 'wider than the reference files, which are indistinguishable');
        $this->assertLessThan(0.25, $spread, 'narrower than the plain cm rule, which leaves cabins floating');
        $this->assertGreaterThan(0.75, $cabin, 'and the smallest case still fills most of the frame');
    }

    /** A size well outside the three named ones still fits on the canvas. */
    public function test_an_unusual_height_is_clamped_to_the_canvas(): void
    {
        [, $tiny] = $this->fit($this->preset() + ['case_height_cm' => 10]);
        [, $huge] = $this->fit($this->preset() + ['case_height_cm' => 120]);

        $this->assertSame(0.70, $tiny);
        $this->assertSame(0.95, $huge);
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
