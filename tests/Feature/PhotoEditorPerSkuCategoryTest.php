<?php

namespace Tests\Feature;

use App\Models\PhotoEditGroup;
use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One folder, several categories.
 *
 * A shoot arrives as a single folder holding shirts, trousers and shorts
 * together, so the category is the one setting that has to differ SKU by SKU
 * while everything else follows the run. It was reachable before only by
 * ticking "settings differ", which opts the SKU out of the run's settings
 * entirely — a heavy price for saying that something is a pair of shorts.
 */
class PhotoEditorPerSkuCategoryTest extends TestCase
{
    use RefreshDatabase;

    private function editor(): User
    {
        return User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
    }

    private function newSession(array $edits): PhotoEditSession
    {
        return PhotoEditSession::create([
            'user_id'       => $this->editor()->id,
            'name'          => 'Run',
            'onedrive_link' => '',
            'edits'         => $edits,
            'scan_status'   => 'scanned',
            'status'        => 'configuring',
        ]);
    }

    private function item(PhotoEditSession $session, string $sku): PhotoEditItem
    {
        return PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'sku_detected'          => $sku,
            'filename'              => "{$sku}.jpg",
            'status'                => 'pending',
        ]);
    }

    public function test_a_sku_is_framed_to_its_own_category(): void
    {
        $session = $this->newSession(['framing_preset' => 'men/shirts', 'background' => 'white']);

        PhotoEditGroup::create([
            'photo_edit_session_id' => $session->id,
            'sku'                   => 'A-1',
            'framing_preset'        => 'men/shorts',
        ]);

        $edits = $this->item($session, 'A-1')->resolvedEdits();

        $this->assertSame('men/shorts', $edits['framing_preset']);

        // The whole set of numbers, not just the label. Writing the key alone
        // would leave the SKU framed to the run's category while claiming
        // another, which nothing downstream looks wrong for.
        $this->assertSame(0.80, $edits['height_fill'], 'the shorts preset binds on height');

        // And everything else still follows the run.
        $this->assertSame('white', $edits['background']);
    }

    /** Null means "the run's category", so changing the run still reaches it. */
    public function test_a_sku_without_one_follows_the_run(): void
    {
        $session = $this->newSession(['framing_preset' => 'men/shirts']);

        PhotoEditGroup::create([
            'photo_edit_session_id' => $session->id,
            'sku'                   => 'A-1',
            'framing_preset'        => null,
        ]);

        $this->assertSame('men/shirts', $this->item($session, 'A-1')->resolvedEdits()['framing_preset']);
    }

    /**
     * The category survives a SKU that otherwise follows the run.
     *
     * The group stores no edits at all in that case, which is the common one
     * now that following the run is the default — so a category kept inside
     * the edits would have been dropped on exactly the SKUs this exists for.
     */
    public function test_it_is_saved_without_opting_the_sku_out_of_the_run(): void
    {
        $user    = $this->editor();
        $session = $this->newSession(['framing_preset' => 'men/shirts']);
        $session->update(['user_id' => $user->id]);

        $group = PhotoEditGroup::create([
            'photo_edit_session_id' => $session->id,
            'sku'                   => 'A-1',
        ]);

        $this->actingAs($user)
            ->post(route('photo-editor.start', $session), [
                'groups' => [$group->id => ['framing_preset' => 'men/shorts']],
            ])
            ->assertRedirect();

        $group->refresh();

        $this->assertSame('men/shorts', $group->framing_preset);
        $this->assertNull($group->edits, 'it still follows the run for everything else');
    }

    /** An unknown key is refused rather than silently dropped. */
    public function test_a_category_that_does_not_exist_is_rejected(): void
    {
        $user    = $this->editor();
        $session = $this->newSession([]);
        $session->update(['user_id' => $user->id]);

        $group = PhotoEditGroup::create([
            'photo_edit_session_id' => $session->id,
            'sku'                   => 'A-1',
        ]);

        $this->actingAs($user)
            ->post(route('photo-editor.start', $session), [
                'groups' => [$group->id => ['framing_preset' => 'men/invented']],
            ])
            ->assertSessionHasErrors("groups.{$group->id}.framing_preset");
    }
}
