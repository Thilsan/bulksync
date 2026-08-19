<?php

namespace Tests\Feature;

use App\Models\ProductRequest;
use App\Models\Store;
use App\Models\User;
use App\Services\ProductRequestWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A role with nobody configured falls back to whoever handles the category, so
 * the owner's name ends up in slots that were never theirs — the Brand Manager
 * box reading "Ghassen" when Ghassen is the E-Commerce owner, not the brand
 * manager. Configuring the right person afterwards has to be able to reach it,
 * without disturbing anything a person chose.
 */
class ProductRequestRestaffTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->store = Store::create([
            'name' => 'Bluesalon Website', 'shopify_domain' => 'qatarbluesalon.myshopify.com', 'is_active' => true,
        ]);
    }

    private function user(string $name, array $attributes = []): User
    {
        return User::create(array_merge([
            'name'                 => $name,
            'email'                => str($name)->slug() . '@example.test',
            'password'             => 'password',
            'is_active'            => true,
            'perm_product_request' => true,
        ], $attributes));
    }

    private function request(User $raisedBy): ProductRequest
    {
        return ProductRequest::create([
            'reference'           => ProductRequest::nextReference(),
            'user_id'             => $raisedBy->id,
            'store_id'            => $this->store->id,
            'request_type'        => 'new_brand',
            'brand'               => 'HANRO',
            'category'            => 'Lingerie',
            'status'              => ProductRequest::SKU_VERIFIED,
            'priority'            => 'medium',
            'photoshoot_required' => false,
            'total_skus'          => 5,
        ]);
    }

    /** The exact complaint: the owner's name sitting in the Brand Manager box. */
    public function test_a_brand_manager_configured_later_replaces_the_owner_fallback(): void
    {
        $owner   = $this->user('Ghassen', ['pcr_role' => 'ecommerce', 'pcr_categories' => ['Lingerie']]);
        $request = $this->request($owner);
        $workflow = app(ProductRequestWorkflow::class);

        // Staffed while nobody was the brand manager, so it fell to the owner.
        $workflow->staffFromCategory($request, $owner);
        $this->assertSame($owner->id, $request->ownerFor('brand_manager_id')?->id);

        $manager = $this->user('Brand Manager', [
            'pcr_role' => 'brand_manager', 'pcr_brand_categories' => ['Lingerie'],
        ]);

        $moved = $workflow->restaffFromCategory($request->refresh());

        $this->assertArrayHasKey('Brand Manager', $moved);
        $this->assertSame(['from' => 'Ghassen', 'to' => 'Brand Manager'], $moved['Brand Manager']);
        $this->assertSame($manager->id, $request->refresh()->ownerFor('brand_manager_id')?->id);

        // And the owner keeps the role that really is theirs.
        $this->assertSame($owner->id, $request->ownerFor('assigned_to')?->id);
    }

    /** Somebody's own choice is not the app's to undo. */
    public function test_a_role_assigned_by_hand_is_never_moved(): void
    {
        $owner    = $this->user('Ghassen', ['pcr_role' => 'ecommerce', 'pcr_categories' => ['Lingerie']]);
        $chosen   = $this->user('Chosen By Hand', ['pcr_role' => 'brand_manager']);
        $request  = $this->request($owner);
        $workflow = app(ProductRequestWorkflow::class);

        // Picked deliberately from the Team Assignments panel.
        $workflow->assignRole(request: $request, field: 'brand_manager_id', userId: $chosen->id, actor: $owner);

        $this->user('Configured Later', ['pcr_role' => 'brand_manager', 'pcr_brand_categories' => ['Lingerie']]);

        $this->assertSame([], $workflow->restaffFromCategory($request->refresh()));
        $this->assertSame($chosen->id, $request->ownerFor('brand_manager_id')?->id);
    }

    public function test_nothing_moves_when_the_settings_already_match(): void
    {
        $owner    = $this->user('Ghassen', ['pcr_role' => 'ecommerce', 'pcr_categories' => ['Lingerie']]);
        $this->user('Brand Manager', ['pcr_role' => 'brand_manager', 'pcr_brand_categories' => ['Lingerie']]);
        $request  = $this->request($owner);
        $workflow = app(ProductRequestWorkflow::class);

        $workflow->staffFromCategory($request, $owner);

        $this->assertSame([], $workflow->restaffFromCategory($request->refresh()));
    }

    /** One name has to be the answer everywhere, or the screen lies to you. */
    public function test_the_screen_and_the_staffing_name_the_same_person(): void
    {
        $first  = $this->user('First Manager', ['pcr_role' => 'brand_manager', 'pcr_brand_categories' => ['Lingerie']]);
        $this->user('Second Manager', ['pcr_role' => 'brand_manager', 'pcr_brand_categories' => ['Lingerie']]);

        $this->assertSame($first->id, User::brandManagerForCategory('Lingerie')?->id);
        $this->assertSame($first->id, User::brandManagerMap()['Lingerie']->id);
    }

    public function test_the_button_reports_what_it_changed(): void
    {
        $owner   = $this->user('Ghassen', [
            'pcr_role' => 'ecommerce', 'pcr_categories' => ['Lingerie'], 'is_super_admin' => true,
        ]);
        $owner->stores()->attach($this->store->id);
        $request = $this->request($owner);

        app(ProductRequestWorkflow::class)->staffFromCategory($request, $owner);
        $this->user('Brand Manager', ['pcr_role' => 'brand_manager', 'pcr_brand_categories' => ['Lingerie']]);

        $this->actingAs($owner)
            ->post(route('product-requests.restaff', $request))
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_the_button_says_so_when_there_is_nothing_to_do(): void
    {
        $owner = $this->user('Ghassen', [
            'pcr_role' => 'ecommerce', 'pcr_categories' => ['Lingerie'], 'is_super_admin' => true,
        ]);
        $owner->stores()->attach($this->store->id);
        $request = $this->request($owner);

        app(ProductRequestWorkflow::class)->staffFromCategory($request, $owner);

        $this->actingAs($owner)
            ->post(route('product-requests.restaff', $request))
            ->assertRedirect()
            ->assertSessionHas('warning');
    }
}
