<?php

namespace Tests\Feature;

use App\Jobs\EditPhotoItemJob;
use Tests\TestCase;

/**
 * Publishing a redraw that changed the garment's proportions, when the operator
 * has asked to — and only that, not any redraw that reworked the garment.
 *
 * The default — refuse it and keep the photograph — is right and stays right: a
 * recut garment is a picture of a product that does not exist. But on a dress
 * form inside a floor-length skirt the redraw is refused every time, measured at
 * 41%, 35% and 32% across three runs of the same two photographs, and the
 * operator is handed back a mannequin they asked to have removed with no way
 * through. Where they have looked at that and decided, it is their catalogue.
 *
 * The first version of this let through anything that was not 'moved',
 * including 'redrawn' — proportions fine, but the garment's own surface
 * differing by more than a third. Measured at 87.8% on a smocked blouse: not a
 * recut, a different garment wearing the right silhouette, published because a
 * checkbox meant for one bounded trade also covered a worse failure standing
 * next to it. Only 'reshaped' is kept now.
 *
 * What is not negotiable is the position. Keeping the photograph's own placement
 * and direction is the one thing the redraw prompt exists to secure, so a
 * 'moved' verdict still falls back however this is set.
 */
class PhotoEditorRecutRedrawTest extends TestCase
{
    private function keeps(array $edits, string $verdict, ?float $aspectShift = 0.10): bool
    {
        $method = new \ReflectionMethod(EditPhotoItemJob::class, 'keepsARecutRedraw');
        $method->setAccessible(true);

        return $method->invoke(new EditPhotoItemJob(1), $edits, $verdict, $aspectShift);
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
    public function test_a_reshaped_redraw_is_kept_when_the_run_allows_it(): void
    {
        $this->assertTrue($this->keeps(['accept_recut_redraw' => true], 'reshaped', 0.10));
    }

    /**
     * A reshape has a ceiling even with the checkbox on.
     *
     * The skirt this feature was built for topped out at 41%. A later batch
     * run published 49-69% routinely, because 'reshaped' had a floor that
     * classified it (past 7%) and no ceiling that capped it — any amount past
     * that was accepted equally. Past 55% is most of the garment's own
     * proportions, not a neckline's worth, and no longer the bounded trade
     * this checkbox describes.
     */
    public function test_a_reshape_past_the_ceiling_is_refused_even_when_allowed(): void
    {
        $this->assertTrue($this->keeps(['accept_recut_redraw' => true], 'reshaped', 0.55),
            'the ceiling itself should still be accepted');
        $this->assertFalse($this->keeps(['accept_recut_redraw' => true], 'reshaped', 0.56),
            'a reshape past the ceiling was published anyway');
        $this->assertFalse($this->keeps(['accept_recut_redraw' => true], 'reshaped', 0.69),
            'the 69%% case from the batch run was published anyway');
    }

    /**
     * A redrawn garment is refused even when recuts are allowed.
     *
     * 'redrawn' means proportions were fine but the surface itself differs by
     * more than a third — a smocked blouse came back 87.8% different, its
     * pattern reworked rather than reproduced. That is not the bounded "cut is
     * slightly different" trade the checkbox describes; it is a different
     * garment in the right silhouette, and no setting here publishes that.
     */
    public function test_a_redrawn_garment_is_refused_even_when_recuts_are_allowed(): void
    {
        $this->assertFalse($this->keeps(['accept_recut_redraw' => true], 'redrawn'),
            'a redraw that reworked the garment\'s own surface was published anyway');
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
