<?php

namespace Tests\Feature;

use App\Jobs\EditPhotoItemJob;
use App\Services\PhotoroomService;
use Tests\TestCase;

/**
 * A back view is named to Photoroom, because it keeps coming back as a front.
 *
 * Gemini classifies every photo as front, back, side or flat lay, and that
 * reading went into the database and no further — Ghost Mannequin was left to
 * work it out. On a back view it often does not. A pink slip photographed from
 * behind, straight neckline and wide straps over the shoulders, came back with
 * a V-neck and lace at the bust: the front of the garment, on a prompt whose
 * words are "keep the same orientation".
 *
 * Only a back is named. A front is what the model assumes anyway, and side and
 * flat lay are rarer and harder to describe usefully — Photoroom's own advice
 * is to add guidance where something specific drifts, and this is the specific
 * thing.
 */
class PhotoEditorBackViewTest extends TestCase
{
    private function route(array $edits, string $viewType): array
    {
        $method = new \ReflectionMethod(EditPhotoItemJob::class, 'chooseApparelRoute');
        $method->setAccessible(true);

        return $method->invoke(new EditPhotoItemJob(1), $edits, 900, 1600, $viewType);
    }

    public function test_a_back_view_is_told_to_stay_a_back_view(): void
    {
        [, $edits] = $this->route(
            ['ghost_mannequin' => true, 'apparel_prompt' => PhotoroomService::GHOST_MANNEQUIN_HINT],
            'back',
        );

        $this->assertStringContainsString('back of the garment', $edits['apparel_prompt']);
        $this->assertStringContainsString('do not turn it round', $edits['apparel_prompt']);
    }

    /** The operator's own wording is kept — this is added to it, not instead of it. */
    public function test_the_typed_wording_survives(): void
    {
        [, $edits] = $this->route(
            ['ghost_mannequin' => true, 'apparel_prompt' => 'keep the lace'],
            'back',
        );

        $this->assertStringStartsWith('keep the lace', $edits['apparel_prompt']);
    }

    /** A front is left alone: it is what the model assumes without being told. */
    public function test_a_front_view_says_nothing_extra(): void
    {
        [, $edits] = $this->route(
            ['ghost_mannequin' => true, 'apparel_prompt' => 'keep the lace'],
            'front',
        );

        $this->assertSame('keep the lace', $edits['apparel_prompt']);
    }

    /** And so are the views we cannot describe usefully. */
    public function test_side_flat_lay_and_unknown_say_nothing_extra(): void
    {
        foreach (['side', 'flat_lay', 'unknown', ''] as $view) {
            [, $edits] = $this->route(
                ['ghost_mannequin' => true, 'apparel_prompt' => 'keep the lace'],
                $view,
            );

            $this->assertSame('keep the lace', $edits['apparel_prompt'], "{$view} picked up a back-view note");
        }
    }
}
