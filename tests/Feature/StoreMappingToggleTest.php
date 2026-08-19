<?php

namespace Tests\Feature;

use App\Models\ProductRequest;
use App\Models\ProductRequestSku;
use App\Models\Store;
use App\Models\User;
use App\Notifications\ProductRequestStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The "SKUs are mapped in Cegid for this website" tick is the only thing that
 * decides whether a request waits on Supply Chain. Flipping it has to reach the
 * requests already open — a request created while it was off was waved straight
 * past the mapping stage and would otherwise stay there forever.
 */
class StoreMappingToggleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name'                 => 'Ahamed',
            'email'                => 'admin@example.test',
            'password'             => 'password',
            'is_active'            => true,
            'is_super_admin'       => true,
            'perm_product_request' => true,
        ]);
    }

    private function store(bool $cegid): Store
    {
        return Store::create([
            'name'                 => 'Bluesalon Website',
            'shopify_domain'       => 'qatarbluesalon.myshopify.com',
            'is_active'            => true,
            'requires_sku_mapping' => $cegid,
        ]);
    }

    /** A request with $mapped SKUs mapped and $pending still with Supply Chain. */
    private function request(Store $store, User $user, string $status, int $mapped, int $pending): ProductRequest
    {
        $request = ProductRequest::create([
            'reference'          => ProductRequest::nextReference(),
            'user_id'            => $user->id,
            'store_id'           => $store->id,
            'request_type'       => 'new_brand',
            'brand'              => 'ZIMMERLI',
            'category'           => "Men's Fashion",
            'status'             => $status,
            'priority'           => 'medium',
            'validation_status'  => 'completed',
            'total_skus'         => $mapped + $pending,
            'mapped_skus'        => $mapped,
            'pending_skus'       => $pending,
            'not_mapped_skus'    => 0,
        ]);

        foreach (range(1, $mapped + $pending) as $i) {
            ProductRequestSku::create([
                'product_request_id' => $request->id,
                'sku'                => "SKU-{$i}",
                'mapping_status'     => $i <= $mapped ? ProductRequest::MAP_MAPPED : ProductRequest::MAP_PENDING,
                'in_shopify'         => $i <= $mapped,
            ]);
        }

        return $request;
    }

    private function toggle(User $admin, Store $store, bool $cegid): void
    {
        $this->actingAs($admin)->put(route('stores.update', $store), [
            'name'                 => $store->name,
            'shopify_domain'       => $store->shopify_domain,
            'requires_sku_mapping' => $cegid ? '1' : '0',
        ])->assertRedirect();
    }

    public function test_ticking_cegid_pulls_half_mapped_requests_back_to_waiting_for_mapping(): void
    {
        Notification::fake();

        $admin   = $this->admin();
        $store   = $this->store(cegid: false);
        $request = $this->request($store, $admin, ProductRequest::SKU_VERIFIED, mapped: 18, pending: 12);

        $this->toggle($admin, $store, cegid: true);

        $this->assertSame(ProductRequest::WAITING_MAPPING, $request->refresh()->status);
    }

    /** One admin toggle must not become hundreds of status-change emails. */
    public function test_the_bulk_correction_sends_no_notifications(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $store = $this->store(cegid: false);

        foreach (range(1, 3) as $i) {
            $this->request($store, $admin, ProductRequest::SKU_VERIFIED, mapped: 1, pending: 1);
        }

        $this->toggle($admin, $store, cegid: true);

        Notification::assertNothingSent();
    }

    public function test_a_fully_mapped_request_stays_sku_verified(): void
    {
        Notification::fake();

        $admin   = $this->admin();
        $store   = $this->store(cegid: false);
        $request = $this->request($store, $admin, ProductRequest::SKU_VERIFIED, mapped: 30, pending: 0);

        $this->toggle($admin, $store, cegid: true);

        $this->assertSame(ProductRequest::SKU_VERIFIED, $request->refresh()->status);
    }

    /** Untick and there is nothing to wait for — the request is released. */
    public function test_unticking_cegid_releases_requests_waiting_on_mapping(): void
    {
        Notification::fake();

        $admin   = $this->admin();
        $store   = $this->store(cegid: true);
        $request = $this->request($store, $admin, ProductRequest::WAITING_MAPPING, mapped: 18, pending: 12);

        $this->toggle($admin, $store, cegid: false);

        $this->assertSame(ProductRequest::SKU_VERIFIED, $request->refresh()->status);
    }

    /** Once the work has moved past mapping, mapping is no longer the gate. */
    public function test_requests_further_along_are_left_alone(): void
    {
        Notification::fake();

        $admin   = $this->admin();
        $store   = $this->store(cegid: false);
        $request = $this->request($store, $admin, ProductRequest::AI_CONTENT, mapped: 18, pending: 12);

        $this->toggle($admin, $store, cegid: true);

        $this->assertSame(ProductRequest::AI_CONTENT, $request->refresh()->status);
    }

    /**
     * The team can carry on with the mapped half and hold the balance — that
     * lands them on SKU Verified with SKUs still pending, which must survive the
     * tick being turned on afterwards.
     */
    public function test_a_request_that_already_went_to_supply_chain_is_not_pulled_back(): void
    {
        Notification::fake();

        $admin   = $this->admin();
        $store   = $this->store(cegid: false);
        $request = $this->request($store, $admin, ProductRequest::SKU_VERIFIED, mapped: 18, pending: 12);

        // The trail Supply Chain leaves behind: this one was parked with them.
        $request->activities()->create([
            'user_id'     => $admin->id,
            'action'      => 'status_changed',
            'description' => 'Status changed from Submitted to Waiting for Mapping',
            'from_status' => ProductRequest::SUBMITTED,
            'to_status'   => ProductRequest::WAITING_MAPPING,
        ]);

        $this->toggle($admin, $store, cegid: true);

        $this->assertSame(ProductRequest::SKU_VERIFIED, $request->refresh()->status);
    }

    /**
     * The command exists for requests whose website was corrected after they
     * were created, where there is no tick change left to trigger the fix.
     */
    public function test_the_reconcile_command_fixes_requests_with_no_tick_change_to_trigger_it(): void
    {
        Notification::fake();

        $admin   = $this->admin();
        $store   = $this->store(cegid: true);   // already ticked — nothing to flip
        $request = $this->request($store, $admin, ProductRequest::SKU_VERIFIED, mapped: 18, pending: 12);

        $this->artisan('product-requests:reconcile-mapping')->assertSuccessful();
        $this->assertSame(ProductRequest::SKU_VERIFIED, $request->refresh()->status, 'A dry run must change nothing.');

        $this->artisan('product-requests:reconcile-mapping', ['--commit' => true])->assertSuccessful();
        $this->assertSame(ProductRequest::WAITING_MAPPING, $request->refresh()->status);

        Notification::assertNothingSent();
    }

    /** Saving the store without touching the tick changes no request at all. */
    public function test_an_unrelated_store_edit_moves_nothing(): void
    {
        Notification::fake();

        $admin   = $this->admin();
        $store   = $this->store(cegid: false);
        $request = $this->request($store, $admin, ProductRequest::SKU_VERIFIED, mapped: 18, pending: 12);

        $this->actingAs($admin)->put(route('stores.update', $store), [
            'name'                 => 'Blue Salon QA',
            'shopify_domain'       => $store->shopify_domain,
            'requires_sku_mapping' => '0',
        ])->assertRedirect();

        $this->assertSame(ProductRequest::SKU_VERIFIED, $request->refresh()->status);
    }
}
