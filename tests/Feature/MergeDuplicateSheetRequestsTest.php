<?php

namespace Tests\Feature;

use App\Models\ProductRequest;
use App\Models\ProductRequestActivity;
use App\Models\ProductRequestSheetSync;
use App\Models\ProductRequestSku;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TRIUMPH on 13 Oct came in six times: three master rows on one date, imported
 * once as #436–#438 and again after the sheet renumbered them to #269–#271.
 * Merging them must keep whatever somebody did and lose no SKU.
 */
class MergeDuplicateSheetRequestsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = User::create([
            'name' => 'Ahamed', 'email' => 'admin@example.test', 'password' => 'password',
            'is_active' => true, 'is_super_admin' => true, 'perm_product_request' => true,
        ]);

        $this->store = Store::create([
            'name' => 'Bluesalon Website', 'shopify_domain' => 'qatarbluesalon.myshopify.com', 'is_active' => true,
        ]);
    }

    private function request(int $no, array $skus, string $date = '2026-10-13', string $brand = 'TRIUMPH'): ProductRequest
    {
        $request = ProductRequest::create([
            'reference'          => ProductRequest::nextReference(),
            'sheet_request_no'   => $no,
            'sheet_request_date' => $date,
            'user_id'            => $this->user->id,
            'store_id'           => $this->store->id,
            'request_type'       => 'new_brand',
            'brand'              => $brand,
            'category'           => 'Lingerie',
            'status'             => ProductRequest::WAITING_MAPPING,
            'priority'           => 'medium',
            'total_skus'         => count($skus),
        ]);

        foreach ($skus as $sku) {
            ProductRequestSku::create(['product_request_id' => $request->id, 'sku' => $sku, 'mapping_status' => ProductRequest::MAP_PENDING]);
        }

        ProductRequestSheetSync::create([
            'request_no' => $no, 'website_token' => 'BS', 'store_id' => $this->store->id,
            'product_request_id' => $request->id, 'status' => 'created',
        ]);

        return $request;
    }

    public function test_copies_of_one_product_are_merged_into_the_oldest(): void
    {
        $oldest = $this->request(436, ['T-1', 'T-2']);
        $copyA  = $this->request(437, ['T-1', 'T-2', 'T-3']);
        $copyB  = $this->request(269, ['T-1', 'T-2']);

        $this->artisan('product-requests:merge-duplicates', ['--commit' => true])->assertSuccessful();

        $this->assertNull($copyA->fresh());
        $this->assertNull($copyB->fresh());
        $this->assertSame(['T-1', 'T-2', 'T-3'], $oldest->skus()->orderBy('sku')->pluck('sku')->all(), 'No SKU is lost.');
        $this->assertSame(3, $oldest->fresh()->total_skus);
        $this->assertSame([$oldest->id], ProductRequestSheetSync::pluck('product_request_id')->unique()->values()->all(),
            'Every number now points at the one that stays, so the sync never re-imports it.');
        $this->assertNotEmpty(Storage::disk('local')->files(), 'What was deleted is saved first.');
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->request(436, ['T-1']);
        $copy = $this->request(269, ['T-1']);

        $this->artisan('product-requests:merge-duplicates')
            ->expectsOutputToContain('Would merge')
            ->assertSuccessful();

        $this->assertNotNull($copy->fresh());
        $this->assertSame(2, ProductRequestSheetSync::distinct()->count('product_request_id'));
    }

    /** The one somebody worked on is the one that stays, whatever its age. */
    public function test_the_copy_someone_worked_on_is_kept(): void
    {
        $oldest = $this->request(436, ['T-1']);
        $worked = $this->request(269, ['T-1']);

        ProductRequestActivity::create([
            'product_request_id' => $worked->id, 'user_id' => $this->user->id,
            'action' => 'commented', 'description' => 'Asked the brand for images',
        ]);

        $this->artisan('product-requests:merge-duplicates', ['--commit' => true])->assertSuccessful();

        $this->assertNotNull($worked->fresh());
        $this->assertNull($oldest->fresh());
    }

    /** Every copy was staffed and had its mapping requested by the import itself. */
    public function test_what_the_import_did_by_itself_is_not_work(): void
    {
        $oldest = $this->request(436, ['T-1']);
        $copy   = $this->request(269, ['T-1']);

        foreach ([$oldest, $copy] as $request) {
            foreach (['assigned', 'mapping_requested'] as $action) {
                ProductRequestActivity::create([
                    'product_request_id' => $request->id, 'user_id' => $this->user->id,
                    'action' => $action, 'description' => 'At import',
                ]);
            }
        }

        $this->artisan('product-requests:merge-duplicates', ['--commit' => true])->assertSuccessful();

        $this->assertNotNull($oldest->fresh());
        $this->assertNull($copy->fresh());
    }

    /** Two people's work cannot be folded into one without someone choosing. */
    public function test_copies_worked_on_twice_are_left_for_a_person(): void
    {
        $a = $this->request(436, ['T-1']);
        $b = $this->request(269, ['T-1']);

        foreach ([$a, $b] as $request) {
            ProductRequestActivity::create([
                'product_request_id' => $request->id, 'user_id' => $this->user->id,
                'action' => 'commented', 'description' => 'Note',
            ]);
        }

        $this->artisan('product-requests:merge-duplicates', ['--commit' => true])
            ->expectsOutputToContain('left for a person')
            ->assertSuccessful();

        $this->assertNotNull($a->fresh());
        $this->assertNotNull($b->fresh());
    }

    /** BMW Fragrances, renamed SIMONE PERELE by a reused number, is not a copy of it. */
    public function test_a_request_renamed_by_the_sheet_is_left_for_the_repair(): void
    {
        $real    = $this->request(282, ['SP-1'], brand: 'SIMONE PERELE');
        $renamed = $this->request(283, ['SP-1'], brand: 'SIMONE PERELE');

        ProductRequestActivity::create([
            'product_request_id' => $renamed->id, 'action' => 'sheet_updated',
            'description' => 'Updated from the tracking sheet', 'remarks' => 'brand: "BMW Fragrances" → "SIMONE PERELE"',
        ]);

        $this->artisan('product-requests:merge-duplicates', ['--commit' => true])
            ->expectsOutputToContain('repair-reused-numbers first')
            ->assertSuccessful();

        $this->assertNotNull($real->fresh());
        $this->assertNotNull($renamed->fresh());

        // Once the repair has given it its own name back, it is no longer in the set.
        $renamed->update(['brand' => 'BMW Fragrances']);
        $this->artisan('product-requests:merge-duplicates', ['--commit' => true])
            ->doesntExpectOutputToContain('repair-reused-numbers first')
            ->assertSuccessful();
    }

    /** SANTINNI on 17 Sep and on 21 Oct are two batches, not copies. */
    public function test_the_same_brand_on_another_date_is_not_a_copy(): void
    {
        $september = $this->request(119, ['S-1'], '2026-09-17', 'SANTINNI');
        $october   = $this->request(337, ['S-2'], '2026-10-21', 'SANTINNI');

        $this->artisan('product-requests:merge-duplicates', ['--commit' => true])->assertSuccessful();

        $this->assertNotNull($september->fresh());
        $this->assertNotNull($october->fresh());
    }
}
