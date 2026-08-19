<?php

namespace Tests\Feature;

use App\Models\ProductRequest;
use App\Models\ProductRequestSheetSync;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The duplicate report has to name the right cause, or it sends people the wrong way. */
class DuplicateDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Ahamed', 'email' => 'admin@example.test', 'password' => 'password',
            'is_active' => true, 'is_super_admin' => true, 'perm_product_request' => true,
        ]);
    }

    private function store(string $domain): Store
    {
        return Store::create(['name' => $domain, 'shopify_domain' => $domain, 'is_active' => true]);
    }

    private function request(Store $store, string $brand): ProductRequest
    {
        return ProductRequest::create([
            'reference'    => ProductRequest::nextReference(),
            'user_id'      => $this->user->id,
            'store_id'     => $store->id,
            'request_type' => 'new_brand',
            'brand'        => $brand,
            'category'     => "Men's Fashion",
            'status'       => ProductRequest::SKU_VERIFIED,
            'priority'     => 'medium',
            'total_skus'   => 30,
        ]);
    }

    private function ledger(ProductRequest $request, int $requestNo, string $token): void
    {
        ProductRequestSheetSync::create([
            'request_no'         => $requestNo,
            'website_token'      => $token,
            'store_id'           => $request->store_id,
            'product_request_id' => $request->id,
            'status'             => 'created',
        ]);
    }

    public function test_it_reports_nothing_when_there_are_no_duplicates(): void
    {
        $store = $this->store('bs.myshopify.com');
        $this->request($store, 'ZIMMERLI');

        $this->artisan('product-requests:diagnose-duplicates')
            ->expectsOutputToContain('No duplicate')
            ->assertSuccessful();
    }

    /** Same brand on two websites is not a duplicate, and must not be reported as one. */
    public function test_two_websites_are_named_as_working_as_intended(): void
    {
        $bs = $this->store('bs.myshopify.com');
        $pg = $this->store('pg.myshopify.com');

        // Same store column would group them; two stores means two groups of one,
        // so the duplicate here is the same website token repeated per website.
        $first  = $this->request($bs, 'ZIMMERLI');
        $second = $this->request($bs, 'ZIMMERLI');

        $this->ledger($first, 40, 'BS');
        $this->ledger($second, 40, 'PG');

        $this->artisan('product-requests:diagnose-duplicates')
            ->expectsOutputToContain('different website tokens')
            ->assertSuccessful();
    }

    /** Same SKU count under two Request No is the renumbering signature. */
    public function test_two_request_numbers_with_the_same_sku_count_are_flagged(): void
    {
        $bs = $this->store('bs.myshopify.com');

        $first  = $this->request($bs, 'ZIMMERLI');
        $second = $this->request($bs, 'ZIMMERLI');

        $this->ledger($first, 40, 'BS');
        $this->ledger($second, 71, 'BS');

        $this->artisan('product-requests:diagnose-duplicates')
            ->expectsOutputToContain('looks like the sheet renumbered')
            ->assertSuccessful();
    }

    /** A brand asked for twice, with different SKUs, is two real requests. */
    public function test_two_request_numbers_with_different_sku_counts_are_called_normal(): void
    {
        $bs = $this->store('bs.myshopify.com');

        $first  = $this->request($bs, 'HANRO');
        $second = $this->request($bs, 'HANRO');
        $second->update(['total_skus' => 279]);

        $this->ledger($first, 124, 'BS');
        $this->ledger($second, 28, 'BS');

        $this->artisan('product-requests:diagnose-duplicates')
            ->expectsOutputToContain('nothing wrong')
            ->assertSuccessful();
    }

    public function test_a_copy_with_no_ledger_row_is_named_as_such(): void
    {
        $bs = $this->store('bs.myshopify.com');

        $first = $this->request($bs, 'ZIMMERLI');
        $this->request($bs, 'ZIMMERLI');

        $this->ledger($first, 40, 'BS');

        $this->artisan('product-requests:diagnose-duplicates')
            ->expectsOutputToContain('no sheet ledger row')
            ->assertSuccessful();
    }
}
