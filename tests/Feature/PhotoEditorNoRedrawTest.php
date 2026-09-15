<?php

namespace Tests\Feature;

use App\Jobs\EditPhotoItemJob;
use App\Services\PhotoroomService;
use Tests\TestCase;

/**
 * A categorised run keeps the photograph rather than being redrawn.
 *
 * Ghost Mannequin does not erase a mannequin from a photograph. It generates a
 * new garment from what it sees, and on anything draped or irregular it guesses
 * — a sequinned poncho's back view came back with the fabric hanging as sleeves,
 * a different garment from its own front view. The badge said REDRAWN BY AI,
 * which was accurate and too late: nothing downstream can tell a confident
 * redraw from a photograph, so the only place to refuse one is before it is
 * asked for.
 *
 * Every category already knows what its product is. Naming it cuts the mannequin
 * out of the real photograph in a single request, and the pixels that come back
 * are the ones the camera recorded.
 */
class PhotoEditorNoRedrawTest extends TestCase
{
    /** The decision under test, run without a queue or an API behind it. */
    private function route(array $edits, bool $mannequinVisible, ?string $seen = null): array
    {
        $method = new \ReflectionMethod(EditPhotoItemJob::class, 'chooseApparelRoute');
        $method->setAccessible(true);

        return $method->invoke(new EditPhotoItemJob(1), $edits, $mannequinVisible, $seen);
    }

    /**
     * The case that was reported: a categorised run, the redraw ticked, a
     * mannequin in shot. It must still not redraw.
     */
    public function test_a_category_names_its_product_instead_of_redrawing(): void
    {
        [$mode, $itemEdits] = $this->route(
            ['framing_preset' => 'women/top', 'ghost_mannequin' => true],
            true,
        );

        $this->assertSame('segmented', $mode, 'a categorised run was still sent to be redrawn');
        $this->assertSame('the top', $itemEdits['segmentation_prompt'] ?? null);
        $this->assertFalse((bool) ($itemEdits['ghost_mannequin'] ?? false));
    }

    /** Every category with a product noun refuses the redraw, not just tops. */
    public function test_no_category_with_a_named_product_is_ever_redrawn(): void
    {
        foreach (PhotoroomService::PRODUCT_NOUNS as $key => $noun) {
            [$mode, $itemEdits] = $this->route(
                ['framing_preset' => $key, 'ghost_mannequin' => true],
                true,
            );

            $this->assertSame('segmented', $mode, "{$key} is still sent to be redrawn");
            $this->assertSame($noun, $itemEdits['segmentation_prompt'] ?? null);
        }
    }

    /**
     * What the classifier saw beats what the folder is called.
     *
     * This is the case that was reported. A scarf worn as a cape, filed under
     * tops because that is how it is merchandised, was described to the cutout
     * as "the top" — and a text-guided segmentation handed a word that matches
     * nothing selects nothing, so the mannequin and the studio floor came back
     * with it. The classifier is already looking at the photograph and already
     * paid for; it names the garment in front of the camera.
     */
    public function test_what_the_classifier_saw_beats_the_folder_it_came_from(): void
    {
        [$mode, $itemEdits] = $this->route(
            ['framing_preset' => 'women/top', 'ghost_mannequin' => true],
            true,
            'the scarf',
        );

        $this->assertSame('segmented', $mode);
        $this->assertSame('the scarf', $itemEdits['segmentation_prompt'] ?? null,
            'the folder\'s word was used on a photograph of something else');
    }

    /** No classification, or none it trusted: the category is still there. */
    public function test_the_category_is_the_fallback_when_nothing_was_seen(): void
    {
        [, $itemEdits] = $this->route(
            ['framing_preset' => 'women/top', 'ghost_mannequin' => true],
            true,
            null,
        );

        $this->assertSame('the top', $itemEdits['segmentation_prompt'] ?? null);
    }

    /** What somebody typed themselves wins over both. */
    public function test_a_typed_product_is_not_overwritten(): void
    {
        [, $itemEdits] = $this->route(
            ['framing_preset' => 'women/top', 'segmentation_prompt' => 'the poncho', 'ghost_mannequin' => true],
            true,
            'the scarf',
        );

        $this->assertSame('the poncho', $itemEdits['segmentation_prompt'] ?? null);

        // And a word somebody chose is trusted, so it keeps the confident mode.
        $this->assertArrayNotHasKey('segmentation_prompt_is_a_guess', $itemEdits);
    }

    /**
     * A cutout with nothing to erase is working already. Swapping its matting
     * for a text prompt would be changing what is not broken.
     */
    public function test_a_photo_with_no_mannequin_is_left_to_its_own_matting(): void
    {
        [, $itemEdits] = $this->route(
            ['framing_preset' => 'women/top', 'ghost_mannequin' => true],
            false,
        );

        $this->assertNull($itemEdits['segmentation_prompt'] ?? null);
    }

    /**
     * The redraw is still reachable. A category nobody has given a word to has
     * nothing better to offer, and the operator did ask for it.
     */
    public function test_an_unnamed_category_can_still_be_redrawn(): void
    {
        [$mode] = $this->route(
            ['framing_preset' => 'women/gown-unlisted', 'ghost_mannequin' => true],
            true,
        );

        $this->assertSame('ghost_mannequin', $mode);
    }
}
