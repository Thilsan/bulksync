<?php

namespace Tests\Feature;

use App\Jobs\EditPhotoItemJob;
use App\Services\PhotoroomService;
use Tests\TestCase;

/**
 * A back view is named to Photoroom, because it keeps coming back as a front.
 *
 * A pink slip photographed from behind — straight neckline, wide straps over
 * the shoulders — came back with a V-neck and lace at the bust: the front of
 * the garment, on a prompt whose words are "keep the same orientation".
 * Naming the view is the one guidance Photoroom suggested for a drift this
 * specific.
 *
 * A person decides which photos are backs: read from the filename where the
 * shoot names it, ticked on the configure screen where it doesn't. Gemini used
 * to classify it and was removed for labelling one dress's two views front and
 * back on one run and front and front on the next — and a wrong "this is the
 * back" is worse than silence, because it would turn a front view round.
 */
class PhotoEditorBackViewTest extends TestCase
{
    private function route(array $edits, bool $isBackView): array
    {
        $method = new \ReflectionMethod(EditPhotoItemJob::class, 'chooseApparelRoute');
        $method->setAccessible(true);

        return $method->invoke(new EditPhotoItemJob(1), $edits, 900, 1600, $isBackView);
    }

    public function test_a_back_view_is_told_to_stay_a_back_view(): void
    {
        [, $edits] = $this->route(
            ['ghost_mannequin' => true, 'apparel_prompt' => PhotoroomService::GHOST_MANNEQUIN_HINT],
            true,
        );

        $this->assertStringContainsString('back of the garment', $edits['apparel_prompt']);
        $this->assertStringContainsString('do not turn it round', $edits['apparel_prompt']);
    }

    /** The operator's own wording is kept — this is added to it, not instead of it. */
    public function test_the_typed_wording_survives(): void
    {
        [, $edits] = $this->route(
            ['ghost_mannequin' => true, 'apparel_prompt' => 'keep the lace'],
            true,
        );

        $this->assertStringStartsWith('keep the lace', $edits['apparel_prompt']);
    }

    /** Anything not marked as a back is left alone. */
    public function test_an_unmarked_photo_says_nothing_extra(): void
    {
        [, $edits] = $this->route(
            ['ghost_mannequin' => true, 'apparel_prompt' => 'keep the lace'],
            false,
        );

        $this->assertSame('keep the lace', $edits['apparel_prompt']);
    }

    /** A filename naming the back ticks the box; a word containing it does not. */
    public function test_the_filename_rule_reads_only_a_whole_word(): void
    {
        $isBack = fn (string $f) => (bool) preg_match('/\\bback\\b/i', str_replace(['-', '_', '.'], ' ', $f));

        $this->assertTrue($isBack('brotini-sandal-back.webp'));
        $this->assertTrue($isBack('dress_back_view.jpg'));
        $this->assertTrue($isBack('0_0-BACK.png'));

        $this->assertFalse($isBack('backless-slip.jpg'), 'a backless slip is a front view');
        $this->assertFalse($isBack('backpack-01.jpg'));
        $this->assertFalse($isBack('1_0.jpg'));
    }
}
