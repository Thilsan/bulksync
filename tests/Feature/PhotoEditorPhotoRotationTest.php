<?php

namespace Tests\Feature;

use App\Models\PhotoEditGroup;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use App\Services\ImageProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * One photograph turned on its own, after the folder has been fetched.
 *
 * The first screen already asks "are the photos lying on their side?" and
 * applies one answer to the whole run, with a tickbox to spare the shots that
 * are already upright. That is the right question for a folder shot in one go
 * and the wrong one for two views of the same SKU that came off the camera
 * differently — there was no way to say that at all.
 *
 * Null is "nobody touched this photo", which is not the same as "no turn":
 * the first follows the run and the second would override it with none.
 */
class PhotoEditorPhotoRotationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Starting a run dispatches the edit jobs. What is under test is what
        // was written to the rows before that, and a real job here only goes
        // looking for a photograph these rows do not have.
        Queue::fake();
    }

    private function editor(): User
    {
        return User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
    }

    private function newSession(User $user, array $edits = []): PhotoEditSession
    {
        return PhotoEditSession::create([
            'user_id'       => $user->id,
            'name'          => 'Run',
            'onedrive_link' => '',
            'edits'         => $edits,
            'scan_status'   => 'scanned',
            'status'        => 'configuring',
        ]);
    }

    private function item(PhotoEditSession $session, string $sku, string $filename): PhotoEditItem
    {
        return PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'sku_detected'          => $sku,
            'filename'              => $filename,
            'kind'                  => 'cutout',
            'status'                => 'pending',
        ]);
    }

    public function test_one_photo_is_turned_and_its_neighbour_is_left_alone(): void
    {
        $user    = $this->editor();
        $session = $this->newSession($user);
        $group   = PhotoEditGroup::create(['photo_edit_session_id' => $session->id, 'sku' => 'A-1']);

        $sideways = $this->item($session, 'A-1', '0_0.jpg');
        $upright  = $this->item($session, 'A-1', '1_0.jpg');

        $this->actingAs($user)
            ->post(route('photo-editor.start', $session), [
                'groups' => [$group->id => ['rotation' => [$sideways->id => 'right']]],
            ])
            ->assertRedirect();

        $this->assertSame('right', $sideways->refresh()->rotation);
        $this->assertNull($upright->refresh()->rotation, 'an untouched photo still follows the run');
    }

    /** Turning it back to "as it came" has to clear the column, not add to it. */
    public function test_turning_a_photo_back_clears_it(): void
    {
        $user    = $this->editor();
        $session = $this->newSession($user);
        $group   = PhotoEditGroup::create(['photo_edit_session_id' => $session->id, 'sku' => 'A-1']);

        $item = $this->item($session, 'A-1', '0_0.jpg');
        $item->update(['rotation' => '180']);

        $this->actingAs($user)
            ->post(route('photo-editor.start', $session), [
                'groups' => [$group->id => []],
            ])
            ->assertRedirect();

        $this->assertNull($item->refresh()->rotation);
    }

    /**
     * A direction rotate() does not know would be a photo that looks turned
     * on the screen and arrives at Photoroom lying on its side, so it is
     * refused at the door rather than dropped quietly.
     */
    public function test_a_direction_that_does_not_exist_is_rejected(): void
    {
        $user    = $this->editor();
        $session = $this->newSession($user);
        $group   = PhotoEditGroup::create(['photo_edit_session_id' => $session->id, 'sku' => 'A-1']);

        $item = $this->item($session, 'A-1', '0_0.jpg');

        $this->actingAs($user)
            ->post(route('photo-editor.start', $session), [
                'groups' => [$group->id => ['rotation' => [$item->id => 'sideways']]],
            ])
            ->assertSessionHasErrors("groups.{$group->id}.rotation.{$item->id}");
    }

    /**
     * Each SKU keeps its own answer.
     *
     * The clear-then-write is scoped to the SKU, as it is for "as is", "back"
     * and the rest — the screen posts every group on every save, so a group
     * whose entry is absent is a group whose photos were turned back, not one
     * to leave alone.
     */
    public function test_each_sku_keeps_its_own_turn(): void
    {
        $user    = $this->editor();
        $session = $this->newSession($user);

        $a = PhotoEditGroup::create(['photo_edit_session_id' => $session->id, 'sku' => 'A-1']);
        $b = PhotoEditGroup::create(['photo_edit_session_id' => $session->id, 'sku' => 'B-2']);

        $mine   = $this->item($session, 'A-1', '0_0.jpg');
        $theirs = $this->item($session, 'B-2', '0_0.jpg');

        $this->actingAs($user)
            ->post(route('photo-editor.start', $session), [
                'groups' => [
                    $a->id => ['rotation' => [$mine->id => 'right']],
                    $b->id => ['rotation' => [$theirs->id => 'left']],
                ],
            ])
            ->assertRedirect();

        $this->assertSame('right', $mine->refresh()->rotation);
        $this->assertSame('left', $theirs->refresh()->rotation);
    }

    /**
     * A whole SKU turned at once.
     *
     * "Turn all" and the run-wide bar are front-end shortcuts: they write the
     * same per-photo answer the individual buttons do, rather than a setting
     * of their own. So what arrives here is every id carrying the same
     * direction, and one photo put back by hand afterwards survives it.
     */
    public function test_a_whole_sku_can_arrive_turned_together(): void
    {
        $user    = $this->editor();
        $session = $this->newSession($user);
        $group   = PhotoEditGroup::create(['photo_edit_session_id' => $session->id, 'sku' => 'A-1']);

        $one   = $this->item($session, 'A-1', '0_0.jpg');
        $two   = $this->item($session, 'A-1', '1_0.jpg');
        $three = $this->item($session, 'A-1', '2_0.jpg');

        $this->actingAs($user)
            ->post(route('photo-editor.start', $session), [
                'groups' => [$group->id => ['rotation' => [
                    $one->id   => 'right',
                    $two->id   => 'right',

                    // The one that came off the camera the other way up and
                    // was put back by hand after the bulk turn.
                    $three->id => '',
                ]]],
            ])
            ->assertRedirect();

        $this->assertSame('right', $one->refresh()->rotation);
        $this->assertSame('right', $two->refresh()->rotation);
        $this->assertNull($three->refresh()->rotation, 'a photo turned back follows the run again');
    }

    /** The run-wide control is on the screen, above the SKU cards. */
    public function test_the_screen_offers_a_run_wide_turn(): void
    {
        $user    = $this->editor();
        $session = $this->newSession($user);
        PhotoEditGroup::create(['photo_edit_session_id' => $session->id, 'sku' => 'A-1']);
        $this->item($session, 'A-1', '0_0.jpg');

        $this->actingAs($user)
            ->get(route('photo-editor.configure', $session))
            ->assertOk()
            ->assertSee('Turn every photo in the run')
            ->assertSee('photo-turn-all', false)
            ->assertSee('Turn all');
    }

    /** The four the cycle offers are the four the service can actually turn. */
    public function test_the_buttons_cycle_only_through_real_directions(): void
    {
        foreach (['', 'right', '180', 'left'] as $direction) {
            $this->assertArrayHasKey($direction, ImageProcessingService::INPUT_ROTATIONS);
        }
    }
}
