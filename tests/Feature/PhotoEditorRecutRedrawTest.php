<?php

namespace Tests\Feature;

use App\Jobs\EditPhotoItemJob;
use Tests\TestCase;

/**
 * Which verdicts "keep the redraw even if recut" covers at all — not whether
 * a particular redraw actually is the same garment, which is a separate,
 * per-photo question answered by Gemini (EditPhotoItemJob::confirmsAsSameGarment(),
 * exercised at the job level in PhotoEditorNoRedrawTest).
 *
 * The default — refuse a changed redraw and keep the photograph — is right
 * and stays right: a recut garment is a picture of a product that does not
 * exist. But on a dress form inside a floor-length skirt the redraw is
 * refused every time, measured at 41%, 35% and 32% across three runs of the
 * same two photographs, and the operator is handed back a mannequin they
 * asked to have removed with no way through. Where they have looked at that
 * and decided, it is their catalogue.
 *
 * 'reshaped' and 'redrawn' are both covered, and were not always covered the
 * same way. 'reshaped' used to also have to clear a 55% ceiling on
 * aspect_shift before anything else was asked. The ceiling caused the exact
 * failure it was meant to prevent: a sequin gown's front view measured 58.1%
 * and was refused outright, its own back view measured 53.8% and was kept —
 * four points apart on the same dress. A fixed percentage cannot tell "still
 * the same garment" from "a different one" any better than having no check
 * at all, so the ceiling was removed rather than tuned again, and both
 * verdicts now rest entirely on Gemini's answer instead.
 *
 * What is not negotiable is the position. Keeping the photograph's own
 * placement and direction is the one thing the redraw prompt exists to
 * secure, so a 'moved' verdict is never covered, however this is set.
 */
class PhotoEditorRecutRedrawTest extends TestCase
{
    private function wants(array $edits, string $verdict): bool
    {
        $method = new \ReflectionMethod(EditPhotoItemJob::class, 'wantsRecutKept');
        $method->setAccessible(true);

        return $method->invoke(new EditPhotoItemJob(1), $edits, $verdict);
    }

    /** Off unless asked for: the safe answer stays the default. */
    public function test_a_recut_redraw_is_refused_by_default(): void
    {
        foreach (['reshaped', 'redrawn', 'moved'] as $verdict) {
            $this->assertFalse($this->wants([], $verdict),
                "a {$verdict} redraw was published without being asked for");
        }
    }

    /** The case it was built for: the garment held still but came back recut. */
    public function test_a_reshaped_redraw_is_covered_when_the_run_allows_it(): void
    {
        $this->assertTrue($this->wants(['accept_recut_redraw' => true], 'reshaped'));
    }

    /**
     * 'redrawn' is covered too, on the same terms as 'reshaped' — the
     * ceiling that used to separate them is gone. Whether a specific
     * 'redrawn' photo is actually the same garment is Gemini's question, not
     * this method's; a smocked blouse measured 87.8% different because the
     * model had reworked its pattern outright, and that is exactly the case
     * confirmsAsSameGarment exists to catch, not this one.
     */
    public function test_a_redrawn_verdict_is_covered_when_the_run_allows_it(): void
    {
        $this->assertTrue($this->wants(['accept_recut_redraw' => true], 'redrawn'));
    }

    /**
     * A moved garment is never covered, however this is set.
     *
     * "Do not change the position or the direction" is the whole of what the
     * redraw prompt asks for in exchange for being allowed to redraw at all,
     * so it is not something a checkbox — or a second opinion on the
     * garment itself — can waive.
     */
    public function test_a_moved_garment_is_never_covered(): void
    {
        $this->assertFalse($this->wants(['accept_recut_redraw' => true], 'moved'),
            'a redraw that moved or tilted the garment was covered by the checkbox');
    }
}
