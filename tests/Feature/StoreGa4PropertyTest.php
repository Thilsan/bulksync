<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which GA4 property a website's traffic lives in.
 *
 * Shopify knows what each store sold but nothing about who visited, so
 * sessions and visitors have to come from Google Analytics — and that starts
 * with knowing which property to ask. The property id is kept on the store
 * beside its Shopify credentials rather than in config, because it is
 * per-website and changes as websites are added.
 */
class StoreGa4PropertyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Ada Okonkwo', 'email' => 'ada@example.test',
            'password' => 'password', 'is_active' => true, 'is_super_admin' => true,
        ]);
    }

    private function store(): Store
    {
        return Store::create([
            'name'           => 'AT Website',
            'shopify_domain' => 'amtqatar.myshopify.com',
        ]);
    }

    public function test_a_property_id_is_saved_against_the_store(): void
    {
        $store = $this->store();

        $this->actingAs($this->admin)
            ->put(route('stores.update', $store), [
                'name'             => $store->name,
                'shopify_domain'   => $store->shopify_domain,
                'ga4_property_id'  => '123456789',
            ])
            ->assertRedirect();

        $this->assertSame('123456789', $store->fresh()->ga4_property_id);
    }

    /**
     * The measurement id (G-XXXXXXX) sits next to the property id in the GA4
     * admin and is the one people copy by mistake. Taking it would leave the
     * store looking configured and every report answering nothing, so it is
     * refused at the form instead.
     */
    public function test_a_measurement_id_is_refused_in_place_of_a_property_id(): void
    {
        $store = $this->store();

        $this->actingAs($this->admin)
            ->put(route('stores.update', $store), [
                'name'            => $store->name,
                'shopify_domain'  => $store->shopify_domain,
                'ga4_property_id' => 'G-AB12CD34EF',
            ])
            ->assertSessionHasErrors('ga4_property_id');

        $this->assertNull($store->fresh()->ga4_property_id);
    }

    /**
     * Not the same as an empty box: a request that never carried the field at
     * all. validate() omits it rather than returning null, and reading it
     * blindly turned every such save into a 500.
     */
    public function test_a_form_that_omits_the_field_entirely_still_saves(): void
    {
        $store = $this->store();
        $store->update(['ga4_property_id' => '123456789']);

        $this->actingAs($this->admin)
            ->put(route('stores.update', $store), [
                'name'           => $store->name,
                'shopify_domain' => $store->shopify_domain,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();
    }

    public function test_a_store_without_analytics_can_be_saved_with_no_property_id(): void
    {
        $store = $this->store();

        $this->actingAs($this->admin)
            ->put(route('stores.update', $store), [
                'name'            => $store->name,
                'shopify_domain'  => $store->shopify_domain,
                'ga4_property_id' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($store->fresh()->ga4_property_id);
    }
}
