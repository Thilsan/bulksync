<?php

namespace Tests\Feature;

use App\Models\ProductRequest;
use App\Models\Store;
use App\Models\User;
use App\Notifications\ProductRequestStatusChanged;
use App\Services\ProductRequestWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Following a category on one website only, for the brand side.
 *
 * "Brand Manager / Brand Coordinator" could only name a category, so whoever was
 * given Leather Goods heard about it on every website we run — Samsonite requests
 * landing on somebody who only handles Blue Salon.
 */
class BrandManagerStoreScopeTest extends TestCase
{
    use RefreshDatabase;

    private Store $blueSalon;
    private Store $samsonite;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->blueSalon = Store::create([
            'name' => 'Bluesalon Website', 'shopify_domain' => 'bluesalon.myshopify.com', 'is_active' => true,
        ]);
        $this->samsonite = Store::create([
            'name' => 'Samsonite Website', 'shopify_domain' => 'samsonite.myshopify.com', 'is_active' => true,
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

    private function request(Store $store, string $category, string $brand = 'POURCHET', array $attributes = []): ProductRequest
    {
        return ProductRequest::create(array_merge([
            'reference'    => ProductRequest::nextReference(),
            'user_id'      => User::first()?->id ?? $this->user('Raiser')->id,
            'store_id'     => $store->id,
            'request_type' => 'new_brand',
            'brand'        => $brand,
            'category'     => $category,
            'status'       => ProductRequest::SKU_VERIFIED,
            'priority'     => 'medium',
            'total_skus'   => 5,
        ], $attributes));
    }

    private function key(Store $store, string $category): string
    {
        return User::storeCategoryKey($store->id, $category);
    }

    // ── The website wins over the plain category ─────────────────────────────

    public function test_a_pairing_is_followed_on_its_own_website_only(): void
    {
        $onBlueSalon = $this->user('Blue Salon Leather', [
            'pcr_role'                    => 'brand_manager',
            'pcr_brand_store_categories'  => [$this->key($this->blueSalon, 'Leather Goods')],
        ]);

        $this->assertSame(
            [$onBlueSalon->id],
            User::brandManagersForCategory('Leather Goods', 'POURCHET', $this->blueSalon->id)->pluck('id')->all(),
        );

        // The same category on the other website is not theirs.
        $this->assertTrue(
            User::brandManagersForCategory('Leather Goods', 'POURCHET', $this->samsonite->id)->isEmpty(),
        );
    }

    public function test_the_pairing_beats_the_plain_category_and_its_people_are_not_copied(): void
    {
        $everywhere  = $this->user('Leather Goods Everywhere', [
            'pcr_role' => 'brand_manager', 'pcr_brand_categories' => ['Leather Goods'],
        ]);
        $onSamsonite = $this->user('Samsonite Leather', [
            'pcr_role'                   => 'brand_manager',
            'pcr_brand_store_categories' => [$this->key($this->samsonite, 'Leather Goods')],
        ]);

        // On the named website the pairing is the whole answer.
        $this->assertSame(
            [$onSamsonite->id],
            User::brandManagersForCategory('Leather Goods', 'POURCHET', $this->samsonite->id)->pluck('id')->all(),
        );

        // Everywhere else the category list still covers it.
        $this->assertSame(
            [$everywhere->id],
            User::brandManagersForCategory('Leather Goods', 'POURCHET', $this->blueSalon->id)->pluck('id')->all(),
        );
    }

    public function test_a_named_brand_still_beats_the_website_pairing(): void
    {
        $onSamsonite = $this->user('Samsonite Leather', [
            'pcr_role'                   => 'brand_manager',
            'pcr_brand_store_categories' => [$this->key($this->samsonite, 'Leather Goods')],
        ]);
        $forBrand = $this->user('Cole Haan Manager', [
            'pcr_role' => 'brand_manager', 'pcr_managed_brands' => ['COLE HAAN'],
        ]);

        $this->assertSame(
            $forBrand->id,
            User::brandManagerForCategory('Leather Goods', 'Cole Haan', $this->samsonite->id)?->id,
        );
        $this->assertSame(
            $onSamsonite->id,
            User::brandManagerForCategory('Leather Goods', 'POURCHET', $this->samsonite->id)?->id,
        );
    }

    // ── The stage fan-out no longer reaches every brand manager ──────────────

    public function test_a_brand_manager_is_not_mailed_about_another_websites_category(): void
    {
        $raiser = $this->user('Raiser', ['pcr_role' => 'ecommerce', 'pcr_categories' => ['Luggage']]);

        $onBlueSalon = $this->user('Blue Salon Leather', [
            'pcr_role'                   => 'brand_manager',
            'pcr_brand_store_categories' => [$this->key($this->blueSalon, 'Leather Goods')],
        ]);

        // Completed notifies the brand_manager role — which used to mean everyone
        // holding it, this person included.
        $request = $this->request($this->samsonite, 'Luggage', 'SAMSONITE', ['user_id' => $raiser->id]);

        Notification::fake();
        app(ProductRequestWorkflow::class)->transition($request, ProductRequest::COMPLETED, $raiser, force: true);

        Notification::assertNotSentTo($onBlueSalon, ProductRequestStatusChanged::class);
        Notification::assertSentTo($raiser, ProductRequestStatusChanged::class);
    }

    public function test_a_brand_manager_is_still_mailed_about_their_own_pairing(): void
    {
        $raiser = $this->user('Raiser', ['pcr_role' => 'ecommerce', 'pcr_categories' => ['Leather Goods']]);

        $onBlueSalon = $this->user('Blue Salon Leather', [
            'pcr_role'                   => 'brand_manager',
            'pcr_brand_store_categories' => [$this->key($this->blueSalon, 'Leather Goods')],
        ]);

        $request = $this->request($this->blueSalon, 'Leather Goods', 'POURCHET', ['user_id' => $raiser->id]);

        Notification::fake();
        app(ProductRequestWorkflow::class)->transition($request, ProductRequest::COMPLETED, $raiser, force: true);

        Notification::assertSentTo($onBlueSalon, ProductRequestStatusChanged::class);
    }

    public function test_a_brand_manager_holding_the_slot_is_mailed_whatever_the_category(): void
    {
        $raiser = $this->user('Raiser', ['pcr_role' => 'ecommerce', 'pcr_categories' => ['Luggage']]);

        $byHand = $this->user('Assigned By Hand', [
            'pcr_role'                   => 'brand_manager',
            'pcr_brand_store_categories' => [$this->key($this->blueSalon, 'Leather Goods')],
        ]);

        $request  = $this->request($this->samsonite, 'Luggage', 'SAMSONITE', ['user_id' => $raiser->id]);
        $workflow = app(ProductRequestWorkflow::class);

        $workflow->assignRole($request, 'brand_manager_id', $byHand->id, $raiser, notify: false, auto: false);

        Notification::fake();
        $workflow->transition($request->fresh(), ProductRequest::COMPLETED, $raiser, force: true);

        // Reach is narrowed, but holding the work still counts.
        Notification::assertSentTo($byHand, ProductRequestStatusChanged::class);
    }

    public function test_an_oversight_account_still_hears_everything(): void
    {
        $raiser = $this->user('Raiser', ['pcr_role' => 'ecommerce', 'pcr_categories' => ['Luggage']]);

        $inbox = $this->user('Shared Inbox', [
            'pcr_role' => 'brand_manager', 'pcr_notify_all' => true,
        ]);

        $request = $this->request($this->samsonite, 'Luggage', 'SAMSONITE', ['user_id' => $raiser->id]);

        Notification::fake();
        app(ProductRequestWorkflow::class)->transition($request, ProductRequest::COMPLETED, $raiser, force: true);

        Notification::assertSentTo($inbox, ProductRequestStatusChanged::class);
    }

    // ── They can open and see what they are mailed about ─────────────────────

    public function test_a_pairing_grants_the_same_reach_the_emails_assume(): void
    {
        $raiser = $this->user('Raiser', ['pcr_categories' => ['Leather Goods']]);

        $onBlueSalon = $this->user('Blue Salon Leather', [
            'pcr_brand_store_categories' => [$this->key($this->blueSalon, 'Leather Goods')],
        ]);

        $mine   = $this->request($this->blueSalon, 'Leather Goods', 'POURCHET', ['user_id' => $raiser->id]);
        $theirs = $this->request($this->samsonite, 'Leather Goods', 'POURCHET', ['user_id' => $raiser->id]);

        $visible = ProductRequest::visibleTo($onBlueSalon)->pluck('id');

        $this->assertTrue($visible->contains($mine->id));
        $this->assertFalse($visible->contains($theirs->id));

        $desk = ProductRequest::onMyDesk($onBlueSalon)->pluck('id');

        $this->assertTrue($desk->contains($mine->id));
        $this->assertFalse($desk->contains($theirs->id));
    }

    public function test_someone_with_no_pairings_is_not_given_everything(): void
    {
        $raiser  = $this->user('Raiser', ['pcr_categories' => ['Leather Goods']]);
        $nobody  = $this->user('No Settings');
        $request = $this->request($this->blueSalon, 'Leather Goods', 'POURCHET', ['user_id' => $raiser->id]);

        // The OR-widening scope must not turn into "match everything" for the
        // people who have no pairings at all.
        $this->assertFalse(
            ProductRequest::visibleTo($nobody)->pluck('id')->contains($request->id),
        );
    }
}
