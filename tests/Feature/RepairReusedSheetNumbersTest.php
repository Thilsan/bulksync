<?php

namespace Tests\Feature;

use App\Models\ProductRequest;
use App\Models\ProductRequestSku;
use App\Models\Store;
use App\Models\User;
use App\Services\OneDriveService;
use App\Services\ProductRequestWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
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
        $flow->log($request, 'created', 'Product request created automatically from the SharePoint tracking sheet', remarks: '2 SKUs imported');

        // The neighbour's first SKUs came in on an earlier run, before the sync
        // could rename anything — the log has no rename to date them by.
        $this->travelTo('2026-10-13 10:00');
        $this->skus($request, ['SP-0']);
        $flow->log($request, 'skus_added', '1 SKU(s) added from the tracking sheet');

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

    /** The Beauty tab: which SKU is whose, whatever the master tab's numbers say. */
    private function fakeTab(array $rows): void
    {
        $drive = Mockery::mock(OneDriveService::class);
        $drive->shouldReceive('asServiceAccount')->andReturnSelf();
        $drive->shouldReceive('resolveShareItem')->andReturn(['driveId' => 'd', 'itemId' => 'i']);
        $drive->shouldReceive('worksheetValues')->with('d', 'i', 'Perfumes & Cosmetics')
            ->andReturn(array_merge([['Date', 'Brand Name', 'Item SKU']], $rows));
        $drive->shouldReceive('worksheetValues')->andReturn([['Date', 'Brand Name', 'Item SKU']]);   // every other tab
        $this->app->instance(OneDriveService::class, $drive);
    }

    private function bothBrandsOnTab(): void
    {
        $this->fakeTab([
            ['12-Oct-26', 'BMW FRAGRANCES', 'BMW-1'],
            ['12-Oct-26', 'BMW Fragrances ', 'BMW-2'],
            ['21-Oct-26', 'SIMONE PERELE', 'SP-0'],
            ['21-Oct-26', 'SIMONE PERELE', 'SP-1'],
        ]);
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
        $this->bothBrandsOnTab();

        $this->artisan('product-requests:repair-reused-numbers')
            ->expectsOutputToContain('"SIMONE PERELE" → "BMW Fragrances"   keep 2 of 7 SKUs   (created with 2)')
            ->assertSuccessful();

        $this->assertSame('SIMONE PERELE', $request->refresh()->brand);
        $this->assertSame(7, $request->skus()->count());
    }

    public function test_the_request_is_put_back_and_the_other_rows_skus_removed(): void
    {
        Storage::fake('local');
        $request = $this->damaged();
        $this->bothBrandsOnTab();

        $this->artisan('product-requests:repair-reused-numbers', ['--commit' => true])->assertSuccessful();

        $request->refresh();
        $this->assertSame('BMW Fragrances', $request->brand);
        $this->assertSame('2026-10-15', $request->online_launch_date->toDateString());
        $this->assertSame(['BMW-1', 'BMW-2'], $request->skus()->orderBy('sku')->pluck('sku')->all());
        $this->assertSame(2, $request->total_skus);
        $this->assertSame('BMW Fragrances', $request->sheet_snapshot['brand'], 'The sync guard compares against this.');
        $this->assertSame(ProductRequest::PUBLISHED, $request->status, 'Its stage is the work people did — kept.');
        $this->assertTrue($request->activities()->where('action', 'sheet_repaired')->exists());

        // What was removed can be put back.
        $saved = json_decode(Storage::disk('local')->get(Storage::disk('local')->files()[0]), true);
        $this->assertEqualsCanonicalizing(['SP-0', 'SP-1', 'SP-2', 'SP-3', 'SP-4'], array_column($saved[$request->reference], 'sku'));

        // Nothing left to do on a second run.
        $this->artisan('product-requests:repair-reused-numbers', ['--commit' => true])
            ->expectsOutputToContain('Repaired 0 request(s), removing 0 SKU(s).');
        $this->assertSame(1, $request->activities()->where('action', 'sheet_repaired')->count());
    }

    public function test_a_corrected_spelling_is_not_a_takeover(): void
    {
        $request = $this->damaged();
        $this->bothBrandsOnTab();
        $request->activities()->where('action', 'sheet_updated')->update(['remarks' => 'brand: "Armani" → "ARMANI BEAUTY"']);

        $this->artisan('product-requests:repair-reused-numbers', ['--commit' => true])
            ->expectsOutputToContain('Repaired 0')
            ->assertSuccessful();
    }

    /** AUBADE is gone from the Lingerie tab, but the tabs still say whose its neighbour's SKUs are. */
    public function test_a_brand_the_tab_does_not_know_loses_only_skus_another_brand_owns(): void
    {
        $request = $this->damaged();
        $this->fakeTab([
            ['21-Oct-26', 'SIMONE PERELE', 'SP-1'],
            ['21-Oct-26', 'SIMONE PERELE', 'SP-2'],
        ]);

        $this->artisan('product-requests:repair-reused-numbers', ['--commit' => true])
            ->expectsOutputToContain("remove 2 other brands' SKUs, keep 5 unconfirmed of 7")
            ->expectsOutputToContain('worth a look')
            ->assertSuccessful();

        $this->assertSame('BMW Fragrances', $request->refresh()->brand);
        $this->assertEqualsCanonicalizing(['BMW-1', 'BMW-2', 'SP-0', 'SP-3', 'SP-4'], $request->skus()->pluck('sku')->all());
    }

    /** SELMARK: on the tab, but none of the request's SKUs are — keep 0 of 32 is not a repair. */
    public function test_a_request_is_never_emptied(): void
    {
        $request = $this->damaged();
        $this->fakeTab([['12-Oct-26', 'BMW FRAGRANCES', 'SOMETHING-ELSE']]);

        $this->artisan('product-requests:repair-reused-numbers', ['--commit' => true])
            ->expectsOutputToContain('every one is listed under another brand')
            ->assertSuccessful();

        $this->assertSame('BMW Fragrances', $request->refresh()->brand);
        $this->assertSame(7, $request->skus()->count());
    }

    /** "Home" is in the config but not in the workbook. */
    public function test_a_missing_tab_does_not_stop_the_repair(): void
    {
        $request = $this->damaged();

        $drive = Mockery::mock(OneDriveService::class);
        $drive->shouldReceive('asServiceAccount')->andReturnSelf();
        $drive->shouldReceive('resolveShareItem')->andReturn(['driveId' => 'd', 'itemId' => 'i']);
        $drive->shouldReceive('worksheetValues')->with('d', 'i', 'Perfumes & Cosmetics')->andReturn([
            ['Date', 'Brand Name', 'Item SKU'],
            ['21-Oct-26', 'SIMONE PERELE', 'SP-1'],
        ]);
        $drive->shouldReceive('worksheetValues')->with('d', 'i', 'Home')->andThrow(new \RuntimeException('ItemNotFound', 404));
        $drive->shouldReceive('worksheetValues')->andReturn([['Date', 'Brand', 'LUI204DRZ00990']]);
        $this->app->instance(OneDriveService::class, $drive);

        $this->artisan('product-requests:repair-reused-numbers', ['--commit' => true])
            ->expectsOutputToContain('Home: no such tab in the workbook')
            ->expectsOutputToContain('no "Brand Name" or "Item SKU" header')
            ->assertSuccessful();

        // BMW is on no tab, so every tab was read for the neighbour's SKUs —
        // and the ones that could not be read did not stop it.
        $this->assertSame(['BMW-1', 'BMW-2', 'SP-0', 'SP-2', 'SP-3', 'SP-4'], $request->refresh()->skus()->orderBy('sku')->pluck('sku')->all());
    }
}
