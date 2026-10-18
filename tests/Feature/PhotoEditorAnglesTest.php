<?php

namespace Tests\Feature;

use App\Jobs\GenerateAngleImageJob;
use App\Models\PhotoEditGroup;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Views of a product nobody photographed.
 *
 * Photoroom's Edit With AI takes a prompt naming a view, and their own
 * documentation says it "generates new images rather than rotating existing
 * ones". So an angle is the model's idea of that side: a sole pattern, a heel
 * counter, a logo on a back panel are not derivable from the view that was
 * shot, and the same pass has already reinvented a printed monogram on this
 * account on a job that only had to remove a stand it could see.
 *
 * Which is why these arrive unselected and badged. The decision is per SKU
 * rather than per run — a plain shoe can carry a generated side view and a
 * branded one cannot, and nobody can make that call for a whole folder.
 */
class PhotoEditorAnglesTest extends TestCase
{
    use RefreshDatabase;

    private function startRun(array $angles): array
    {
        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $session = PhotoEditSession::create([
            'user_id' => $user->id, 'name' => 'Run', 'onedrive_link' => 'https://example.com',
            'edits' => ['remove_background' => true], 'status' => 'configuring', 'scan_status' => 'scanned',
        ]);

        $group = PhotoEditGroup::create([
            'photo_edit_session_id' => $session->id, 'sku' => 'SHOE-1', 'edits' => null,
        ]);

        $photo = PhotoEditItem::create([
            'photo_edit_session_id' => $session->id, 'kind' => 'cutout',
            'filename' => 'top.jpg', 'sku_detected' => 'SHOE-1', 'status' => 'pending',
            'onedrive_drive_id' => 'd', 'onedrive_item_id' => 'i',
        ]);

        $this->actingAs($user)->post(route('photo-editor.start', $session), [
            'groups' => [$group->id => [
                'lifestyle_count' => 0,
                'angles' => $angles,
                'angle_source_item_id' => $photo->id,
            ]],
        ])->assertRedirect();

        return [$session, $photo];
    }

    public function test_a_ticked_angle_becomes_its_own_photograph_to_review(): void
    {
        Queue::fake();
        Http::fake(['*/v2/account' => Http::response('', 503)]);

        [$session, $photo] = $this->startRun(['side', 'back']);

        $angles = PhotoEditItem::where('photo_edit_session_id', $session->id)
            ->where('kind', 'angle')->orderBy('filename')->get();

        $this->assertCount(2, $angles);
        $this->assertSame(['top-back.jpg', 'top-side.jpg'], $angles->pluck('filename')->all());

        foreach ($angles as $angle) {
            $this->assertSame($photo->id, $angle->source_item_id, 'drawn from the photo that was picked');
            $this->assertFalse((bool) $angle->selected, 'a drawing of a product is opted into, never out of');
        }

        Queue::assertPushed(GenerateAngleImageJob::class, 2);
        Queue::assertPushed(GenerateAngleImageJob::class, fn ($job) => $job->angle === 'side');
        Queue::assertPushed(GenerateAngleImageJob::class, fn ($job) => $job->angle === 'back');
    }

    /** Nothing ticked, nothing generated and nothing spent. */
    public function test_no_angles_means_no_extra_work(): void
    {
        Queue::fake();
        Http::fake(['*/v2/account' => Http::response('', 503)]);

        [$session] = $this->startRun([]);

        $this->assertSame(0, PhotoEditItem::where('photo_edit_session_id', $session->id)
            ->where('kind', 'angle')->count());

        Queue::assertNotPushed(GenerateAngleImageJob::class);
    }

    /**
     * Every prompt insists it is the same product.
     *
     * That sentence is the only thing standing between a new angle and a new
     * product: the pass regenerates the whole picture rather than rotating it.
     */
    public function test_every_prompt_holds_the_product_still(): void
    {
        foreach (PhotoEditGroup::ANGLES as $angle => $prompt) {
            $this->assertStringContainsString('this exact product', $prompt, "the {$angle} prompt lets it drift");
            $this->assertStringContainsString('same markings', $prompt);

            /*
             * And says how many products there should be.
             *
             * The first wording left the count to the model. On a supplier
             * photograph holding three views of one shoe — the side, the top
             * and the sole in one frame — it regenerated all three: the side
             * view came back with the sole still floating beside it, and "from
             * behind" was read as a pair and drew two shoes. Edit With AI
             * regenerates the whole picture rather than rotating a product in
             * it, so a frame with three objects produces three.
             */
            $this->assertStringContainsString('Exactly one product in the frame', $prompt,
                "the {$angle} prompt does not say how many products there should be");
            $this->assertStringContainsString('no pair', $prompt);
        }
    }

    /** An unknown view is dropped rather than sent as a prompt of its own. */
    public function test_a_view_we_have_no_wording_for_is_ignored(): void
    {
        Queue::fake();
        Http::fake(['*/v2/account' => Http::response('', 503)]);

        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $session = PhotoEditSession::create([
            'user_id' => $user->id, 'name' => 'Run', 'onedrive_link' => 'https://example.com',
            'edits' => [], 'status' => 'configuring', 'scan_status' => 'scanned',
        ]);
        $group = PhotoEditGroup::create([
            'photo_edit_session_id' => $session->id, 'sku' => 'S', 'edits' => null,
        ]);

        $this->actingAs($user)->post(route('photo-editor.start', $session), [
            'groups' => [$group->id => ['lifestyle_count' => 0, 'angles' => ['underneath']]],
        ])->assertSessionHasErrors('groups.' . $group->id . '.angles.0');

        Queue::assertNotPushed(GenerateAngleImageJob::class);
    }

    /*
     * Ticking an angle without picking a source still generates it.
     *
     * The source is chosen by clicking a thumbnail, which is obvious with four
     * photos and invisible with one. The first version skipped the whole SKU
     * without a word when nobody clicked — an instruction cancelled by a
     * refinement nobody made.
     */
    public function test_an_angle_without_a_chosen_source_falls_back_to_the_first_photo(): void
    {
        Queue::fake();
        Http::fake(['*/v2/account' => Http::response('', 503)]);

        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $session = PhotoEditSession::create([
            'user_id' => $user->id, 'name' => 'Run', 'onedrive_link' => 'https://example.com',
            'edits' => [], 'status' => 'configuring', 'scan_status' => 'scanned',
        ]);
        $group = PhotoEditGroup::create([
            'photo_edit_session_id' => $session->id, 'sku' => 'SHOE-1', 'edits' => null,
        ]);

        $first = PhotoEditItem::create([
            'photo_edit_session_id' => $session->id, 'kind' => 'cutout', 'position' => 0,
            'filename' => 'a.jpg', 'sku_detected' => 'SHOE-1', 'status' => 'pending',
            'onedrive_drive_id' => 'd', 'onedrive_item_id' => 'i1',
        ]);

        $this->actingAs($user)->post(route('photo-editor.start', $session), [
            'groups' => [$group->id => ['lifestyle_count' => 0, 'angles' => ['side']]],
        ])->assertRedirect();

        $angle = PhotoEditItem::where('kind', 'angle')->sole();

        $this->assertSame($first->id, $angle->source_item_id);
        Queue::assertPushed(GenerateAngleImageJob::class, 1);
    }

    /*
     * The number above the button counts them.
     *
     * An extra angle is two credits — one to draw the view, one to cut the
     * drawing out — and the first version of this screen said "1 credit" with
     * four angles ticked. That figure is the only thing between ticking four
     * boxes and spending eight credits on them.
     */
    public function test_the_credit_line_counts_an_angle_as_two(): void
    {
        Http::fake(['*/v2/account' => Http::response('', 503)]);

        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $session = PhotoEditSession::create([
            'user_id' => $user->id, 'name' => 'Run', 'onedrive_link' => 'https://example.com',
            'edits' => [], 'status' => 'configuring', 'scan_status' => 'scanned',
        ]);

        PhotoEditGroup::create([
            'photo_edit_session_id' => $session->id, 'sku' => 'SHOE-1', 'edits' => null,
            'angles' => ['side', 'back', 'front', 'angle'],
        ]);

        PhotoEditItem::create([
            'photo_edit_session_id' => $session->id, 'kind' => 'cutout',
            'filename' => 'a.jpg', 'sku_detected' => 'SHOE-1', 'status' => 'pending',
            'onedrive_drive_id' => 'd', 'onedrive_item_id' => 'i',
        ]);

        $html = $this->actingAs($user)
            ->get(route('photo-editor.configure', $session))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('angleTotal: 4', $html, 'the angles are not counted');
        $this->assertStringContainsString('this.angleTotal * 2', $html, 'an angle is being counted as one credit');
    }
}
