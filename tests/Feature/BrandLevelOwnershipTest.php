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
 * Ownership at brand level, not just category.
 *
 * A category is usually the right unit — one person handles Lingerie end to end.
 * But Cole Haan sits inside Leather Goods and is somebody else's brand, and the
 * only way to say so used to be handing over the whole category.
 */
class BrandLevelOwnershipTest extends TestCase
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

    private function request(string $brand, string $category = 'Leather Goods'): ProductRequest
    {
        return ProductRequest::create([
            'reference'    => ProductRequest::nextReference(),
            'user_id'      => User::first()?->id ?? $this->user('Raiser')->id,
            'store_id'     => $this->store->id,
            'request_type' => 'new_brand',
            'brand'        => $brand,
            'category'     => $category,
            'status'       => ProductRequest::SKU_VERIFIED,
            'priority'     => 'medium',
            'total_skus'   => 5,
        ]);
    }

    // ── The brand wins over the category ─────────────────────────────────────

    public function test_a_brand_manager_named_for_one_brand_beats_the_category(): void
    {
        $forCategory = $this->user('Leather Goods Manager', [
            'pcr_role' => 'brand_manager', 'pcr_brand_categories' => ['Leather Goods'],
        ]);
        $forBrand = $this->user('Cole Haan Manager', [
            'pcr_role' => 'brand_manager', 'pcr_managed_brands' => ['COLE HAAN'],
        ]);

        $this->assertSame($forBrand->id, User::brandManagerForCategory('Leather Goods', 'Cole Haan')?->id);

        // Every other brand in the category is untouched.
        $this->assertSame($forCategory->id, User::brandManagerForCategory('Leather Goods', 'POURCHET')?->id);
    }

    public function test_the_owner_can_be_named_for_one_brand_too(): void
    {
        $forCategory = $this->user('Ahmad', ['pcr_role' => 'ecommerce', 'pcr_categories' => ['Leather Goods']]);
        $forBrand    = $this->user('Ghassen', ['pcr_role' => 'ecommerce', 'pcr_owned_brands' => ['COLE HAAN']]);

        $this->assertSame($forBrand->id, User::ownerForCategory('Leather Goods', 'Cole Haan')?->id);
        $this->assertSame($forCategory->id, User::ownerForCategory('Leather Goods', 'POURCHET')?->id);
    }

    /**
     * The sheet writes "COLE HAAN ", "Cole Haan" and "cole haan" for one brand,
     * so a setting matched literally would miss most of them.
     */
    public function test_the_brand_is_matched_however_it_is_written(): void
    {
        $named = $this->user('Cole Haan Manager', [
            'pcr_role' => 'brand_manager', 'pcr_managed_brands' => ['COLE HAAN'],
        ]);

        foreach (['COLE HAAN ', ' cole haan', 'Cole Haan'] as $spelling) {
            $this->assertSame(
                $named->id,
                User::brandManagerForCategory('Leather Goods', $spelling)?->id,
                "\"{$spelling}\" should have matched.",
            );
        }
    }

    /** Naming a brand means it is handled apart, so the category's people are not copied. */
    public function test_the_categorys_people_are_not_copied_on_a_named_brand(): void
    {
        $this->user('Leather Goods Manager', [
            'pcr_role' => 'brand_manager', 'pcr_brand_categories' => ['Leather Goods'],
        ]);
        $forBrand = $this->user('Cole Haan Manager', [
            'pcr_role' => 'brand_manager', 'pcr_managed_brands' => ['COLE HAAN'],
        ]);

        $people = User::brandManagersForCategory('Leather Goods', 'COLE HAAN');

        $this->assertCount(1, $people);
        $this->assertSame($forBrand->id, $people->first()->id);
    }

    // ── Staffing follows it ──────────────────────────────────────────────────

    public function test_a_request_is_staffed_from_its_brand_when_one_is_named(): void
    {
        $owner    = $this->user('Ahmad', ['pcr_role' => 'ecommerce', 'pcr_categories' => ['Leather Goods']]);
        $forBrand = $this->user('Cole Haan Manager', [
            'pcr_role' => 'brand_manager', 'pcr_managed_brands' => ['COLE HAAN'],
        ]);
        $this->user('Leather Goods Manager', [
            'pcr_role' => 'brand_manager', 'pcr_brand_categories' => ['Leather Goods'],
        ]);

        $request = $this->request('COLE HAAN ');

        app(ProductRequestWorkflow::class)->staffFromCategory($request, null, notify: false);

        $this->assertSame($forBrand->id, $request->refresh()->ownerFor('brand_manager_id')?->id);
        $this->assertSame($owner->id, $request->ownerFor('assigned_to')?->id);
    }

    // ── It reaches their screens ─────────────────────────────────────────────

    /** A brand named to them shows up even though the category is not theirs. */
    public function test_a_named_brand_appears_on_the_brand_managers_dashboard(): void
    {
        $forBrand = $this->user('Cole Haan Manager', [
            'pcr_role' => 'brand_manager', 'pcr_managed_brands' => ['COLE HAAN'],
        ]);
        $forBrand->stores()->attach($this->store->id);

        $this->request('COLE HAAN ');
        $this->request('POURCHET');

        $page = $this->actingAs($forBrand)->get(route('product-requests.index'))->assertOk();

        $page->assertSee('COLE HAAN');
        $page->assertDontSee('POURCHET');
    }

    // ── The Users screen ────────────────────────────────────────────────────

    public function test_the_picker_saves_brands_uppercased(): void
    {
        $admin = $this->user('Root', ['is_super_admin' => true]);
        $them  = $this->user('Cole Haan Manager', ['pcr_role' => 'brand_manager']);

        $this->request('Cole Haan');

        $this->actingAs($admin)->post(route('super-admin.users.permissions', $them), [
            'perm_product_request' => 1,
            'pcr_role'             => 'brand_manager',
            'pcr_managed_brands'   => ['cole haan '],
        ])->assertRedirect();

        $this->assertSame(['COLE HAAN'], $them->fresh()->pcr_managed_brands);
    }

    /** A brand nobody has a request for is not a brand. */
    public function test_an_unknown_brand_is_dropped(): void
    {
        $admin = $this->user('Root', ['is_super_admin' => true]);
        $them  = $this->user('Somebody', ['pcr_role' => 'brand_manager']);

        $this->actingAs($admin)->post(route('super-admin.users.permissions', $them), [
            'perm_product_request' => 1,
            'pcr_role'             => 'brand_manager',
            'pcr_managed_brands'   => ['NOT A REAL BRAND'],
        ])->assertRedirect();

        $this->assertNull($them->fresh()->pcr_managed_brands);
    }

    /** A brand belongs to one handler, the same rule categories follow. */
    public function test_giving_a_brand_to_someone_takes_it_off_whoever_held_it(): void
    {
        $admin = $this->user('Root', ['is_super_admin' => true]);
        $first = $this->user('First', ['pcr_role' => 'ecommerce', 'pcr_owned_brands' => ['COLE HAAN']]);
        $second = $this->user('Second', ['pcr_role' => 'ecommerce']);

        $this->request('COLE HAAN');

        $this->actingAs($admin)->post(route('super-admin.users.permissions', $second), [
            'perm_product_request' => 1,
            'pcr_role'             => 'ecommerce',
            'pcr_owned_brands'     => ['COLE HAAN'],
        ])->assertRedirect();

        $this->assertNull($first->fresh()->pcr_owned_brands);
        $this->assertSame(['COLE HAAN'], $second->fresh()->pcr_owned_brands);
    }

    public function test_the_brand_pickers_are_on_the_users_screen(): void
    {
        $admin = $this->user('Root', ['is_super_admin' => true]);

        // The panel is only drawn on other people's cards — a super admin has
        // every permission already, so their own card has nothing to set.
        $this->user('Cole Haan Manager', ['pcr_role' => 'brand_manager']);

        $this->request('COLE HAAN');

        $this->actingAs($admin)->get(route('super-admin.index'))
            ->assertOk()
            ->assertSee('Handles these brands only')
            ->assertSee('Brand manager for these brands only')
            ->assertSee('COLE HAAN');
    }

    /**
     * The role decides which settings are on screen: a brand manager has no
     * categories to be assigned work in, and an e-commerce owner is not the brand
     * side. Showing both sets invited people to fill in the wrong one.
     */
    public function test_only_the_settings_the_role_needs_are_drawn(): void
    {
        $admin = $this->user('Root', ['is_super_admin' => true]);
        $this->user('Somebody', ['pcr_role' => 'brand_manager']);
        $this->request('COLE HAAN');

        $page = $this->actingAs($admin)->get(route('super-admin.index'))->assertOk();

        // Rendered inside x-if, so the browser keeps only the half that applies.
        $page->assertSee("x-if=\"role !== 'brand_manager'\"", false);
        $page->assertSee("x-if=\"role === 'brand_manager'\"", false);
    }

    /**
     * x-if removes the fields rather than hiding them, so switching somebody to
     * the brand side clears the owner settings instead of leaving them assigning
     * work with nothing on screen to explain it.
     */
    public function test_switching_to_the_brand_side_clears_the_owner_settings(): void
    {
        $admin = $this->user('Root', ['is_super_admin' => true]);
        $them  = $this->user('Was An Owner', [
            'pcr_role' => 'ecommerce', 'pcr_categories' => ['Leather Goods'], 'pcr_owned_brands' => ['COLE HAAN'],
        ]);

        $this->request('COLE HAAN');

        // What the browser posts once the role is the brand side: no categories.
        $this->actingAs($admin)->post(route('super-admin.users.permissions', $them), [
            'perm_product_request'  => 1,
            'pcr_role'              => 'brand_manager',
            'pcr_brand_categories'  => ['Leather Goods'],
        ])->assertRedirect();

        $them->refresh();

        $this->assertNull($them->pcr_categories);
        $this->assertNull($them->pcr_owned_brands);
        $this->assertSame(['Leather Goods'], $them->pcr_brand_categories);
    }

    /** Nothing changes for anybody until a brand is actually named. */
    public function test_categories_still_decide_when_no_brand_is_named(): void
    {
        $forCategory = $this->user('Leather Goods Manager', [
            'pcr_role' => 'brand_manager', 'pcr_brand_categories' => ['Leather Goods'],
        ]);

        $this->assertSame($forCategory->id, User::brandManagerForCategory('Leather Goods', 'COLE HAAN')?->id);
    }
}
