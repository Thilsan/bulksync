<?php

namespace Tests\Feature;

use App\Jobs\ValidateProductRequestSkusJob;
use App\Models\ProductRequest;
use App\Models\ProductRequestSheetSync;
use App\Models\Store;
use App\Models\User;
use App\Services\OneDriveService;
use App\Services\ProductRequestSheetSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Covers the sheet → ProductRequest sync. The sheet itself is stubbed at the
 * OneDriveService boundary so the tests never touch SharePoint.
 */
class ProductRequestSheetSyncTest extends TestCase
{
    use RefreshDatabase;

    private function syncUser(): User
    {
        return User::create([
            'name'                 => 'Ahamed',
            'email'                => config('product_request_sync.sync_user_email'),
            'password'             => 'password',
            'is_active'            => true,
            'perm_product_request' => true,
        ]);
    }

    /** The person the Lingerie category falls to when nobody is named. */
    private function categoryOwner(): User
    {
        return User::create([
            'name'                 => 'Lingerie Owner',
            'email'                => 'lingerie@example.test',
            'password'             => 'password',
            'is_active'            => true,
            'perm_product_request' => true,
            'pcr_role'             => 'ecommerce',
            'pcr_categories'       => ['Lingerie'],
        ]);
    }

    private function store(): Store
    {
        return Store::create([
            'name'                 => 'Bluesalon Website',
            'shopify_domain'       => config('product_request_sync.website_store_map.BS'),
            'is_active'            => true,
            'requires_sku_mapping' => true,
        ]);
    }

    /** One master row (KAYCEE / LINGERIE / BS) plus the matching SKU tab. */
    private function fakeSheet(array $masterOverrides = []): void
    {
        $masterRow = array_merge([
            'Request No'                      => '17',
            'Request Date'                    => '05-Aug-26',
            'Requested By'                    => 'KAYCEE',
            'Department'                      => 'LINGERIE',
            'Brand'                           => 'AMADAMARIA',
            'Website'                         => 'BS',
            'Collection/Season'               => 'BASIC',
            'Category'                        => 'LINGARIE',
            'Requested Website Go-Live Date'  => '20-Aug-26',
            'Priority'                        => 'High',
        ], $masterOverrides);

        $drive = Mockery::mock(OneDriveService::class);
        $drive->shouldReceive('asServiceAccount')->andReturnSelf();
        $drive->shouldReceive('setUser')->andReturnSelf();
        $drive->shouldReceive('resolveShareItem')->andReturn(['driveId' => 'd', 'itemId' => 'i']);
        $drive->shouldReceive('worksheetValues')
            ->with('d', 'i', config('product_request_sync.master_worksheet'))
            ->andReturn([array_keys($masterRow), array_values($masterRow)]);
        $drive->shouldReceive('worksheetValues')
            ->with('d', 'i', 'Lingerie')
            ->andReturn([
                ['Date', 'Brand Name', 'Item SKU'],
                ['05-Aug-26', 'AMADAMARIA', 'SKU-1'],
                ['05-Aug-26', 'AMADAMARIA', 'SKU-2'],
                ['05-Aug-26', 'OTHER BRAND', 'SKU-3'],
            ]);

        $this->app->instance(OneDriveService::class, $drive);
    }

    public function test_it_stores_the_sheet_request_no_and_requester_name(): void
    {
        Queue::fake();
        $this->syncUser();
        $this->store();
        $this->fakeSheet();

        $result = app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->assertSame(1, $result['created']);

        $request = ProductRequest::sole();
        $this->assertSame(17, $request->sheet_request_no);
        $this->assertSame('KAYCEE', $request->sheet_requested_by);
        $this->assertSame('KAYCEE', $request->requesterName());
        $this->assertSame(2, $request->total_skus);

        Queue::assertPushed(ValidateProductRequestSkusJob::class);
    }

    /** The requester owns the request when the sheet name is a real account. */
    public function test_a_matching_user_still_becomes_the_owner(): void
    {
        Queue::fake();
        $syncUser = $this->syncUser();
        $this->store();
        $kaycee = User::create([
            'name'                 => 'KAYCEE',
            'email'                => 'kaycee@example.test',
            'password'             => 'password',
            'is_active'            => true,
            'perm_product_request' => true,
        ]);
        $this->fakeSheet();

        app(ProductRequestSheetSyncService::class)->run(commit: true);

        $request = ProductRequest::sole();
        $this->assertSame($kaycee->id, $request->user_id);
        $this->assertNotSame($syncUser->id, $request->user_id);
        $this->assertSame('KAYCEE', $request->requesterName());
    }

    /** A name with no account leaves ownership with the sync user but still displays the real requester. */
    public function test_an_unknown_requester_falls_back_to_the_sync_user_for_ownership_only(): void
    {
        Queue::fake();
        $syncUser = $this->syncUser();
        $this->store();
        $this->fakeSheet();

        app(ProductRequestSheetSyncService::class)->run(commit: true);

        $request = ProductRequest::sole();
        $this->assertSame($syncUser->id, $request->user_id);
        $this->assertSame('KAYCEE', $request->requesterName());
    }

    public function test_a_rerun_backfills_requests_synced_before_the_columns_existed(): void
    {
        Queue::fake();
        $this->syncUser();
        $store = $this->store();
        $this->fakeSheet();

        app(ProductRequestSheetSyncService::class)->run(commit: true);

        // Simulate a request created by the first version of the sync.
        $request = ProductRequest::sole();
        $request->forceFill(['sheet_request_no' => null, 'sheet_requested_by' => null])->save();

        $this->fakeSheet();
        $result = app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['backfilled']);
        $this->assertSame(1, ProductRequest::count());

        $request->refresh();
        $this->assertSame(17, $request->sheet_request_no);
        $this->assertSame('KAYCEE', $request->sheet_requested_by);
        $this->assertSame($store->id, $request->store_id);
    }

    public function test_a_second_run_with_nothing_to_fix_creates_and_changes_nothing(): void
    {
        Queue::fake();
        $this->syncUser();
        $this->store();
        $this->fakeSheet();

        app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->fakeSheet();
        $result = app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['backfilled']);
        $this->assertSame(1, $result['skipped_existing']);
        $this->assertSame(1, ProductRequest::count());
        $this->assertSame(1, ProductRequestSheetSync::count());
    }

    /** Same staffing as a request raised by hand — never "nobody assigned yet". */
    public function test_a_synced_request_is_staffed_from_its_category(): void
    {
        Queue::fake();
        Notification::fake();
        $this->syncUser();
        $owner = $this->categoryOwner();
        $this->store();
        $this->fakeSheet();

        app(ProductRequestSheetSyncService::class)->run(commit: true);

        $request = ProductRequest::sole();
        $this->assertGreaterThan(0, $request->currentAssignments()->count(), 'The request should not be unassigned.');
        $this->assertSame($owner->id, $request->ownerFor('assigned_to')?->id);
    }

    /** A category owner does not need one email per row of a 200-row import. */
    public function test_bulk_staffing_sends_no_assignment_emails(): void
    {
        Queue::fake();
        Notification::fake();
        $this->syncUser();
        $this->categoryOwner();
        $this->store();
        $this->fakeSheet();

        app(ProductRequestSheetSyncService::class)->run(commit: true);

        Notification::assertNothingSent();
    }

    public function test_a_rerun_staffs_requests_the_first_version_left_unassigned(): void
    {
        Queue::fake();
        Notification::fake();
        $this->syncUser();
        $owner = $this->categoryOwner();
        $this->store();
        $this->fakeSheet();

        app(ProductRequestSheetSyncService::class)->run(commit: true);

        // Simulate a request created before the sync staffed anything.
        $request = ProductRequest::sole();
        $request->assignments()->delete();
        $this->assertSame(0, $request->currentAssignments()->count());

        $this->fakeSheet();
        $result = app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['backfilled']);
        $this->assertSame($owner->id, $request->refresh()->ownerFor('assigned_to')?->id);
    }

    /** Someone put on a role by hand is never replaced by the category default. */
    public function test_a_rerun_leaves_people_already_assigned_alone(): void
    {
        Queue::fake();
        Notification::fake();
        $this->syncUser();
        $this->categoryOwner();
        $this->store();
        $this->fakeSheet();

        app(ProductRequestSheetSyncService::class)->run(commit: true);

        $request = ProductRequest::sole();
        $before  = $request->currentAssignments()->pluck('user_id', 'role')->all();

        $this->fakeSheet();
        $result = app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->assertSame(0, $result['backfilled']);
        $this->assertSame(1, $result['skipped_existing']);
        $this->assertSame($before, $request->refresh()->currentAssignments()->pluck('user_id', 'role')->all());
    }

    /** A skipped row has to say which row and why, or nobody can fix the sheet. */
    public function test_a_row_with_an_unmapped_website_says_which_row_and_why(): void
    {
        Queue::fake();
        $this->syncUser();
        $this->store();
        $this->fakeSheet(['Website' => 'Gold Gourmet']);

        $result = app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->assertSame(1, $result['unmatched_store']);

        $log = implode("\n", $result['log']);
        $this->assertStringContainsString('Request No 17', $log);
        $this->assertStringContainsString('AMADAMARIA', $log);
        $this->assertStringContainsString('Gold Gourmet', $log);
    }

    /**
     * Every request against one tab failing is a header problem, not eighteen
     * missing rows — the message has to say which, or people go looking in the
     * wrong place.
     */
    public function test_a_tab_with_the_wrong_headers_says_which_column_is_missing(): void
    {
        Queue::fake();
        $this->syncUser();
        $this->store();

        $drive = Mockery::mock(OneDriveService::class);
        $drive->shouldReceive('asServiceAccount')->andReturnSelf();
        $drive->shouldReceive('setUser')->andReturnSelf();
        $drive->shouldReceive('resolveShareItem')->andReturn(['driveId' => 'd', 'itemId' => 'i']);
        $drive->shouldReceive('worksheetValues')
            ->with('d', 'i', config('product_request_sync.master_worksheet'))
            ->andReturn([
                ['Request No', 'Request Date', 'Requested By', 'Department', 'Brand', 'Website'],
                ['17', '05-Aug-26', 'KAYCEE', 'LINGERIE', 'AMADAMARIA', 'BS'],
            ]);
        $drive->shouldReceive('worksheetValues')
            ->with('d', 'i', 'Lingerie')
            ->andReturn([
                ['Date', 'Brand', 'SKU'],          // "Brand Name" and "Item SKU" both wrong
                ['05-Aug-26', 'AMADAMARIA', 'SKU-1'],
            ]);

        $this->app->instance(OneDriveService::class, $drive);

        $result = app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->assertSame(1, $result['unmatched_skus']);

        $log = implode("\n", $result['log']);
        $this->assertStringContainsString('"Brand Name"', $log);
        $this->assertStringContainsString('"Item SKU"', $log);
        $this->assertStringNotContainsString('no row in', $log, 'A header problem must not read as a missing row.');
    }

    public function test_a_row_whose_skus_are_not_on_the_category_tab_says_so(): void
    {
        Queue::fake();
        $this->syncUser();
        $this->store();
        $this->fakeSheet(['Brand' => 'BRAND WITH NO SKU ROWS']);

        $result = app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->assertSame(1, $result['unmatched_skus']);
        $this->assertStringContainsString('no row in "Lingerie"', implode("\n", $result['log']));
    }

    /**
     * "No row matched" is true but unusable. When the brand is on the tab under
     * other dates, saying so points at the master tab's Request Date — which is
     * the actual edit.
     */
    public function test_a_date_mismatch_says_which_dates_the_brand_is_on(): void
    {
        Queue::fake();
        $this->syncUser();
        $this->store();

        $drive = Mockery::mock(OneDriveService::class);
        $drive->shouldReceive('asServiceAccount')->andReturnSelf();
        $drive->shouldReceive('setUser')->andReturnSelf();
        $drive->shouldReceive('resolveShareItem')->andReturn(['driveId' => 'd', 'itemId' => 'i']);
        $drive->shouldReceive('worksheetValues')
            ->with('d', 'i', config('product_request_sync.master_worksheet'))
            ->andReturn([
                ['Request No', 'Request Date', 'Requested By', 'Department', 'Brand', 'Website'],
                ['55', '12-Aug-26', 'KAYCEE', 'LINGERIE', 'CERRUTI', 'BS'],
            ]);
        $drive->shouldReceive('worksheetValues')
            ->with('d', 'i', 'Lingerie')
            ->andReturn([
                ['Date', 'Brand Name', 'Item SKU'],
                ['10-Aug-26', 'CERRUTI', 'CER-1'],
                ['11-Aug-26', 'CERRUTI', 'CER-2'],
            ]);

        $this->app->instance(OneDriveService::class, $drive);

        $result = app(ProductRequestSheetSyncService::class)->run(commit: true);
        $log    = implode("\n", $result['log']);

        $this->assertSame(1, $result['unmatched_skus']);
        $this->assertStringContainsString('that brand is on the tab, dated', $log);
        $this->assertStringContainsString('2026-08-10', $log);
    }

    /** A near-miss spelling is the other common cause, and worth naming. */
    public function test_a_brand_spelled_differently_is_named(): void
    {
        Queue::fake();
        $this->syncUser();
        $this->store();

        $drive = Mockery::mock(OneDriveService::class);
        $drive->shouldReceive('asServiceAccount')->andReturnSelf();
        $drive->shouldReceive('setUser')->andReturnSelf();
        $drive->shouldReceive('resolveShareItem')->andReturn(['driveId' => 'd', 'itemId' => 'i']);
        $drive->shouldReceive('worksheetValues')
            ->with('d', 'i', config('product_request_sync.master_worksheet'))
            ->andReturn([
                ['Request No', 'Request Date', 'Requested By', 'Department', 'Brand', 'Website'],
                ['55', '12-Aug-26', 'KAYCEE', 'LINGERIE', 'CERRUTI', 'BS'],
            ]);
        $drive->shouldReceive('worksheetValues')
            ->with('d', 'i', 'Lingerie')
            ->andReturn([
                ['Date', 'Brand Name', 'Item SKU'],
                ['12-Aug-26', 'CERRUTI 1881', 'CER-1'],
            ]);

        $this->app->instance(OneDriveService::class, $drive);

        $result = app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->assertStringContainsString('does have CERRUTI 1881', implode("\n", $result['log']));
    }

    /**
     * The sheet often names the department the way this app names the category —
     * "LUGGAGE" instead of "Travel". Matching on the category too means the
     * obvious word works without an alias for every variation somebody types.
     */
    public function test_a_department_named_after_the_category_still_maps(): void
    {
        Queue::fake();
        $this->syncUser();
        $this->store();

        $drive = Mockery::mock(OneDriveService::class);
        $drive->shouldReceive('asServiceAccount')->andReturnSelf();
        $drive->shouldReceive('setUser')->andReturnSelf();
        $drive->shouldReceive('resolveShareItem')->andReturn(['driveId' => 'd', 'itemId' => 'i']);
        $drive->shouldReceive('worksheetValues')
            ->with('d', 'i', config('product_request_sync.master_worksheet'))
            ->andReturn([
                ['Request No', 'Request Date', 'Requested By', 'Department', 'Brand', 'Website'],
                // "LUGGAGE" is the category name, not one of the department keys.
                ['191', '19-Aug-26', 'ANJALI', 'LUGGAGE', 'Stokke', 'BS'],
            ]);
        $drive->shouldReceive('worksheetValues')
            ->with('d', 'i', 'Luggage')
            ->andReturn([
                ['Date', 'Brand Name', 'Item SKU'],
                ['19-Aug-26', 'Stokke', 'JKD207LUG00010'],
            ]);

        $this->app->instance(OneDriveService::class, $drive);

        $result = app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->assertSame(0, $result['unmatched_department']);
        $this->assertSame(1, $result['created']);
        $this->assertSame('Luggage', ProductRequest::sole()->category);
    }

    /** The same department typed short still finds its tab. */
    public function test_a_short_department_name_still_maps(): void
    {
        $this->assertSame(
            config('product_request_sync.department_map.LEATHER GOODS'),
            config('product_request_sync.department_map.LEATHER'),
        );
    }

    /**
     * The sheet writes the bare name, the app names its stores "<x> Website" —
     * so a token has to find the store despite the suffix, or every new website
     * needs a config edit and a deploy before it can sync.
     */
    public function test_a_token_finds_a_store_named_with_the_website_suffix(): void
    {
        Queue::fake();
        $this->syncUser();

        Store::create([
            'name'                 => 'Gold Gourmet Website',
            'shopify_domain'       => 'goldgourmet.myshopify.com',
            'is_active'            => true,
            'requires_sku_mapping' => false,
        ]);

        $this->fakeSheet(['Website' => 'Gold Gourmet']);

        $result = app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->assertSame(0, $result['unmatched_store']);
        $this->assertSame(1, $result['created']);
        $this->assertSame('Gold Gourmet Website', ProductRequest::sole()->store->name);
    }

    /**
     * Request 191 imported with 3 SKUs while the sheet said 4 — one row carried a
     * September date among August ones. The request still imports; the point is
     * that nobody has to count by hand to notice.
     */
    public function test_a_short_sku_count_is_reported_against_the_sheets_own_number(): void
    {
        Queue::fake();
        $this->syncUser();
        $this->store();

        $drive = Mockery::mock(OneDriveService::class);
        $drive->shouldReceive('asServiceAccount')->andReturnSelf();
        $drive->shouldReceive('setUser')->andReturnSelf();
        $drive->shouldReceive('resolveShareItem')->andReturn(['driveId' => 'd', 'itemId' => 'i']);
        $drive->shouldReceive('worksheetValues')
            ->with('d', 'i', config('product_request_sync.master_worksheet'))
            ->andReturn([
                ['Request No', 'Request Date', 'Requested By', 'Department', 'Brand', 'Website', 'SKU Count'],
                ['191', '19-Aug-26', 'ANJALI', 'LUGGAGE', 'Stokke', 'BS', '4'],
            ]);
        $drive->shouldReceive('worksheetValues')
            ->with('d', 'i', 'Luggage')
            ->andReturn([
                ['Date', 'Brand Name', 'Item SKU'],
                ['19-Sep-26', 'Stokke', 'JKD207LUG00010'],   // a month out, so it will not match
                ['19-Aug-26', 'Stokke', 'JKD207LUG00011'],
                ['19-Aug-26', 'Stokke', 'JKD207LUG00012'],
                ['19-Aug-26', 'Stokke', 'JKD207LUG00013'],
            ]);

        $this->app->instance(OneDriveService::class, $drive);

        $result = app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->assertSame(1, $result['created'], 'It still imports — short, not skipped.');
        $this->assertSame(3, ProductRequest::sole()->skus()->count());
        $this->assertSame(1, $result['count_mismatch']);
        $this->assertStringContainsString('the tab only has 3 row(s) for that date', implode("\n", $result['log']));
    }

    /**
     * The first import is the one moment nobody is checking the numbers, so a
     * request that came in short has to keep saying so on later runs.
     */
    public function test_an_already_synced_request_that_is_short_keeps_being_reported(): void
    {
        Queue::fake();
        $this->syncUser();
        $this->store();

        // Imported with 2 of the 3 the sheet claims.
        $this->fakeSheetWithSkus(['SKU-1', 'SKU-2'], expected: 3);
        app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->assertSame(2, ProductRequest::sole()->skus()->count());

        // Nothing has changed on the sheet — it must still say the count is wrong.
        $this->fakeSheetWithSkus(['SKU-1', 'SKU-2'], expected: 3);
        $result = app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['count_mismatch']);
        $this->assertStringContainsString('the tab only has 2 row(s) for that date', implode("\n", $result['log']));
    }

    /** Once the sheet is fixed and the SKUs top up, it stops complaining. */
    public function test_the_report_stops_once_the_count_agrees(): void
    {
        Queue::fake();
        $this->syncUser();
        $this->store();

        $this->fakeSheetWithSkus(['SKU-1', 'SKU-2'], expected: 3);
        app(ProductRequestSheetSyncService::class)->run(commit: true);

        // The third row's date is corrected on the sheet, so it now matches.
        $this->fakeSheetWithSkus(['SKU-1', 'SKU-2', 'SKU-3'], expected: 3);
        $result = app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->assertSame(1, $result['skus_added']);
        $this->assertSame(0, $result['count_mismatch']);
        $this->assertSame(3, ProductRequest::sole()->skus()->count());
    }

    /**
     * The master row counts rows; a request holds distinct SKUs. Where the whole
     * difference is the same SKU listed twice, nothing is missing — and warning
     * about it teaches people to ignore the warning.
     */
    public function test_repeated_skus_on_the_tab_are_not_reported_as_missing(): void
    {
        Queue::fake();
        $this->syncUser();
        $this->store();

        $drive = Mockery::mock(OneDriveService::class);
        $drive->shouldReceive('asServiceAccount')->andReturnSelf();
        $drive->shouldReceive('setUser')->andReturnSelf();
        $drive->shouldReceive('resolveShareItem')->andReturn(['driveId' => 'd', 'itemId' => 'i']);
        $drive->shouldReceive('worksheetValues')
            ->with('d', 'i', config('product_request_sync.master_worksheet'))
            ->andReturn([
                ['Request No', 'Request Date', 'Requested By', 'Department', 'Brand', 'Website', 'SKU Count'],
                ['18', '05-Aug-26', 'KAYCEE', 'LINGERIE', 'BELDONA', 'BS', '4'],
            ]);
        $drive->shouldReceive('worksheetValues')
            ->with('d', 'i', 'Lingerie')
            ->andReturn([
                ['Date', 'Brand Name', 'Item SKU'],
                ['05-Aug-26', 'BELDONA', 'B-1'],
                ['05-Aug-26', 'BELDONA', 'B-2'],
                ['05-Aug-26', 'BELDONA', 'B-1'],     // the same SKU again
                ['05-Aug-26', 'BELDONA', 'B-2'],
            ]);

        $this->app->instance(OneDriveService::class, $drive);

        $result = app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->assertSame(2, ProductRequest::sole()->skus()->count());
        $this->assertSame(0, $result['count_mismatch'], 'Four rows, two SKUs — nothing is missing.');
    }

    /**
     * Rows the master row counts that are genuinely not on the tab — the case
     * worth interrupting somebody about.
     */
    public function test_rows_missing_from_the_tab_are_reported_with_the_evidence(): void
    {
        Queue::fake();
        $this->syncUser();
        $this->store();

        $drive = Mockery::mock(OneDriveService::class);
        $drive->shouldReceive('asServiceAccount')->andReturnSelf();
        $drive->shouldReceive('setUser')->andReturnSelf();
        $drive->shouldReceive('resolveShareItem')->andReturn(['driveId' => 'd', 'itemId' => 'i']);
        $drive->shouldReceive('worksheetValues')
            ->with('d', 'i', config('product_request_sync.master_worksheet'))
            ->andReturn([
                ['Request No', 'Request Date', 'Requested By', 'Department', 'Brand', 'Website', 'SKU Count'],
                ['21', '05-Aug-26', 'KAYCEE', 'LINGERIE', 'COTTONREAL', 'BS', '4'],
            ]);
        $drive->shouldReceive('worksheetValues')
            ->with('d', 'i', 'Lingerie')
            ->andReturn([
                ['Date', 'Brand Name', 'Item SKU'],
                ['05-Aug-26', 'COTTONREAL', 'C-1'],
                ['05-Aug-26', 'COTTONREAL', 'C-2'],
                ['02-Jun-26', 'COTTONREAL', 'C-3'],      // an earlier request's row
                ['05-Aug-26', 'COTTONREAL', ''],          // a row with no SKU at all
            ]);

        $this->app->instance(OneDriveService::class, $drive);

        $result = app(ProductRequestSheetSyncService::class)->run(commit: true);
        $log    = implode("\n", $result['log']);

        $this->assertSame(1, $result['count_mismatch']);
        $this->assertStringContainsString('the tab only has 2 row(s) for that date', $log);
        $this->assertStringContainsString('2 row(s) the master row counts are not on the tab', $log);

        // And where the brand's other rows sit, so the reader can judge.
        $this->assertStringContainsString('2026-08-05 (2)', $log);
        $this->assertStringContainsString('2026-06-02 (1)', $log);
    }

    /** Matching the stated count says nothing, which is how it should read. */
    public function test_a_matching_sku_count_is_not_reported(): void
    {
        Queue::fake();
        $this->syncUser();
        $this->store();
        $this->fakeSheet(['SKU Count' => '2']);

        $result = app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->assertSame(0, $result['count_mismatch']);
    }

    // ── Edits made on the sheet after the request exists ─────────────────────

    /** Correcting the brand on the sheet should reach the request. */
    public function test_a_brand_changed_on_the_sheet_updates_the_request(): void
    {
        Queue::fake();
        $this->syncUser();
        $this->store();

        $this->fakeSheet(['Brand' => 'AMADAMARIA']);
        app(ProductRequestSheetSyncService::class)->run(commit: true);

        $request = ProductRequest::sole();
        $this->assertSame('AMADAMARIA', $request->brand);

        $this->fakeSheet(['Brand' => 'AMADA MARIA']);
        $result = app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->assertSame(1, $result['updated']);
        $this->assertSame('AMADA MARIA', $request->refresh()->brand);
        $this->assertTrue(
            $request->activities()->where('action', 'sheet_updated')->exists(),
            'A change from the sheet belongs on the record.',
        );
    }

    public function test_priority_and_launch_date_follow_the_sheet_too(): void
    {
        Queue::fake();
        $this->syncUser();
        $this->store();

        $this->fakeSheet(['Priority' => 'High', 'Requested Website Go-Live Date' => '20-Aug-26']);
        app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->fakeSheet(['Priority' => 'Low', 'Requested Website Go-Live Date' => '25-Aug-26']);
        app(ProductRequestSheetSyncService::class)->run(commit: true);

        $request = ProductRequest::sole()->refresh();
        $this->assertSame('low', $request->priority);
        $this->assertSame('2026-08-25', $request->online_launch_date->toDateString());
    }

    /**
     * The reason this is three-way rather than "the sheet always wins": somebody
     * correcting a request here must not have it undone by a spreadsheet.
     */
    public function test_a_change_made_here_is_not_overwritten_by_the_sheet(): void
    {
        Queue::fake();
        $this->syncUser();
        $this->store();

        $this->fakeSheet(['Brand' => 'AMADAMARIA']);
        app(ProductRequestSheetSyncService::class)->run(commit: true);

        // Corrected on the request itself.
        $request = ProductRequest::sole();
        $request->update(['brand' => 'AMADAMARIA (CORRECTED HERE)']);

        $this->fakeSheet(['Brand' => 'SOMETHING ELSE']);
        $result = app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['conflicts']);
        $this->assertSame('AMADAMARIA (CORRECTED HERE)', $request->refresh()->brand);
        $this->assertStringContainsString('left as it is', implode("\n", $result['log']));
    }

    /** Nothing changed on the sheet means nothing reported. */
    public function test_an_unchanged_sheet_row_updates_nothing(): void
    {
        Queue::fake();
        $this->syncUser();
        $this->store();

        $this->fakeSheet();
        app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->fakeSheet();
        $result = app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['conflicts']);
    }

    /**
     * Everything imported before this existed has no snapshot, so the first run
     * records one and changes nothing — otherwise it could not tell an edited
     * sheet from an edited request and would overwrite on a guess.
     */
    public function test_a_request_with_no_snapshot_is_only_recorded_the_first_time(): void
    {
        Queue::fake();
        $this->syncUser();
        $this->store();

        $this->fakeSheet(['Brand' => 'AMADAMARIA']);
        app(ProductRequestSheetSyncService::class)->run(commit: true);

        // As it would look if it had been imported before snapshots existed.
        $request = ProductRequest::sole();
        $request->update(['sheet_snapshot' => null]);

        $this->fakeSheet(['Brand' => 'SOMETHING ELSE']);
        $result = app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->assertSame(0, $result['updated'], 'Nothing to compare against yet.');
        $this->assertSame('AMADAMARIA', $request->refresh()->brand);
        $this->assertNotNull($request->sheet_snapshot, 'But the snapshot is now recorded.');

        // From here on, a sheet edit is detectable.
        $this->fakeSheet(['Brand' => 'A THIRD NAME']);
        $result = app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->assertSame(1, $result['updated']);
        $this->assertSame('A THIRD NAME', $request->refresh()->brand);
    }

    // ── SKUs appended to a category tab after the request exists ─────────────

    /**
     * The case the team actually works in: ten SKUs today, ten more against the
     * same brand and date tomorrow. Without this the request keeps its original
     * ten and nobody ever sees the rest.
     */
    public function test_skus_added_to_the_sheet_later_are_added_to_the_request(): void
    {
        Queue::fake();
        $this->syncUser();
        $this->store();

        $this->fakeSheetWithSkus(['SKU-1', 'SKU-2']);
        app(ProductRequestSheetSyncService::class)->run(commit: true);

        $request = ProductRequest::sole();
        $this->assertSame(2, $request->skus()->count());

        // Two more appear on the tab under the same brand and date.
        $this->fakeSheetWithSkus(['SKU-1', 'SKU-2', 'SKU-3', 'SKU-4']);
        $result = app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->assertSame(0, $result['created'], 'It is the same request, not a new one.');
        $this->assertSame(2, $result['skus_added']);
        $this->assertSame(4, $request->refresh()->skus()->count());
        $this->assertSame(4, $request->total_skus, 'The roll-up has to follow, or the counts lie.');
        $this->assertStringContainsString('2 new SKU(s)', implode("\n", $result['log']));
    }

    /**
     * A SKU on the request but not on the sheet may have been added by hand, or
     * had its mapping recorded — a spreadsheet edit must not delete that.
     */
    public function test_a_sku_missing_from_the_sheet_is_never_removed(): void
    {
        Queue::fake();
        $this->syncUser();
        $this->store();

        $this->fakeSheetWithSkus(['SKU-1', 'SKU-2']);
        app(ProductRequestSheetSyncService::class)->run(commit: true);

        $request = ProductRequest::sole();

        $this->fakeSheetWithSkus(['SKU-1']);        // SKU-2 taken off the tab
        app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->assertSame(2, $request->refresh()->skus()->count());
    }

    public function test_a_dry_run_reports_the_new_skus_without_adding_them(): void
    {
        Queue::fake();
        $this->syncUser();
        $this->store();

        $this->fakeSheetWithSkus(['SKU-1']);
        app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->fakeSheetWithSkus(['SKU-1', 'SKU-2', 'SKU-3']);
        $result = app(ProductRequestSheetSyncService::class)->run(commit: false);

        $this->assertSame(2, $result['skus_added']);
        $this->assertSame(1, ProductRequest::sole()->skus()->count(), 'A dry run must change nothing.');
    }

    public function test_nothing_is_reported_when_the_sheet_has_not_changed(): void
    {
        Queue::fake();
        $this->syncUser();
        $this->store();

        $this->fakeSheetWithSkus(['SKU-1', 'SKU-2']);
        app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->fakeSheetWithSkus(['SKU-1', 'SKU-2']);
        $result = app(ProductRequestSheetSyncService::class)->run(commit: true);

        $this->assertSame(0, $result['skus_added']);
        $this->assertSame(1, $result['skipped_existing']);
    }

    /** One master row, one category tab, whatever SKUs it is given. */
    private function fakeSheetWithSkus(array $skus, ?int $expected = null): void
    {
        $drive = Mockery::mock(OneDriveService::class);
        $drive->shouldReceive('asServiceAccount')->andReturnSelf();
        $drive->shouldReceive('setUser')->andReturnSelf();
        $drive->shouldReceive('resolveShareItem')->andReturn(['driveId' => 'd', 'itemId' => 'i']);
        $drive->shouldReceive('worksheetValues')
            ->with('d', 'i', config('product_request_sync.master_worksheet'))
            ->andReturn([
                ['Request No', 'Request Date', 'Requested By', 'Department', 'Brand', 'Website', 'SKU Count'],
                ['17', '05-Aug-26', 'KAYCEE', 'LINGERIE', 'AMADAMARIA', 'BS', (string) ($expected ?? count($skus))],
            ]);
        $drive->shouldReceive('worksheetValues')
            ->with('d', 'i', 'Lingerie')
            ->andReturn(array_merge(
                [['Date', 'Brand Name', 'Item SKU']],
                array_map(fn ($sku) => ['05-Aug-26', 'AMADAMARIA', $sku], $skus),
            ));

        $this->app->instance(OneDriveService::class, $drive);
    }

    public function test_a_dry_run_creates_nothing(): void
    {
        Queue::fake();
        $this->syncUser();
        $this->store();
        $this->fakeSheet();

        $result = app(ProductRequestSheetSyncService::class)->run(commit: false);

        $this->assertSame(0, ProductRequest::count());
        $this->assertSame(0, $result['created']);
        Queue::assertNothingPushed();
    }
}
