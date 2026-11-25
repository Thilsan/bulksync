<?php

namespace Tests\Feature;

use App\Services\PhotoroomService;
use Tests\TestCase;

/**
 * Socks, under Women and under Men.
 *
 * Measured off CHH123ACC00447_0, a finished catalogue frame on a 2000 square:
 * the pack sits 10.00% down, ends 10.00% up from the bottom and fills 80.00%
 * of the height and 29.9% of the width, centred to within half a percent.
 * That is the house rule to the pixel — the cleanest sample measured so far,
 * and the first one that is not a garment at all: socks arrive packaged on a
 * backing card with a hanger hook, and the card is what the frame measures.
 *
 * The sample is a women's pack, so Women is the measured entry and Men carries
 * the same numbers across. Measure a men's pack when one is finished; nothing
 * in a backing card changes between departments, but that is an argument, not
 * a measurement.
 */
class PhotoEditorSocksFramingTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private function edits(string $key): array
    {
        return PhotoroomService::framingPresetsFlat()[$key]['edits'];
    }

    public function test_both_departments_sell_socks(): void
    {
        $flat = PhotoroomService::framingPresetsFlat();

        $this->assertArrayHasKey('women/socks', $flat);
        $this->assertArrayHasKey('men/socks', $flat);
    }

    public function test_socks_are_framed_at_the_measured_padding(): void
    {
        foreach (['women/socks', 'men/socks'] as $key) {
            $edits = $this->edits($key);

            $this->assertSame(2000, $edits['width'], $key);
            $this->assertSame(2000, $edits['height'], $key);
            $this->assertSame(0.10, $edits['padding'], $key);
        }
    }

    /**
     * One set of numbers, not two that drift.
     *
     * Men was written from the women's sample, so the day either is corrected
     * the other has to move with it or the grid holds two framings for the
     * same product photographed the same way.
     */
    public function test_the_two_departments_agree(): void
    {
        $this->assertSame($this->edits('women/socks'), $this->edits('men/socks'));
    }

    /**
     * Nothing is declared to bind, as on ties and pens.
     *
     * A packaged sock is a tall card — 80.0% of the height against 29.9% of
     * the width — so the height decides the size unaided and the padding
     * alone reproduces the sample. Pinning it would be wrong for the loose
     * pair laid flat, which is close to square and would be driven off both
     * side edges reaching a height its shape does not have.
     */
    public function test_nothing_is_pinned_so_a_loose_pair_still_fits(): void
    {
        foreach (['women/socks', 'men/socks'] as $key) {
            $edits = $this->edits($key);

            $this->assertArrayNotHasKey('height_fill', $edits, $key);
            $this->assertArrayNotHasKey('body_fill', $edits, $key);
            $this->assertArrayNotHasKey('padding_top', $edits, $key);
            $this->assertArrayNotHasKey('padding_bottom', $edits, $key);
        }
    }

    /** Centred, like the sample and like every category not stood on a line. */
    public function test_it_is_centred_rather_than_stood_on_a_line(): void
    {
        foreach (['women/socks', 'men/socks'] as $key) {
            $edits = PhotoroomService::applyFramingPreset([], $key);

            $this->assertSame('center', $edits['v_align'], $key);
            $this->assertSame('center', $edits['h_align'], $key);
        }
    }

    /**
     * The padding is what the sample's own geometry asks for.
     *
     * Two paddings and the height between them have to close to 100, or the
     * number in the preset is not the number that was measured.
     */
    public function test_the_padding_and_the_fill_close_to_the_sample(): void
    {
        $padding = $this->edits('women/socks')['padding'];

        $this->assertEqualsWithDelta(0.8000, 1 - 2 * $padding, 0.005,
            'the padding no longer reproduces the height measured off CHH123ACC00447_0');
    }

    /** A cutout prompt has a word for it, as every other category does. */
    public function test_each_department_names_its_product(): void
    {
        $this->assertSame('the socks', PhotoroomService::productNoun('women/socks'));
        $this->assertSame('the socks', PhotoroomService::productNoun('men/socks'));
    }

    /** Offered where the rest of Women and Men is, and not on the brand canvas. */
    public function test_the_right_storefronts_are_offered_it(): void
    {
        foreach (['Bluesalon', 'Blue Salon', 'Paris Gallery'] as $identity) {
            $tree = PhotoroomService::framingPresetsForStore($identity);

            $this->assertArrayHasKey('socks', $tree['women']['subcategories'], $identity);
            $this->assertArrayHasKey('socks', $tree['men']['subcategories'], $identity);
        }

        $this->assertArrayNotHasKey('women', PhotoroomService::framingPresetsForStore('Samsonite Website'));
    }
}
