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
 * Changing which document the sheet sync reads means the ledger's Request No
 * values belong to a file nobody is reading any more. Clearing both together is
 * the only safe way to re-point it — but it deletes real requests, so what it
 * takes and what it spares is worth pinning down.
 */
class ResetSheetSyncTest extends TestCase
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

    private function request(string $brand, string $status = ProductRequest::SKU_VERIFIED, ?int $sheetNo = null): ProductRequest
    {
        return ProductRequest::create([
            'reference'        => ProductRequest::nextReference(),
            'user_id'          => $this->user->id,
            'store_id'         => $this->store->id,
            'request_type'     => 'new_brand',
            'brand'            => $brand,
            'category'         => 'Lingerie',
            'status'           => $status,
            'priority'         => 'medium',
            'sheet_request_no' => $sheetNo,
            'total_skus'       => 1,
        ]);
    }

    private function ledger(ProductRequest $request, int $requestNo): void
    {
        ProductRequestSheetSync::create([
            'request_no'         => $requestNo,
            'website_token'      => 'BS',
            'store_id'           => $request->store_id,
            'product_request_id' => $request->id,
            'status'             => 'created',
        ]);
    }

    public function test_imported_requests_and_the_whole_ledger_go(): void
    {
        $imported = $this->request('HANRO', sheetNo: 28);
        $this->ledger($imported, 28);

        // An orphaned ledger row from a deleted duplicate: its number still
        // belongs to the old file, so it has to go too.
        ProductRequestSheetSync::create([
            'request_no' => 99, 'website_token' => 'BS', 'status' => 'created',
        ]);

        $this->artisan('product-requests:reset-sheet-sync', ['--commit' => true])->assertSuccessful();

        $this->assertNull($imported->fresh());
        $this->assertSame(0, ProductRequestSheetSync::count(), 'A stale number would make the next sync skip a row it has never seen.');
    }

    /** A request somebody raised in the app has nothing to do with the sheet. */
    public function test_a_request_raised_by_hand_is_never_touched(): void
    {
        $manual = $this->request('RAISED BY HAND');

        $this->artisan('product-requests:reset-sheet-sync', ['--commit' => true])->assertSuccessful();

        $this->assertNotNull($manual->fresh());
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        $imported = $this->request('HANRO', sheetNo: 28);
        $this->ledger($imported, 28);

        $this->artisan('product-requests:reset-sheet-sync')
            ->expectsOutputToContain('would go')
            ->assertSuccessful();

        $this->assertNotNull($imported->fresh());
        $this->assertSame(1, ProductRequestSheetSync::count());
    }

    /** Work somebody did is named and spared unless it is explicitly overridden. */
    public function test_a_request_with_work_on_it_is_kept_and_named(): void
    {
        $plain  = $this->request('PLAIN', sheetNo: 28);
        $worked = $this->request('WORKED ON', sheetNo: 29);

        $this->ledger($plain, 28);
        $this->ledger($worked, 29);

        ProductRequestSku::create([
            'product_request_id' => $worked->id,
            'sku'                => 'W-1',
            'mapping_status'     => ProductRequest::MAP_MAPPED,
            'mapping_set_by'     => $this->user->id,
            'mapping_set_at'     => now(),
        ]);

        $this->artisan('product-requests:reset-sheet-sync', ['--commit' => true])
            ->expectsOutputToContain('mapped by hand')
            ->assertSuccessful();

        $this->assertNull($plain->fresh());
        $this->assertNotNull($worked->fresh(), 'Work nobody agreed to lose must survive.');
    }

    public function test_force_deletes_the_worked_on_ones_too(): void
    {
        $worked = $this->request('WORKED ON', status: ProductRequest::AI_CONTENT, sheetNo: 29);
        $this->ledger($worked, 29);

        $this->artisan('product-requests:reset-sheet-sync', ['--commit' => true, '--force' => true])
            ->assertSuccessful();

        $this->assertNull($worked->fresh());
    }

    public function test_it_says_so_when_there_is_nothing_imported(): void
    {
        $this->request('RAISED BY HAND');

        $this->artisan('product-requests:reset-sheet-sync')
            ->expectsOutputToContain('nothing to reset')
            ->assertSuccessful();
    }
}
