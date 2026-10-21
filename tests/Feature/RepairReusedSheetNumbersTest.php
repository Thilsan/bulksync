<?php

namespace Tests\Feature;

use App\Models\ProductRequest;
use App\Models\ProductRequestSku;
use App\Models\Store;
use App\Models\User;
use App\Services\ProductRequestWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PCR-00335 was imported as BMW Fragrances with 5 SKUs. The sheet then gave its
 * Request No to Simone Perele, and the sync renamed it and added 1,213 lingerie
 * SKUs — still filed under Beauty, still mailing the Beauty brand managers.
 */
class RepairReusedSheetNumbersTest extends TestCase
{
    use RefreshDatabase;

    private function damaged(): ProductRequest
    {
        $user  = User::create(['name' => 'Sync', 'email' => 'sync@example.test', 'password' => 'x', 'is_active' => true]);
        $store = Store::create(['name' => 'Bluesalon', 'shopify_domain' => 'bs.myshopify.com', 'is_active' => true]);
        $flow  = app(ProductRequestWorkflow::class);

        $this->travelTo('2026-10-12 20:03');

        $request = ProductRequest::create([
            'reference' => ProductRequest::nextReference(), 'sheet_request_no' => 282,
            'user_id' => $user->id, 'store_id' => $store->id, 'request_type' => 'new_brand',
            'brand' => 'BMW Fragrances', 'category' => 'Beauty', 'status' => ProductRequest::PUBLISHED,
            'priority' => 'medium', 'online_launch_date' => '2026-10-15',
        ]);
        $this->skus($request, ['BMW-1', 'BMW-2']);
        $flow->log($request, 'created', 'Product request created automatically from the SharePoint tracking sheet');

        $this->travelTo('2026-10-14 12:03');
        $flow->log($request, 'status_changed', 'Status changed from SKU Mapped to Published');

        // The sync, reading another product under the same number.
        $this->travelTo('2026-10-21 12:04');
        $this->skus($request, ['SP-1', 'SP-2', 'SP-3']);
        $flow->log($request, 'skus_added', '3 SKU(s) added from the tracking sheet');
        $flow->log($request, 'sheet_updated', 'Updated from the tracking sheet',
            remarks: 'brand: "BMW Fragrances" → "SIMONE PERELE"; online_launch_date: "2026-10-15" → "2026-10-21"');
        $request->update(['brand' => 'SIMONE PERELE', 'online_launch_date' => '2026-10-21']);

        $this->travelTo('2026-10-21 14:13');
        $this->skus($request, ['SP-4']);
        $flow->log($request, 'skus_added', '1 SKU(s) added from the tracking sheet');

        $this->travelBack();

        return $request;
    }

    private function skus(ProductRequest $request, array $skus): void
    {
        foreach ($skus as $sku) {
            ProductRequestSku::create(['product_request_id' => $request->id, 'sku' => $sku, 'mapping_status' => ProductRequest::MAP_PENDING]);
        }
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $request = $this->damaged();

        $this->artisan('product-requests:repair-reused-numbers')
            ->expectsOutputToContain('"SIMONE PERELE" → "BMW Fragrances"   remove 4 of 6 SKUs')
            ->assertSuccessful();

        $this->assertSame('SIMONE PERELE', $request->refresh()->brand);
        $this->assertSame(6, $request->skus()->count());
    }

    public function test_the_request_is_put_back_and_the_other_rows_skus_removed(): void
    {
        $request = $this->damaged();

        $this->artisan('product-requests:repair-reused-numbers', ['--commit' => true])->assertSuccessful();

        $request->refresh();
        $this->assertSame('BMW Fragrances', $request->brand);
        $this->assertSame('2026-10-15', $request->online_launch_date->toDateString());
        $this->assertSame(['BMW-1', 'BMW-2'], $request->skus()->orderBy('sku')->pluck('sku')->all());
        $this->assertSame(2, $request->total_skus);
        $this->assertSame('BMW Fragrances', $request->sheet_snapshot['brand'], 'The sync guard compares against this.');
        $this->assertSame(ProductRequest::PUBLISHED, $request->status, 'Its stage is the work people did — kept.');
        $this->assertTrue($request->activities()->where('action', 'sheet_repaired')->exists());

        // Nothing left to do on a second run.
        $this->artisan('product-requests:repair-reused-numbers', ['--commit' => true])
            ->expectsOutputToContain('Repaired 0 request(s).');
        $this->assertSame(1, $request->activities()->where('action', 'sheet_repaired')->count());
    }

    public function test_a_corrected_spelling_is_not_a_takeover(): void
    {
        $request = $this->damaged();
        $request->activities()->where('action', 'sheet_updated')->update(['remarks' => 'brand: "Armani" → "ARMANI BEAUTY"']);

        $this->artisan('product-requests:repair-reused-numbers', ['--commit' => true])
            ->expectsOutputToContain('Repaired 0')
            ->assertSuccessful();
    }
}
