<?php

namespace Tests\Feature;

use App\Services\PhotoroomService;
use Tests\TestCase;

/**
 * Pressing a garment through the one pass that accepts instructions.
 *
 * Photoroom's ironing takes a single field — ironing.mode — and no text of any
 * kind, so it cannot be told to leave a print alone. On an appliquéd or
 * logo'd garment that is the whole difficulty: the pass decides for itself
 * what a crease is, and a logo is a thing with edges.
 *
 * Edit With AI does take a prompt. Said plainly, it is also the pass that
 * reinvented an Aigner horseshoe monogram as rings on a job that only had to
 * remove a stand it could see — so this exists to be compared against plain
 * ironing on one garment, not because it is expected to win.
 */
class PhotoEditorGuidedIroningTest extends TestCase
{
    public function test_the_prompt_names_what_must_not_change(): void
    {
        $prompt = PhotoroomService::IRONING_PROMPT;

        $this->assertStringContainsString('Press out the wrinkles', $prompt);

        // The parts a regenerative pass invents when nothing names them.
        foreach (['print', 'logo', 'embroidery', 'lettering', 'trim', 'stitching'] as $part) {
            $this->assertStringContainsString($part, $prompt, "the prompt does not protect the {$part}");
        }
    }

    /** Photoroom's own ironing is the default; guided is opted into. */
    public function test_plain_ironing_is_what_happens_unless_guided_is_chosen(): void
    {
        $this->assertSame('', PhotoroomService::defaultEdits()['ironing_mode']);
    }

    /**
     * Guided replaces Photoroom's ironing rather than joining it.
     *
     * Both press the garment. Running them together would press it twice, and
     * the second pass would be working on fabric the first had already
     * redrawn.
     */
    public function test_guided_turns_photorooms_own_ironing_off(): void
    {
        $fields = app(PhotoroomService::class)->buildFields([
            'remove_background' => true,
            'ironing'           => false,
            'ironing_mode'      => 'guided',
        ]);

        $this->assertArrayNotHasKey('ironing.mode', $fields);
    }

    /** And Photoroom's own ironing still sends what it always did. */
    public function test_plain_ironing_still_sends_the_mode(): void
    {
        $fields = app(PhotoroomService::class)->buildFields([
            'remove_background' => true,
            'ironing'           => true,
        ]);

        $this->assertSame('ai.auto', $fields['ironing.mode'] ?? null);
    }
}
