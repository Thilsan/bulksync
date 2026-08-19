<?php

namespace Tests\Feature;

use App\Models\ProductRequest;
use App\Models\ProductRequestSheetSync;
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
