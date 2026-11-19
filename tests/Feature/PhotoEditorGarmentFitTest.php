<?php

namespace Tests\Feature;

use App\Jobs\EditPhotoItemJob;
use App\Services\PhotoroomService;
use Tests\TestCase;

/**
 * The redraw is told whose body it is filling.
 *
 * A women's printed shirt came back with a man's build — wide square
 * shoulders, straight heavy sleeves, the waist gone. Ghost Mannequin invents
 * the body inside the garment, and nothing in the prompt had ever said whose
 * body it is, so it drew the average of everything it has seen. Both views of
 * that SKU were refused by the verifier for it, at 15.8% and 30.6% against a
 * 7% limit, and kept only because the accept-recut override was ticked.
 *
 * The answer was already on the screen: the category the operator picked
 * carries the gender in its first segment. So the clause is appended from the
 * category rather than being one more chip to remember.
 */
class PhotoEditorGarmentFitTest extends TestCase
{
    private function route(array $edits, bool $isBackView = false): array
    {
        $method = new \ReflectionMethod(EditPhotoItemJob::class, 'chooseApparelRoute');
        $method->setAccessible(true);

        return $method->invoke(new EditPhotoItemJob(1), $edits, 900, 1600, $isBackView);
    }

    public function test_a_womens_category_asks_for_a_womans_figure(): void
    {
        [, $edits] = $this->route([
            'ghost_mannequin' => true,
            'apparel_prompt'  => PhotoroomService::GHOST_MANNEQUIN_HINT,
            'framing_preset'  => 'women/top',
        ]);

        $this->assertStringContainsString("woman's figure", $edits['apparel_prompt']);
        $this->assertStringContainsString("Do not broaden it into a man's build", $edits['apparel_prompt']);
        $this->assertStringStartsWith(PhotoroomService::GHOST_MANNEQUIN_HINT, $edits['apparel_prompt']);
    }

    public function test_a_mens_category_asks_for_a_mans_build(): void
    {
        [, $edits] = $this->route([
            'ghost_mannequin' => true,
            'apparel_prompt'  => PhotoroomService::GHOST_MANNEQUIN_HINT,
            'framing_preset'  => 'men/shirts',
        ]);

        $this->assertStringContainsString("man's build", $edits['apparel_prompt']);
        $this->assertStringNotContainsString("woman's figure", $edits['apparel_prompt']);
    }

    public function test_a_kids_category_asks_for_a_childs_build(): void
    {
        [, $edits] = $this->route([
            'ghost_mannequin' => true,
            'apparel_prompt'  => PhotoroomService::GHOST_MANNEQUIN_HINT,
            'framing_preset'  => 'kids/tops',
        ]);

        $this->assertStringContainsString("child's build", $edits['apparel_prompt']);
        $this->assertStringContainsString('Do not draw it on an adult', $edits['apparel_prompt']);
    }

    /** A chip the operator clicked wins over the category's guess. */
    public function test_a_fit_already_named_is_not_overruled(): void
    {
        [, $edits] = $this->route([
            'ghost_mannequin' => true,
            'apparel_prompt'  => PhotoroomService::GHOST_MANNEQUIN_HINT
                . '. ' . PhotoroomService::GHOST_MANNEQUIN_FIT_MENS,
            'framing_preset'  => 'women/top',
        ]);

        $this->assertStringContainsString("man's build", $edits['apparel_prompt']);
        $this->assertStringNotContainsString("woman's figure", $edits['apparel_prompt']);
        $this->assertSame(1, substr_count($edits['apparel_prompt'], 'This is a'));
    }

    /** Nothing with a body to cut for, nothing said. */
    public function test_a_category_with_no_body_says_nothing(): void
    {
        foreach (['samsonite', 'footwear', 'watches_jewellery/watches', null] as $preset) {
            [, $edits] = $this->route([
                'ghost_mannequin' => true,
                'apparel_prompt'  => PhotoroomService::GHOST_MANNEQUIN_HINT,
                'framing_preset'  => $preset,
            ]);

            $this->assertSame(
                PhotoroomService::GHOST_MANNEQUIN_HINT,
                $edits['apparel_prompt'],
                $preset ?? 'no category',
            );
        }
    }

    /** It sits alongside the back-view sentence rather than replacing it. */
    public function test_a_back_view_keeps_both_sentences(): void
    {
        [, $edits] = $this->route([
            'ghost_mannequin' => true,
            'apparel_prompt'  => PhotoroomService::GHOST_MANNEQUIN_HINT,
            'framing_preset'  => 'women/dresses',
        ], true);

        $this->assertStringContainsString("woman's figure", $edits['apparel_prompt']);
        $this->assertStringContainsString('back of the garment', $edits['apparel_prompt']);
    }

    /** An empty hint field gets the clause on its own, with no stray leading dot. */
    public function test_an_empty_hint_is_not_left_with_a_leading_dot(): void
    {
        [, $edits] = $this->route([
            'ghost_mannequin' => true,
            'apparel_prompt'  => '',
            'framing_preset'  => 'women/top',
        ]);

        $this->assertStringStartsWith('This is a woman', $edits['apparel_prompt']);
    }

    public function test_the_lookup_reads_only_the_first_segment(): void
    {
        $this->assertSame(
            PhotoroomService::GHOST_MANNEQUIN_FIT_WOMENS,
            PhotoroomService::ghostMannequinFit('women/swim-wear'),
        );
        $this->assertNull(PhotoroomService::ghostMannequinFit('home'));
        $this->assertNull(PhotoroomService::ghostMannequinFit(''));
        $this->assertNull(PhotoroomService::ghostMannequinFit(null));
    }
}
