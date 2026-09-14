<?php

namespace Tests\Feature;

use App\Models\PhotoEditItem;
use App\Models\PhotoEditSession;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sending a run to a different storefront than the one it was fetched under.
 *
 * A run holds its own store from the day it was created, and the push reads it
 * from there — not from the picker in the header, which only decides what the
 * next new run starts against. The two look like the same control and are not,
 * so a folder fetched with Bluesalon selected went to Bluesalon however the
 * header was set afterwards, and the only way out was to fetch it all again.
 *
 * The line that matters is where this stops. A Shopify image id belongs to the
 * store that created it, so a run with pictures already out there cannot be
 * re-pointed: this side would go on believing it can replace and reorder images
 * it can no longer reach.
 */
class PhotoEditorChangeStoreTest extends TestCase
{
    use RefreshDatabase;

    private function store(string $name): Store
    {
        return Store::create([
            'name'                 => $name,
            'shopify_domain'       => strtolower($name) . '.myshopify.com',
            'shopify_access_token' => 'shpat_' . $name,
        ]);
    }

    private function makeSession(User $user, Store $store): PhotoEditSession
    {
        return PhotoEditSession::create([
            'user_id'       => $user->id,
            'store_id'      => $store->id,
            'name'          => 'Run',
            'onedrive_link' => 'https://example.com',
            'edits'         => [],
            'status'        => 'reviewing',
        ]);
    }

    private function user(): User
    {
        return User::factory()->create([
            'is_active'         => true,
            'perm_photo_editor' => true,
            'is_super_admin'    => true,
        ]);
    }

    public function test_a_run_with_nothing_sent_can_be_pointed_at_another_store(): void
    {
        $user      = $this->user();
        $bluesalon = $this->store('Bluesalon');
        $mosafer   = $this->store('Mosafer');
        $session   = $this->makeSession($user, $bluesalon);

        $item = PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'filename'              => 'a.jpg',
            'sku_detected'          => 'WNG207LUG00019',
            'status'                => 'edited',
            // Matched against the store it is leaving.
            'product_id'            => '900',
            'product_title'         => 'Wenger, on Bluesalon',
            'variant_id'            => '11',
        ]);

        $this->actingAs($user)
            ->post(route('photo-editor.change-store', $session), ['store_id' => $mosafer->id])
            ->assertRedirect();

        $this->assertSame($mosafer->id, $session->fresh()->store_id);

        /*
         * The old match has to go with it. The same SKU is a different product,
         * or no product at all, on the new store — left in place it would look
         * convincing right up until the push landed somewhere unexpected.
         */
        $item->refresh();

        $this->assertNull($item->product_id, 'the run still carries the old store\'s product match');
        $this->assertNull($item->variant_id);
        $this->assertNull($item->product_title);

        // The editing is the expensive part and none of it is thrown away.
        $this->assertSame('edited', $item->status);
    }

    /** The line. Images already on a store belong to it. */
    public function test_a_run_that_has_pushed_is_left_where_it_is(): void
    {
        $user      = $this->user();
        $bluesalon = $this->store('Bluesalon');
        $mosafer   = $this->store('Mosafer');
        $session   = $this->makeSession($user, $bluesalon);

        PhotoEditItem::create([
            'photo_edit_session_id' => $session->id,
            'filename'              => 'a.jpg',
            'sku_detected'          => 'S',
            'status'                => 'pushed',
            'shopify_image_id'      => 'img-1',
        ]);

        $this->actingAs($user)
            ->post(route('photo-editor.change-store', $session), ['store_id' => $mosafer->id])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame($bluesalon->id, $session->fresh()->store_id, 'a run with images out there was re-pointed');
    }

    /** A store id typed into the form is not a store the sender may use. */
    public function test_a_store_the_user_cannot_reach_is_refused(): void
    {
        $owner     = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $bluesalon = $this->store('Bluesalon');
        $other     = $this->store('SomebodyElse');
        $session   = $this->makeSession($owner, $bluesalon);

        // Not a super admin and attached to no store, so nothing is reachable.
        $this->actingAs($owner)
            ->post(route('photo-editor.change-store', $session), ['store_id' => $other->id])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame($bluesalon->id, $session->fresh()->store_id);
    }

    /** Somebody else's run is not theirs to redirect. */
    public function test_another_users_run_is_refused(): void
    {
        $owner   = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $store   = $this->store('Bluesalon');
        $session = $this->makeSession($owner, $store);

        $intruder = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);

        $this->actingAs($intruder)
            ->post(route('photo-editor.change-store', $session), ['store_id' => $store->id])
            ->assertForbidden();
    }
}
