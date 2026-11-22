<?php

namespace Tests\Feature;

use App\Services\PhotoroomService;
use Tests\TestCase;

/**
 * Pens, for Blue Salon and Paris Gallery.
 *
 * Measured off PERGR0001502_01, a finished catalogue frame on a 2400 square:
 * the pen sits 8.33% down, ends 8.29% up from the bottom and fills 83.38% of
 * the height, 7.83% of the width, centred to within four pixels either way.
 *
 * Carried to the 2000 square every Blue Salon category shares, that is 8.3%
 * all round and nothing else — a pen is far taller than it is wide, so the
 * height decides the size unaided. Running the real framing over the sample
 * lands it at 8.30% / 83.00% / 8.70%, which is the tolerance doing its work
 * rather than a different rule.
 *
 * One sample. This is the entry to re-measure when there are three.
 */
class PhotoEditorPenFramingTest extends TestCase
{
    private function edits(): array
    {
        return PhotoroomService::framingPresetsFlat()['pens']['edits'];
    }

    public function test_the_pen_is_framed_at_the_measured_padding(): void
    {
        $edits = $this->edits();

        $this->assertSame(2000, $edits['width']);
        $this->assertSame(2000, $edits['height']);
        $this->assertSame(0.083, $edits['padding']);
    }

    /**
     * Nothing is declared to bind, and that is the decision.
     *
     * Pinning the height at the measured 83.38% is right for every pen stood
     * upright and wrong for the one lying across the frame or shot beside its
     * box — that one would be driven past both side edges reaching a height
     * its shape does not have. The padding alone already reproduces the
     * sample, so the bind would buy nothing and cost that.
     */
    public function test_nothing_is_pinned_so_a_pen_on_its_side_still_fits(): void
    {
        $edits = $this->edits();

        $this->assertArrayNotHasKey('height_fill', $edits);
        $this->assertArrayNotHasKey('body_fill', $edits);
        $this->assertArrayNotHasKey('padding_bottom', $edits);
        $this->assertArrayNotHasKey('padding_top', $edits);
    }

    /** Centred, like the sample and like every category that is not stood on a line. */
    public function test_it_is_centred_rather_than_stood_on_a_line(): void
    {
        $edits = PhotoroomService::applyFramingPreset([], 'pens');

        $this->assertSame('center', $edits['v_align']);
        $this->assertSame('center', $edits['h_align']);
    }

    /**
     * The padding is what the sample's own geometry asks for.
     *
     * Two paddings and the height between them have to close to 100, or the
     * number in the preset is not the number that was measured.
     */
    public function test_the_padding_and_the_fill_close_to_the_sample(): void
    {
        $padding = $this->edits()['padding'];

        $this->assertEqualsWithDelta(0.8338, 1 - 2 * $padding, 0.005,
            'the padding no longer reproduces the height measured off PERGR0001502_01');
    }

    /** Both storefronts that sell pens are offered the category. */
    public function test_blue_salon_and_paris_gallery_both_see_it(): void
    {
        foreach (['Bluesalon', 'Blue Salon', 'Paris Gallery', 'parisgallery.myshopify.com'] as $identity) {
            $this->assertArrayHasKey(
                'pens',
                PhotoroomService::framingPresetsForStore($identity),
                "{$identity} was not offered the pen category",
            );
        }
    }

    /** And the brand with its own canvas still is not. */
    public function test_samsonite_is_not_offered_it(): void
    {
        $this->assertArrayNotHasKey('pens', PhotoroomService::framingPresetsForStore('Samsonite Website'));
        $this->assertArrayNotHasKey('pens', PhotoroomService::framingPresetsForStore('American Tourister'));
    }

    /** A cutout prompt has a word for it, as every other category does. */
    public function test_the_category_names_its_product(): void
    {
        $this->assertSame('the pen', PhotoroomService::productNoun('pens'));
    }
}
