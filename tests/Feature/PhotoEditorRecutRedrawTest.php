<?php

namespace Tests\Feature;

use App\Jobs\EditPhotoItemJob;
use Tests\TestCase;

/**
 * Publishing a redraw that reworked the garment, when the operator has asked to.
 *
 * The default — refuse it and keep the photograph — is right and stays right: a
 * recut garment is a picture of a product that does not exist. But on a dress
 * form inside a floor-length skirt the redraw is refused every time, measured at
 * 41%, 35% and 32% across three runs of the same two photographs, and the
 * operator is handed back a mannequin they asked to have removed with no way
 * through. Where they have looked at that and decided, it is their catalogue.
 *
 * What is not negotiable is the position. Keeping the photograph's own placement
 * and direction is the one thing the redraw prompt exists to secure, so a
 * 'moved' verdict still falls back however this is set.
 */
class PhotoEditorRecutRedrawTest extends TestCase
{
    private function keeps(array $edits, string $verdict): bool
    {
        $method = new \ReflectionMethod(EditPhotoItemJob::class, 'keepsARecutRedraw');
        $method->setAccessible(true);

        return $method->invoke(new EditPhotoItemJob(1), $edits, $verdict);
    }

    /** Off unless asked for: the safe answer stays the default. */
    public function test_a_recut_redraw_is_refused_by_default(): void
    {
        foreach (['reshaped', 'redrawn', 'moved'] as $verdict) {
            $this->assertFalse($this->keeps([], $verdict),
                "a {$verdict} redraw was published without being asked for");
        }
    }

    /** The case it was built for: the garment held still but came back recut. */
    public function test_a_recut_redraw_is_kept_when_the_run_allows_it(): void
    {
        $this->assertTrue($this->keeps(['accept_recut_redraw' => true], 'reshaped'));
        $this->assertTrue($this->keeps(['accept_recut_redraw' => true], 'redrawn'));
    }

    /**
     * A moved garment is refused however this is set.
     *
     * "Do not change the position or the direction" is the whole of what the
     * redraw prompt asks for in exchange for being allowed to redraw at all, so
     * it is not something a checkbox can waive.
     */
    public function test_a_moved_garment_is_refused_even_when_recuts_are_allowed(): void
    {
        $this->assertFalse($this->keeps(['accept_recut_redraw' => true], 'moved'),
            'a redraw that moved or tilted the garment was published anyway');
    }
}
