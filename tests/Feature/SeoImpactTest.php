<?php

namespace Tests\Feature;

use App\Jobs\MeasureSeoImpactJob;
use App\Models\SeoContentPush;
use App\Models\Store;
use App\Models\User;
use App\Services\SearchConsoleService;
use App\Services\SeoImpactService;
use App\Services\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * The half of the SEO work that says whether any of it mattered: organic
 * sessions to a product's URL before the content was rewritten, against after.
 *
 * What is worth holding still is the arithmetic being honest — a page nobody
 * visited must not read as a flat result, and a push must not be graded before
 * Google has had time to recrawl it.
 */
class SeoImpactTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        // is_active matters: an inactive user is bounced to the login screen
        // and every assertion below then reads as a routing failure.
        return User::create([
            'name'           => 'SEO Analyst',
            'email'          => 'impact@example.test',
            'password'       => 'password',
            'is_active'      => true,
            'perm_seo_audit' => true,
        ]);
    }

    private function storeFor(User $user, ?string $propertyId = '12345', ?string $siteUrl = null): Store
    {
        return Store::create([
            'name'                 => 'Test Store',
            'shopify_domain'       => 'test.myshopify.com',
            'shopify_access_token' => 'shpat_test',
            'ga4_property_id'      => $propertyId,
            'gsc_site_url'         => $siteUrl,
            'user_id'              => $user->id,
        ]);
    }

    private function push(User $user, Store $store, array $overrides = []): SeoContentPush
    {
        return SeoContentPush::create(array_merge([
            'user_id'       => $user->id,
            'store_id'      => $store->id,
            'product_id'    => '1',
            'handle'        => 'linen-shirt',
            'product_title' => 'Relaxed Linen Shirt',
            'meta_title'    => 'Relaxed Linen Shirt with Camp Collar',
            'pushed_at'     => now()->subDays(SeoContentPush::SETTLE_DAYS + 1),
        ], $overrides));
    }

    /** A GA4 landing-page report, as batchRunReports returns one. */
    private function report(array $pathsToSessions): array
    {
        return ['rows' => array_map(fn ($path, $sessions) => [
            'dimensionValues' => [['value' => $path]],
            'metricValues'    => [['value' => (string) $sessions]],
        ], array_keys($pathsToSessions), $pathsToSessions)];
    }

    /**
     * A Search Console search-analytics response, as the API returns one.
     *
     * @param  array<string, array{0:int,1:int,2:float}>  $pages  url => [impressions, clicks, position]
     */
    private function searchRows(array $pages): array
    {
        return array_map(fn ($url, $figures) => [
            'keys'        => [$url],
            'impressions' => $figures[0],
            'clicks'      => $figures[1],
            'position'    => $figures[2],
        ], array_keys($pages), $pages);
    }

    /** Runs the measurement with Google and Shopify all faked out. */
    private function measure(
        array $before,
        array $after,
        ?ShopifyService $shopify = null,
        ?array $searchBefore = null,
        ?array $searchAfter = null,
    ): void {
        $service = new SeoImpactService(
            fn (string $propertyId, array $requests) => [$this->report($before), $this->report($after)]
        );

        // Two calls, before-window then after-window, in that order.
        $windows = [$this->searchRows($searchBefore ?? []), $this->searchRows($searchAfter ?? [])];
        $console = new SearchConsoleService(function (string $site, array $request) use (&$windows) {
            return array_shift($windows) ?? [];
        });

        $job = new class($service, $console, $shopify) extends MeasureSeoImpactJob {
            public function __construct(private $service, private $console, private $shopify) {}

            protected function impactService(): SeoImpactService
            {
                return $this->service;
            }

            protected function searchConsoleService(): SearchConsoleService
            {
                return $this->console;
            }

            protected function shopifyFor(Store $store): ShopifyService
            {
                return $this->shopify ?? Mockery::mock(ShopifyService::class)
                    ->shouldReceive('getProductHandles')->andReturn([])->getMock();
            }
        };

        $job->handle();
    }

    public function test_sessions_either_side_of_the_push_are_recorded_with_the_change(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user);
        $push  = $this->push($user, $store);

        $this->measure(
            before: ['/products/linen-shirt' => 100],
            after:  ['/products/linen-shirt' => 150],
        );

        $push->refresh();

        $this->assertSame('measured', $push->measurement_status);
        $this->assertSame(100, $push->sessions_before);
        $this->assertSame(150, $push->sessions_after);
        $this->assertSame(50.0, $push->changePercent());
    }

    public function test_the_same_product_reached_by_several_urls_is_counted_once(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user);
        $push  = $this->push($user, $store);

        // One product, four rows: a tracking parameter, a collection prefix, a
        // trailing slash, and the bare URL. They are the same page to a
        // merchant, and picking whichever arrives first would undercount it.
        $this->measure(
            before: [
                '/products/linen-shirt'                     => 40,
                '/products/linen-shirt?utm_source=news'     => 10,
                '/collections/shirts/products/linen-shirt'  => 25,
                '/products/linen-shirt/'                    => 5,
            ],
            after: ['/products/linen-shirt' => 100],
        );

        $this->assertSame(80, $push->refresh()->sessions_before);
    }

    public function test_a_page_nobody_visited_is_reported_as_no_data_not_as_no_change(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user);
        $push  = $this->push($user, $store);

        $this->measure(before: ['/products/something-else' => 10], after: []);

        $push->refresh();

        // Zero to zero is not a flat result — it means nobody was ever there to
        // count, which is a different statement about the rewrite.
        $this->assertSame('no_data', $push->measurement_status);
        $this->assertNull($push->changePercent());
        $this->assertSame(
            'This URL had no organic sessions and no search impressions in either window.',
            $push->measurement_note,
        );
    }

    public function test_a_push_from_zero_sessions_reports_the_counts_but_no_percentage(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user);
        $push  = $this->push($user, $store);

        $this->measure(before: [], after: ['/products/linen-shirt' => 30]);

        $push->refresh();

        $this->assertSame('measured', $push->measurement_status);
        $this->assertSame(0, $push->sessions_before);
        $this->assertSame(30, $push->sessions_after);
        // A rise from nothing is not an infinite improvement, it is unmeasurable.
        $this->assertNull($push->changePercent());
    }

    public function test_a_push_that_has_not_settled_is_left_alone(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user);
        $push  = $this->push($user, $store, [
            'pushed_at' => now()->subDays(SeoContentPush::SETTLE_DAYS - 2),
        ]);

        $this->measure(
            before: ['/products/linen-shirt' => 100],
            after:  ['/products/linen-shirt' => 400],
        );

        $push->refresh();

        // Graded too early, this would mostly measure how long Google took to
        // come back and look at the page.
        $this->assertSame('pending', $push->measurement_status);
        $this->assertNull($push->sessions_after);
    }

    public function test_a_missing_handle_is_resolved_from_shopify_before_measuring(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user);
        $push  = $this->push($user, $store, ['handle' => null, 'product_id' => '77']);

        $shopify = Mockery::mock(ShopifyService::class);
        $shopify->shouldReceive('getProductHandles')->once()->with(['77'])
            ->andReturn(['77' => 'linen-shirt']);

        $this->measure(
            before: ['/products/linen-shirt' => 20],
            after:  ['/products/linen-shirt' => 25],
            shopify: $shopify,
        );

        $push->refresh();

        $this->assertSame('linen-shirt', $push->handle);
        $this->assertSame('measured', $push->measurement_status);
    }

    public function test_a_product_deleted_since_the_push_is_closed_off_rather_than_retried_forever(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user);
        $push  = $this->push($user, $store, ['handle' => null, 'product_id' => '404']);

        $shopify = Mockery::mock(ShopifyService::class);
        $shopify->shouldReceive('getProductHandles')->once()->andReturn([]);

        $this->measure(before: [], after: [], shopify: $shopify);

        $push->refresh();

        $this->assertSame('no_data', $push->measurement_status);
        $this->assertNotNull($push->measured_at);
    }

    public function test_a_store_with_no_analytics_property_is_told_so_rather_than_left_pending(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user, propertyId: null);
        $push  = $this->push($user, $store);

        $this->measure(before: [], after: []);

        $push->refresh();

        $this->assertSame('no_data', $push->measurement_status);
        $this->assertStringContainsString('Neither a GA4 property nor a Search Console site', $push->measurement_note);
    }

    public function test_the_report_totals_sessions_rather_than_averaging_percentages(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user);
        $user->update(['active_store_id' => $store->id]);

        // A busy page that slipped, and a quiet one that tripled. Averaging the
        // two percentages would report a triumph; the sessions say otherwise.
        $this->push($user, $store, [
            'product_id' => '1', 'handle' => 'busy',
            'sessions_before' => 1000, 'sessions_after' => 900,
            'measurement_status' => 'measured', 'measured_at' => now(),
        ]);
        $this->push($user, $store, [
            'product_id' => '2', 'handle' => 'quiet',
            'sessions_before' => 1, 'sessions_after' => 4,
            'measurement_status' => 'measured', 'measured_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('seo-audit.impact'));

        $response->assertOk();
        $response->assertSee('1,001');  // sessions before
        $response->assertSee('904');    // sessions after
        $response->assertSee('-9.7%');  // the honest headline
    }

    /**
     * The recording itself lives in AiContentController::push, which builds its
     * own ShopifyService and so cannot be exercised without a live store. What
     * is covered here is the part that decides when a recorded push is ripe.
     */
    public function test_a_recorded_push_becomes_due_only_once_it_has_settled(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user);

        $this->push($user, $store, ['product_id' => 'settled']);
        $this->push($user, $store, [
            'product_id' => 'fresh',
            'pushed_at'  => now()->subDay(),
        ]);

        $due = SeoContentPush::readyToMeasure()->get();

        $this->assertCount(1, $due);
        $this->assertSame('settled', $due->first()->product_id);
    }

    public function test_search_console_figures_are_recorded_alongside_the_sessions(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user, siteUrl: 'sc-domain:test.com');
        $push  = $this->push($user, $store);

        $this->measure(
            before: ['/products/linen-shirt' => 100],
            after:  ['/products/linen-shirt' => 150],
            searchBefore: ['https://test.com/products/linen-shirt' => [10000, 200, 8.4]],
            searchAfter:  ['https://test.com/products/linen-shirt' => [10000, 300, 8.2]],
        );

        $push->refresh();

        $this->assertSame(10000, $push->impressions_before);
        $this->assertSame(300, $push->clicks_after);
        $this->assertEquals(2.0, $push->ctr_before);
        $this->assertEquals(3.0, $push->ctr_after);

        // The same impressions, more clicks: the description did its job
        // without the page moving. Sessions alone could not have told those
        // two explanations apart.
        $this->assertSame(1.0, $push->ctrChangePoints());
    }

    public function test_a_page_that_climbed_reports_a_positive_move_despite_the_number_falling(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user, siteUrl: 'sc-domain:test.com');
        $push  = $this->push($user, $store);

        $this->measure(
            before: [],
            after:  [],
            searchBefore: ['https://test.com/products/linen-shirt' => [500, 5, 12.0]],
            searchAfter:  ['https://test.com/products/linen-shirt' => [900, 20, 7.5]],
        );

        // Search Console counts position downwards, so 12th to 7.5th is a rise
        // of 4.5 places, not a fall.
        $this->assertSame(4.5, $push->refresh()->positionChange());
    }

    public function test_a_page_shown_but_never_clicked_still_counts_as_measured(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user, siteUrl: 'sc-domain:test.com');
        $push  = $this->push($user, $store);

        $this->measure(
            before: [],
            after:  [],
            searchBefore: ['https://test.com/products/linen-shirt' => [400, 0, 30.0]],
            searchAfter:  ['https://test.com/products/linen-shirt' => [600, 0, 28.0]],
        );

        $push->refresh();

        // Nobody arrived, so Analytics has nothing — but the page was put in
        // front of a thousand people, and "shown and ignored" is a finding
        // rather than an absence of one.
        $this->assertSame('measured', $push->measurement_status);
        $this->assertSame(0, $push->sessions_after);
        $this->assertSame(600, $push->impressions_after);
    }

    public function test_average_position_is_weighted_by_impressions_when_urls_are_folded(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user, siteUrl: 'sc-domain:test.com');
        $push  = $this->push($user, $store);

        $this->measure(
            before: [],
            after:  [],
            searchBefore: [
                'https://test.com/products/linen-shirt'            => [9900, 100, 5.0],
                'https://test.com/products/linen-shirt?variant=2'  => [100,  1,   95.0],
            ],
            searchAfter: ['https://test.com/products/linen-shirt' => [10000, 120, 5.0]],
        );

        // A straight mean of 5 and 95 would report 50th place for a page that
        // essentially always sits 5th.
        $this->assertEquals(5.9, $push->refresh()->position_before);
    }

    /**
     * Search Console has no data for its last few days and answers with a
     * partial window rather than an error, which would read as a collapse in
     * traffic. The settle period has to clear both the measurement window and
     * that reporting lag, and the job carries a guard for when it does not.
     *
     * The guard cannot fire while these constants hold, so what is pinned here
     * is the relationship between them: retune one and this fails rather than
     * quietly starting to compare a full window against a half-empty one.
     */
    public function test_the_settle_period_clears_both_the_window_and_search_consoles_lag(): void
    {
        $this->assertGreaterThan(
            SeoContentPush::WINDOW_DAYS + SearchConsoleService::LAG_DAYS,
            SeoContentPush::SETTLE_DAYS,
        );
    }

    public function test_a_store_with_only_search_console_is_still_measured(): void
    {
        $user  = $this->operator();
        $store = $this->storeFor($user, propertyId: null, siteUrl: 'sc-domain:test.com');
        $push  = $this->push($user, $store);

        $this->measure(
            before: [],
            after:  [],
            searchBefore: ['https://test.com/products/linen-shirt' => [1000, 30, 9.0]],
            searchAfter:  ['https://test.com/products/linen-shirt' => [1000, 45, 9.0]],
        );

        $push->refresh();

        $this->assertSame('measured', $push->measurement_status);
        $this->assertSame(1.5, $push->ctrChangePoints());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
