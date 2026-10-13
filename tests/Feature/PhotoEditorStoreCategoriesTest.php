<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Services\PhotoroomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A storefront is offered its own framing categories and nobody else's.
 *
 * Every entry in FRAMING_PRESETS was measured off bluesalon.com until
 * Samsonite arrived with its own canvas and its own base line. Offering both
 * brands' categories at once is how a Samsonite case gets framed to Blue
 * Salon's 2000 square: "Luggage" is a plausible thing to click on either site,
 * and nothing on the screen would say which one you were framing for.
 */
class PhotoEditorStoreCategoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_samsonite_sees_only_its_own_category(): void
    {
        $this->assertSame(
            ['samsonite'],
            array_keys(PhotoroomService::framingPresetsForStore('Samsonite Website')),
        );
    }

    /** Matched on the domain as well, since a store can be named anything. */
    public function test_the_shopify_domain_identifies_it_too(): void
    {
        $this->assertSame(
            ['samsonite'],
            array_keys(PhotoroomService::framingPresetsForStore('QA Store samsonite-qa.myshopify.com')),
        );
    }

    /**
     * Blue Salon keeps everything that is not another store's.
     *
     * Named by exclusion rather than by a list of its own: its categories are
     * the default, and a single-store install with no matching name has to
     * keep working without being added here.
     */
    public function test_every_other_store_keeps_the_rest_and_not_samsonite(): void
    {
        foreach (['Bluesalon', 'Diamantaire', '', null] as $identity) {
            $keys = array_keys(PhotoroomService::framingPresetsForStore($identity));

            $this->assertContains('women', $keys);
            $this->assertContains('luggage', $keys, 'Blue Salon has its own luggage, measured on its own canvas');
            $this->assertNotContains('samsonite', $keys, "'{$identity}' was offered another brand's canvas");
        }
    }

    /** And the screen shows what the service says, not the whole table. */
    public function test_the_form_offers_only_the_active_stores_categories(): void
    {
        config(['services.photoroom.api_key' => 'live_sk_test']);
        Http::fake(['*/v2/account' => Http::response('', 503)]);

        $user  = User::factory()->create(['is_active' => true, 'perm_photo_editor' => true]);
        $store = Store::create([
            'name' => 'Samsonite Website', 'shopify_domain' => 'samsonite-qa.myshopify.com',
            'user_id' => $user->id,
        ]);

        // Access is re-checked rather than trusted, so the pivot has to exist.
        $store->users()->attach($user->id);
        $user->forceFill(['active_store_id' => $store->id])->save();

        $html = $this->actingAs($user)->get(route('photo-editor.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Samsonite', $html);
        $this->assertStringNotContainsString('Watches &amp; Jewellery', $html,
            "Blue Salon's categories are on Samsonite's screen");
    }
}
