<?php

namespace Tests\Feature;

use App\Models\PhotoEditGroup;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The suitcase's real height, picked SKU by SKU.
 *
 * Nothing in a photograph says how big a case is: a cabin case and a large one
 * are both shot to fill their own frame, so the pixels are identical and the
 * products are not. Framed to one standard they all come out the same size,
 * which is the complaint this answers — medium and large looking like cabin.
 *
 * The property worth guarding is where the number lives. A luggage run arrives
 * as one folder with all three sizes in it, under one set of run settings, so
 * the SKU that needs a size is almost never the SKU that opted out of the run's
 * settings. Kept among the group's edits the number would be discarded for
 * every SKU following the run — silently, and only visible weeks later in a
 * gallery of identically-sized cases. So there is a test that sets a size on a
 * group which explicitly follows the run, and demands it survive.
 */
class PhotoEditorCaseHeightTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(User $user): PhotoEditSession
    {
        return PhotoEditSession::create([
            'user_id'       => $user->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => ['remove_background' => true, 'framing_preset' => 'bags/luggage'],
            'status'        => 'configuring',
            'scan_status'   => 'scanned',
        ]);
    }

    private function photo(PhotoEditSession $session, string $sku): PhotoEditItem
    {
        return PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'kind'                  => 'cutout',
            'filename'              => "Medium JPG-{$sku}.jpg",
            'sku_detected'          => $sku,
            'status'                => 'pending',
            'onedrive_drive_id'     => 'drive-1',
            'onedrive_item_id'      => 'item-' . $sku,
        ]);
    }

    /**
     * The one that would have been lost. `differs` is absent, so this SKU keeps
     * following the run's settings — and still carries its own size.
     */
    public function test_a_size_survives_on_a_sku_that_follows_the_run(): void
    {
        Queue::fake();

        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $session = $this->makeSession($user);
        $item    = $this->photo($session, 'WNG207LUG00021');
        $group   = PhotoEditGroup::create([
            'photo_edit_session_id' => $session->id,
            'sku'                   => 'WNG207LUG00021',
            'edits'                 => null,
        ]);

        $this->actingAs($user)->post(route('photo-editor.start', $session), [
            'groups' => [$group->id => ['case_height_cm' => '65']],
        ])->assertRedirect(route('photo-editor.show', $session));

        $group->refresh();

        $this->assertSame(65, $group->case_height_cm, 'the size was dropped');
        $this->assertNull($group->edits, 'the SKU was pinned to a copy of the run settings');

        // And it reaches the editing job, which is the only reason to store it.
        $this->assertSame(65, $item->fresh()->resolvedEdits()['case_height_cm'] ?? null);
    }

    /** Three SKUs in one run, three sizes. */
    public function test_each_sku_keeps_its_own_size(): void
    {
        Queue::fake();

        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $session = $this->makeSession($user);

        $wanted = ['LUG-CABIN' => 55, 'LUG-MEDIUM' => 65, 'LUG-LARGE' => 80];
        $post   = [];

        foreach ($wanted as $sku => $cm) {
            $this->photo($session, $sku);

            $group = PhotoEditGroup::create([
                'photo_edit_session_id' => $session->id,
                'sku'                   => $sku,
                'edits'                 => null,
            ]);

            $post[$group->id] = ['case_height_cm' => (string) $cm];
        }

        $this->actingAs($user)->post(route('photo-editor.start', $session), ['groups' => $post]);

        foreach ($wanted as $sku => $cm) {
            $this->assertSame(
                $cm,
                PhotoEditGroup::where('photo_edit_session_id', $session->id)->where('sku', $sku)->first()->case_height_cm,
                "{$sku} did not keep its own size",
            );
        }
    }

    /**
     * Never inferred from the filenames.
     *
     * An earlier version read "Cabin"/"Medium"/"Large" out of the names and
     * pre-selected a size. The names are wrong often enough that this was worse
     * than offering nothing: a wrong size looks like a correct one all the way
     * through the run, and only shows up as a medium case the size of a cabin
     * once the finished images sit side by side. So every photo in this fixture
     * is named "Medium ..." and the page must still open with nothing chosen.
     */
    public function test_the_size_is_chosen_and_never_read_off_the_filenames(): void
    {
        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $session = $this->makeSession($user);
        $this->photo($session, 'WNG207LUG00021');
        $group = PhotoEditGroup::create([
            'photo_edit_session_id' => $session->id,
            'sku'                   => 'WNG207LUG00021',
            'edits'                 => null,
        ]);

        $html = $this->actingAs($user)
            ->get(route('photo-editor.configure', $session))
            ->assertOk()
            ->getContent();

        $select = $this->caseSelect($html, $group->id);

        $this->assertStringNotContainsString(
            'selected',
            $select,
            'a size was pre-selected from the filenames',
        );
    }

    /**
     * A height set before the list existed, or one off the list entirely, stays
     * chosen — otherwise opening the page and saving it would quietly wipe it.
     */
    public function test_a_height_that_is_not_on_the_list_stays_selected(): void
    {
        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $session = $this->makeSession($user);
        $this->photo($session, 'LUG-1');
        $group = PhotoEditGroup::create([
            'photo_edit_session_id' => $session->id,
            'sku'                   => 'LUG-1',
            'edits'                 => null,
            'case_height_cm'        => 43,
        ]);

        $select = $this->caseSelect(
            $this->actingAs($user)->get(route('photo-editor.configure', $session))->assertOk()->getContent(),
            $group->id,
        );

        $this->assertMatchesRegularExpression(
            '/<option value="43"\s+selected>/',
            $select,
            'the stored height was dropped from the list',
        );
    }

    /** Clearing the box goes back to the category's own framing. */
    public function test_clearing_it_removes_the_size(): void
    {
        Queue::fake();

        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $session = $this->makeSession($user);
        $item    = $this->photo($session, 'LUG-1');
        $group   = PhotoEditGroup::create([
            'photo_edit_session_id' => $session->id,
            'sku'                   => 'LUG-1',
            'edits'                 => null,
            'case_height_cm'        => 80,
        ]);

        $this->actingAs($user)->post(route('photo-editor.start', $session), [
            'groups' => [$group->id => ['case_height_cm' => '']],
        ]);

        $this->assertNull($group->refresh()->case_height_cm);
        $this->assertArrayNotHasKey('case_height_cm', $item->fresh()->resolvedEdits());
    }

    /**
     * A typo of 550 for 55 would ask for a case five times the canvas, and
     * frameToStandard would shrink the whole product to fit — a smaller case
     * for a larger number, which reads as the feature being broken.
     */
    public function test_an_impossible_height_is_refused(): void
    {
        Queue::fake();

        $user    = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $session = $this->makeSession($user);
        $this->photo($session, 'LUG-1');
        $group = PhotoEditGroup::create([
            'photo_edit_session_id' => $session->id,
            'sku'                   => 'LUG-1',
            'edits'                 => null,
        ]);

        $this->actingAs($user)->post(route('photo-editor.start', $session), [
            'groups' => [$group->id => ['case_height_cm' => '550']],
        ])->assertSessionHasErrors('groups.' . $group->id . '.case_height_cm');

        $this->assertNull($group->refresh()->case_height_cm);
    }

    /** Just this group's size dropdown, so one SKU's markup cannot answer for another's. */
    private function caseSelect(string $html, int $groupId): string
    {
        $open = strpos($html, "name=\"groups[{$groupId}][case_height_cm]\"");

        $this->assertNotFalse($open, "no case size control was rendered for group {$groupId}");

        $close = strpos($html, '</select>', $open);

        $this->assertNotFalse($close);

        return substr($html, $open, $close - $open);
    }
}
