<?php

namespace Tests\Feature;

use App\Jobs\MeasureSeoImpactJob;
use App\Models\SeoContentPush;
use App\Models\Store;
use App\Models\User;
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

    private function storeFor(User $user, ?string $propertyId = '12345'): Store
    {
        return Store::create([
            'name'                 => 'Test Store',
            'shopify_domain'       => 'test.myshopify.com',
            'shopify_access_token' => 'shpat_test',
            'ga4_property_id'      => $propertyId,
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

    /** Runs the measurement with GA4 and Shopify both faked out. */
    private function measure(array $before, array $after, ?ShopifyService $shopify = null): void
    {
        $service = new SeoImpactService(
            fn (string $propertyId, array $requests) => [$this->report($before), $this->report($after)]
        );

        $job = new class($service, $shopify) extends MeasureSeoImpactJob {
            public function __construct(private $service, private $shopify) {}

            protected function impactService(): SeoImpactService
            {
                return $this->service;
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
        $this->assertSame('No organic sessions to this URL in either window.', $push->measurement_note);
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
        $this->assertStringContainsString('No GA4 property', $push->measurement_note);
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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
