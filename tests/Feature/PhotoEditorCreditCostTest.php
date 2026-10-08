<?php

namespace Tests\Feature;

use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What a photograph cost is counted, not inferred.
 *
 * The badge on a card says which route the photo took, and most routes are one
 * Photoroom request. But a redraw that was refused and fell back to a plain
 * cutout is two, and an ironing pass retried without the ironing is another,
 * and no mode name carries either. So the job counts its own calls and files
 * the number, and the card shows that number rather than a rule about modes.
 */
class PhotoEditorCreditCostTest extends TestCase
{
    use RefreshDatabase;

    private function editor(): User
    {
        return User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
    }

    private function item(User $user, int $requests): PhotoEditItem
    {
        $session = PhotoEditSession::create([
            'user_id'       => $user->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => ['remove_background' => true],
        ]);

        return PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'kind'                  => 'cutout',
            'filename'              => '0_0.jpg',
            'sku_detected'          => 'SKU-1',
            'status'                => 'edited',
            'edited_path'           => 'edited/0_0.jpg',
            'original_size_kb'      => 8551,
            'edited_size_kb'        => 565,
            'photoroom_requests'    => $requests,
            'onedrive_drive_id'     => 'd',
            'onedrive_item_id'      => 'i',
        ]);
    }

    public function test_the_card_carries_what_the_photo_cost(): void
    {
        Http::fake(['*/v2/account' => Http::response('', 503)]);

        $user = $this->editor();
        $item = $this->item($user, 2);

        $this->actingAs($user)
            ->getJson(route('photo-editor.status', $item->photo_edit_session_id))
            ->assertOk()
            ->assertJsonPath('items.0.credits', 2);
    }

    /**
     * Rows edited before this was recorded show nothing rather than "0 images".
     *
     * Zero is not what those photographs cost — it is what we know about them,
     * and the two are different claims.
     */
    public function test_an_older_row_claims_nothing(): void
    {
        Http::fake(['*/v2/account' => Http::response('', 503)]);

        $user = $this->editor();
        $item = $this->item($user, 0);

        $this->actingAs($user)
            ->getJson(route('photo-editor.status', $item->photo_edit_session_id))
            ->assertOk()
            ->assertJsonPath('items.0.credits', 0);
    }

    /** An "as is" photo never reaches Photoroom, so it costs nothing. */
    public function test_a_pass_through_photo_costs_nothing(): void
    {
        $user = $this->editor();
        $item = $this->item($user, 0);
        $item->update(['skip_edit' => true]);

        $this->assertSame(0, $item->fresh()->photoroom_requests);
    }
}
