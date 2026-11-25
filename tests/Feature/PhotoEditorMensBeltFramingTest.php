<?php

namespace Tests\Feature;

use App\Services\PhotoroomService;
use Tests\TestCase;

/**
 * Belts under Men, carried across from Women.
 *
 * Womenswear measured 11.0% and 10.8% left and right on a 2000 square, and
 * sunglasses — the only other category wider than it is tall — landed on 11.1%
 * and 11.7% from an unrelated sample. That is the same figure twice, from two
 * products whose only shared property is their shape, which is as close to a
 * house rule for wide objects as the table has.
 *
 * So the men's entry is the women's numbers, not a guess at them. A belt is
 * the same shape in either department on the same canvas. Measure a men's
 * sample when one is finished; until then this is an argument, not a reading,
 * and the note in the table says so.
 */
class PhotoEditorMensBeltFramingTest extends TestCase
{
    public function test_men_have_belts_too(): void
    {
        $this->assertArrayHasKey('men/belts', PhotoroomService::framingPresetsFlat());
    }

    /**
     * One set of numbers, not two that drift.
     *
     * The men's entry exists only because the women's one was measured, so
     * the day either moves the other has to move with it — otherwise the grid
     * holds two framings for the same object photographed the same way.
     */
    public function test_the_two_departments_agree(): void
    {
        $flat = PhotoroomService::framingPresetsFlat();

        $this->assertSame($flat['women/belts']['edits'], $flat['men/belts']['edits']);
    }

    public function test_it_keeps_the_padding_womenswear_measured(): void
    {
        $edits = PhotoroomService::framingPresetsFlat()['men/belts']['edits'];

        $this->assertSame(2000, $edits['width']);
        $this->assertSame(2000, $edits['height']);
        $this->assertSame(0.11, $edits['padding']);
    }

    /**
     * Nothing is pinned, and the width is left to bind on its own.
     *
     * A belt is wider than it is tall, so the width decides the size unaided
     * and the padding alone reproduces the sample — the same reasoning as the
     * caps entry, which is the other wide shape in menswear.
     */
    public function test_nothing_is_pinned_so_the_width_binds_on_its_own(): void
    {
        $edits = PhotoroomService::framingPresetsFlat()['men/belts']['edits'];

        $this->assertArrayNotHasKey('height_fill', $edits);
        $this->assertArrayNotHasKey('body_fill', $edits);
        $this->assertArrayNotHasKey('padding_top', $edits);
        $this->assertArrayNotHasKey('padding_bottom', $edits);
    }

    /** Centred, like the womenswear sample and like every category not stood on a line. */
    public function test_it_is_centred_rather_than_stood_on_a_line(): void
    {
        $edits = PhotoroomService::applyFramingPreset([], 'men/belts');

        $this->assertSame('center', $edits['v_align']);
        $this->assertSame('center', $edits['h_align']);
    }

    /** A cutout prompt has a word for it, as every other category does. */
    public function test_the_category_names_its_product(): void
    {
        $this->assertSame('the belt', PhotoroomService::productNoun('men/belts'));
        $this->assertSame('the belt', PhotoroomService::productNoun('women/belts'));
    }

    /** Offered wherever the rest of Men is. */
    public function test_the_right_storefronts_are_offered_it(): void
    {
        foreach (['Bluesalon', 'Blue Salon', 'Paris Gallery'] as $identity) {
            $this->assertArrayHasKey(
                'belts',
                PhotoroomService::framingPresetsForStore($identity)['men']['subcategories'],
                $identity,
            );
        }

        $this->assertArrayNotHasKey('men', PhotoroomService::framingPresetsForStore('Samsonite Website'));
    }
}
