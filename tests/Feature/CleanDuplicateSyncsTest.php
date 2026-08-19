<?php

namespace Tests\Feature;

use App\Models\ProductRequest;
use App\Models\ProductRequestSheetSync;
use App\Models\ProductRequestSku;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two sync runs racing on the same sheet row leave two identical requests and
 * one ledger entry. Cleaning that up deletes real work if it is careless, so the
 * conditions are tested one at a time.
 */
class CleanDuplicateSyncsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Ahamed', 'email' => 'admin@example.test', 'password' => 'password',
            'is_active' => true, 'is_super_admin' => true, 'perm_product_request' => true,
        ]);

        $this->store = Store::create([
            'name' => 'Bluesalon Website', 'shopify_domain' => 'qatarbluesalon.myshopify.com', 'is_active' => true,
        ]);
    }

    private function request(string $brand = 'HANRO', int $skus = 5, string $status = ProductRequest::SKU_VERIFIED): ProductRequest
    {
        return ProductRequest::create([
            'reference'    => ProductRequest::nextReference(),
            'user_id'      => $this->user->id,
            'store_id'     => $this->store->id,
            'request_type' => 'new_brand',
            'brand'        => $brand,
            'category'     => 'Lingerie',
            'status'       => $status,
            'priority'     => 'medium',
            'total_skus'   => $skus,
        ]);
    }

    private function ledger(ProductRequest $request, int $requestNo = 124): void
    {
        ProductRequestSheetSync::create([
            'request_no'         => $requestNo,
            'website_token'      => 'BS',
            'store_id'           => $request->store_id,
            'product_request_id' => $request->id,
            'status'             => 'created',
        ]);
    }

    /** The exact shape the race left behind: identical twins, one ledger row. */
    public function test_the_copy_with_no_ledger_row_is_the_one_removed(): void
    {
        $orphan = $this->request();
        $twin   = $this->request();
        $this->ledger($twin);

        $this->artisan('product-requests:clean-duplicate-syncs', ['--commit' => true])->assertSuccessful();

        $this->assertNull($orphan->fresh(), 'The unlinked copy should be gone.');
        $this->assertNotNull($twin->fresh(), 'The copy tied to the sheet must stay.');
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        $orphan = $this->request();
        $this->ledger($this->request());

        $this->artisan('product-requests:clean-duplicate-syncs')
            ->expectsOutputToContain('Would delete')
            ->assertSuccessful();

        $this->assertNotNull($orphan->fresh());
    }

    /** A request raised by hand has no ledger row either — and no twin. */
    public function test_a_request_with_no_twin_is_never_touched(): void
    {
        $manual = $this->request('RAISED BY HAND');

        $this->artisan('product-requests:clean-duplicate-syncs', ['--commit' => true])->assertSuccessful();

        $this->assertNotNull($manual->fresh());
    }

    /** Same brand, different SKU count — a different sheet row, not a copy. */
    public function test_a_different_request_for_the_same_brand_is_kept(): void
    {
        $older = $this->request('HANRO', skus: 279);
        $this->ledger($this->request('HANRO', skus: 5), requestNo: 124);

        $this->artisan('product-requests:clean-duplicate-syncs', ['--commit' => true])->assertSuccessful();

        $this->assertNotNull($older->fresh(), 'HANRO #28 with 279 SKUs is its own request.');
    }

    /**
     * The SKU check logs its status move against whoever ran the import, so
     * every synced request carries an activity with a user against it seconds
     * after creation. That is the automation, and must not save a duplicate.
     */
    public function test_the_imports_own_history_does_not_count_as_someone_working_on_it(): void
    {
        $orphan = $this->request();
        $twin   = $this->request();
        $this->ledger($twin);

        // The SKU check runs on the queue, so this lands long after the import —
        // hours later when two hundred requests are queued ahead of it.
        $orphan->activities()->create([
            'user_id'     => $this->user->id,          // the person who ran the sync
            'action'      => 'status_changed',
            'from_status' => ProductRequest::SUBMITTED,
            'to_status'   => ProductRequest::SKU_VERIFIED,
            'description' => 'Status changed from Submitted to SKU Verified',
            'created_at'  => $orphan->created_at->copy()->addHours(3),
        ]);

        $this->artisan('product-requests:clean-duplicate-syncs', ['--commit' => true])->assertSuccessful();

        $this->assertNull($orphan->fresh(), 'An automatic status move must not protect a duplicate.');
    }

    /**
     * Turning a website's Cegid tick on pulls half-mapped requests back a stage.
     * That is the app reacting to a setting, not anybody working on this copy.
     */
    public function test_the_cegid_pull_back_does_not_protect_the_copy(): void
    {
        $orphan = $this->request();
        $this->ledger($this->request());

        $orphan->activities()->create([
            'user_id'     => $this->user->id,
            'action'      => 'status_changed',
            'from_status' => ProductRequest::SKU_VERIFIED,
            'to_status'   => ProductRequest::WAITING_MAPPING,
            'description' => 'Status changed from SKU Verified to Waiting for Mapping',
        ]);

        $this->artisan('product-requests:clean-duplicate-syncs', ['--commit' => true])->assertSuccessful();

        $this->assertNull($orphan->fresh());
    }

    /** The hourly recheck announces newly mapped SKUs on every open request. */
    public function test_an_automatic_balance_announcement_does_not_protect_the_copy(): void
    {
        $orphan = $this->request();
        $this->ledger($this->request());

        $orphan->activities()->create([
            'action'      => 'sku_mapping',
            'to_status'   => ProductRequest::SKU_VERIFIED,
            'description' => '3 more SKU(s) mapped — 5 of 5',
        ]);

        $this->artisan('product-requests:clean-duplicate-syncs', ['--commit' => true])->assertSuccessful();

        $this->assertNull($orphan->fresh());
    }

    /** Supply Chain recording a mapping by hand is real work, and it is theirs. */
    public function test_a_sku_mapped_by_hand_protects_the_copy(): void
    {
        $orphan = $this->request();
        $this->ledger($this->request());

        ProductRequestSku::create([
            'product_request_id' => $orphan->id,
            'sku'                => 'HAN-1',
            'mapping_status'     => ProductRequest::MAP_MAPPED,
            'mapping_set_by'     => $this->user->id,
            'mapping_set_at'     => now(),
        ]);

        $this->artisan('product-requests:clean-duplicate-syncs', ['--commit' => true])
            ->expectsOutputToContain('mapped by hand')
            ->assertSuccessful();

        $this->assertNotNull($orphan->fresh());
    }

    /** Carrying on with the mapped half makes the same move, but it is a decision. */
    public function test_continuing_with_the_mapped_half_protects_the_copy(): void
    {
        $orphan = $this->request();
        $this->ledger($this->request());

        $orphan->activities()->create([
            'user_id'     => $this->user->id,
            'action'      => 'status_changed',
            'from_status' => ProductRequest::WAITING_MAPPING,
            'to_status'   => ProductRequest::SKU_VERIFIED,
            'description' => 'Status changed from Waiting for Mapping to SKU Verified',
            'remarks'     => 'Continuing with 18 of 30 SKUs (60%) — 12 still with Supply Chain.',
        ]);

        $this->artisan('product-requests:clean-duplicate-syncs', ['--commit' => true])->assertSuccessful();

        $this->assertNotNull($orphan->fresh());
    }

    public function test_a_copy_someone_has_worked_on_is_kept_and_reported(): void
    {
        $orphan = $this->request();
        $this->ledger($this->request());

        $orphan->activities()->create([
            'user_id'     => $this->user->id,
            'action'      => 'comment',
            'description' => 'Checked with the brand team',
        ]);

        $this->artisan('product-requests:clean-duplicate-syncs', ['--commit' => true])
            ->expectsOutputToContain('someone has worked on it')
            ->assertSuccessful();

        $this->assertNotNull($orphan->fresh());
    }

    /** Once it is past SKU Verified there is work downstream depending on it. */
    public function test_a_copy_further_along_the_pipeline_is_kept(): void
    {
        $orphan = $this->request(status: ProductRequest::AI_CONTENT);
        $this->ledger($this->request());

        $this->artisan('product-requests:clean-duplicate-syncs', ['--commit' => true])->assertSuccessful();

        $this->assertNotNull($orphan->fresh());
    }
}
