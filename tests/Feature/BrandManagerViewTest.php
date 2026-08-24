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
 * A brand manager's two screens are narrowed to their job.
 *
 * They hand over the SKUs and the pictures; running the pipeline belongs to
 * somebody else. So "everything I hold a role on" is their whole category —
 * hundreds of rows, none of them a task — and stage counters like Photoshoot
 * Scheduled describe work they can neither do nor hurry.
 *
 * Assigned to Me becomes what is actually waiting on them. The dashboard becomes
 * their brands, and whether each is live.
 */
class BrandManagerViewTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->store = Store::create([
            'name' => 'Bluesalon Website', 'shopify_domain' => 'qatarbluesalon.myshopify.com',
            'is_active' => true, 'requires_sku_mapping' => true,
        ]);
    }

    private function manager(): User
    {
        $user = User::create([
            'name' => 'Brand Manager', 'email' => 'brand@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true,
            'pcr_role' => 'brand_manager', 'pcr_brand_categories' => ['Lingerie'],
        ]);

        $user->stores()->attach($this->store->id);

        return $user;
    }

    private function request(string $brand, string $status, array $attributes = []): ProductRequest
    {
        return ProductRequest::create(array_merge([
            'reference'    => ProductRequest::nextReference(),
            'user_id'      => User::first()->id,
            'store_id'     => $this->store->id,
            'request_type' => 'new_brand',
            'brand'        => $brand,
            'category'     => 'Lingerie',
            'status'       => $status,
            'priority'     => 'medium',
            'total_skus'   => 10,
            'mapped_skus'  => 10,
        ], $attributes));
    }

    // ── Assigned to Me: only what is asked of them ───────────────────────────

    public function test_only_requests_waiting_on_them_are_listed(): void
    {
        $manager = $this->manager();

        $toMap    = $this->request('HANRO', ProductRequest::WAITING_MAPPING, ['mapped_skus' => 0, 'pending_skus' => 10]);
        $notTheirs = $this->request('CHANTELLE', ProductRequest::SKU_VERIFIED);

        $page = $this->actingAs($manager)->get(route('product-requests.my-tasks'))->assertOk();

        $page->assertSee('HANRO');
        $page->assertSee('Map 10 SKU(s) in Cegid');

        // Mapped and moving through the pipeline: nothing for them to do on it.
        $page->assertDontSee('CHANTELLE');
    }

    /** Carried on with the mapped half — the balance is still theirs. */
    public function test_an_outstanding_balance_still_counts_as_theirs(): void
    {
        $manager = $this->manager();

        $this->request('AUBADE', ProductRequest::SKU_VERIFIED, [
            'total_skus' => 20, 'mapped_skus' => 12, 'pending_skus' => 8,
        ]);

        $this->actingAs($manager)->get(route('product-requests.my-tasks'))
            ->assertOk()
            ->assertSee('AUBADE')
            ->assertSee('Map the remaining 8 SKU(s) in Cegid');
    }

    public function test_images_asked_for_are_listed(): void
    {
        $manager = $this->manager();

        $this->request('LA PERLA', ProductRequest::WAITING_IMAGES, [
            'photoshoot_decision'    => 'no',
            'image_request_decision' => 'yes',
        ]);

        $this->actingAs($manager)->get(route('product-requests.my-tasks'))
            ->assertOk()
            ->assertSee('LA PERLA')
            ->assertSee('Send the product images');
    }

    /** Nobody asked them for the images, so it is not their task. */
    public function test_images_they_were_not_asked_for_are_not_listed(): void
    {
        $manager = $this->manager();

        $this->request('BORDELLE', ProductRequest::WAITING_IMAGES, [
            'photoshoot_decision'    => 'yes',
            'image_request_decision' => null,
        ]);

        $this->actingAs($manager)->get(route('product-requests.my-tasks'))
            ->assertOk()
            ->assertDontSee('BORDELLE');
    }

    public function test_a_published_request_is_off_their_list(): void
    {
        $manager = $this->manager();

        $this->request('SELMARK', ProductRequest::PUBLISHED, ['mapped_skus' => 0, 'pending_skus' => 10]);

        $this->actingAs($manager)->get(route('product-requests.my-tasks'))
            ->assertOk()
            ->assertDontSee('SELMARK');
    }

    /** A request nobody can act on is not a task. */
    public function test_a_held_request_is_not_a_task(): void
    {
        $manager = $this->manager();

        $this->request('LISCA', ProductRequest::WAITING_MAPPING, [
            'mapped_skus' => 0, 'pending_skus' => 10, 'on_hold' => true, 'hold_reason' => 'Buyer away',
        ]);

        $this->actingAs($manager)->get(route('product-requests.my-tasks'))
            ->assertOk()
            ->assertDontSee('LISCA');
    }

    // ── The dashboard: are my brands live? ───────────────────────────────────

    public function test_the_dashboard_is_their_brands_and_whether_they_are_live(): void
    {
        $manager = $this->manager();

        $this->request('LA PERLA', ProductRequest::PUBLISHED);
        $this->request('CHANTELLE', ProductRequest::SKU_VERIFIED);

        $page = $this->actingAs($manager)->get(route('product-requests.index'))->assertOk();

        $page->assertSee('My Brands');
        $page->assertSee('LA PERLA');
        $page->assertSee('CHANTELLE');
        $page->assertSee('Live on the website');
        $page->assertSee('Waiting on you');

        // The stage counters are somebody else's work, so they are not shown.
        $page->assertDontSee('Waiting for Photoshoot');
        $page->assertDontSee('Total Requests');
    }

    /** Published with the shoot still open is not live, and does not claim to be. */
    public function test_a_request_waiting_on_photos_is_not_counted_as_live(): void
    {
        $manager = $this->manager();

        $this->request('YAMAMAY', ProductRequest::PUBLISHED, [
            'photoshoot_decision' => 'yes',
            'photoshoot_status'   => ProductRequest::SHOOT_PENDING,
        ]);

        $this->actingAs($manager)->get(route('product-requests.index'))
            ->assertOk()
            ->assertSee('Waiting on photos');
    }

    /** Everything in their categories, whoever raised it — the sheet raised most. */
    public function test_requests_they_did_not_raise_are_still_theirs_to_follow(): void
    {
        $manager = $this->manager();

        $this->request('TWIN-SET', ProductRequest::SKU_VERIFIED);

        $this->actingAs($manager)->get(route('product-requests.index'))
            ->assertOk()
            ->assertSee('TWIN-SET');
    }

    /** Another category is not their business. */
    public function test_another_categorys_requests_are_not_shown(): void
    {
        $manager = $this->manager();

        $this->request('BUGATTI', ProductRequest::SKU_VERIFIED, ['category' => "Men's Fashion"]);

        $this->actingAs($manager)->get(route('product-requests.index'))
            ->assertOk()
            ->assertDontSee('BUGATTI');
    }

    // ── Everyone else is unaffected ──────────────────────────────────────────

    public function test_the_ordinary_dashboard_is_unchanged_for_everyone_else(): void
    {
        $owner = User::create([
            'name' => 'Ghassen', 'email' => 'ecom@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true, 'pcr_role' => 'ecommerce',
        ]);
        $owner->stores()->attach($this->store->id);

        $this->actingAs($owner)->get(route('product-requests.index'))
            ->assertOk()
            ->assertSee('Total Requests')
            ->assertDontSee('My Brands');
    }

    /** A brand manager who is also a super admin still needs the whole picture. */
    public function test_a_super_admin_keeps_the_full_dashboard(): void
    {
        $admin = User::create([
            'name' => 'Root', 'email' => 'root@example.test', 'password' => 'password',
            'is_active' => true, 'is_super_admin' => true, 'perm_product_request' => true,
            'pcr_role' => 'brand_manager', 'pcr_brand_categories' => ['Lingerie'],
        ]);
        $admin->stores()->attach($this->store->id);

        $this->actingAs($admin)->get(route('product-requests.index'))
            ->assertOk()
            ->assertSee('Total Requests');
    }
}
