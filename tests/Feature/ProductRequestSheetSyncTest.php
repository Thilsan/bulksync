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

    /** The same department typed short still finds its tab. */
    public function test_a_short_department_name_still_maps(): void
    {
        $this->assertSame(
            config('product_request_sync.department_map.LEATHER GOODS'),
            config('product_request_sync.department_map.LEATHER'),
        );
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
