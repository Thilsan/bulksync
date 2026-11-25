<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Services\GoogleAdsViaGa4Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Google Ads tab: spend, clicks, CPC, ROAS and revenue per website, read
 * through each website's GA4 property. The reporter is stubbed, so nothing
 * here reaches Google.
 */
class GoogleAdsTabTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-25 10:00:00');

        $this->admin = User::create([
            'name' => 'Ada Okonkwo', 'email' => 'ada@example.test',
            'password' => 'password', 'is_active' => true, 'is_super_admin' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function store(string $name, ?string $property): Store
    {
        return Store::create([
            'name' => $name, 'shopify_domain' => str($name)->slug() . '.myshopify.com',
            'ga4_property_id' => $property,
        ]);
    }

    /**
     * Answers each request by what it asks for, not by position, so these
     * tests hold however the batch is ordered.
     *
     * Totals rows: [cost, clicks, impressions, cpc, roas, keyEvents, revenue].
     *
     * @param  array{0: list<string>, 1?: list<string>}  $totals  current, then previous
     */
    private function reporter(array $current, ?array $previous = null, array $campaigns = [], array $trend = [], array &$seen = []): callable
    {
        return function (string $property, array $requests) use ($current, $previous, $campaigns, $trend, &$seen) {
            $seen[$property] = $requests;

            $currentStart = $requests[0]['dateRanges'][0]['startDate'];

            return array_map(function (array $r) use ($current, $previous, $campaigns, $trend, $currentStart) {
                $dimension = $r['dimensions'][0]['name'] ?? null;

                return match ($dimension) {
                    null => ['rows' => [['metricValues' => array_map(
                        fn ($v) => ['value' => (string) $v],
                        $r['dateRanges'][0]['startDate'] === $currentStart ? $current : ($previous ?? array_fill(0, 7, '0')),
                    )]]],
                    'sessionGoogleAdsCampaignName' => ['rows' => array_map(fn ($c) => [
                        'dimensionValues' => [['value' => $c[0]]],
                        'metricValues'    => array_map(fn ($v) => ['value' => (string) $v], \array_slice($c, 1)),
                    ], $campaigns)],
                    default => ['rows' => array_map(fn ($day, $vals) => [
                        'dimensionValues' => [['value' => $day]],
                        'metricValues'    => [['value' => (string) $vals[0]], ['value' => (string) $vals[1]]],
                    ], array_keys($trend), $trend)],
                };
            }, $requests);
        };
    }

    private function useReporter(callable $reporter): void
    {
        $this->app->instance(GoogleAdsViaGa4Service::class, new GoogleAdsViaGa4Service($reporter));
    }

    // ── Shaping ──────────────────────────────────────────────────────────────

    public function test_totals_roas_and_the_comparison_are_shaped_from_the_reports(): void
    {
        $store = $this->store('Blue Salon', '307311411');

        $service = new GoogleAdsViaGa4Service($this->reporter(
            current:  ['500', '250', '10000', '2', '4', '12', '2000'],
            previous: ['400', '200', '8000', '2', '2.5', '6', '1000'],
        ));

        [$row] = $service->forStores(collect([$store]), Carbon::parse('2026-11-01'), Carbon::parse('2026-11-25'));

        $this->assertSame('ok', $row['status']);
        $this->assertSame('QAR', $row['currency']);
        $this->assertSame(500.0, $row['current']['cost']);
        $this->assertSame(250, $row['current']['clicks']);
        $this->assertSame(10000, $row['current']['impressions']);
        $this->assertSame(2.0, $row['current']['cpc']);
        $this->assertSame(4.0, $row['current']['roas']);
        $this->assertSame(12.0, $row['current']['conversions']);
        $this->assertSame(2000.0, $row['current']['revenue']);

        $this->assertEqualsWithDelta(25.0, $row['deltas']['cost'], 0.001);
        $this->assertEqualsWithDelta(25.0, $row['deltas']['clicks'], 0.001);
        $this->assertEqualsWithDelta(0.0, $row['deltas']['cpc'], 0.001);
        $this->assertEqualsWithDelta(60.0, $row['deltas']['roas'], 0.001);
        $this->assertEqualsWithDelta(100.0, $row['deltas']['revenue'], 0.001);
    }

    /** GA4 left them out: the same divisions are done here instead. */
    public function test_cpc_and_roas_fall_back_to_the_divisions_when_ga4_sends_none(): void
    {
        $store = $this->store('Blue Salon', '307311411');

        $service = new GoogleAdsViaGa4Service($this->reporter(current: ['300', '150', '5000', '0', '0', '3', '900']));

        [$row] = $service->forStores(collect([$store]), Carbon::parse('2026-11-01'), Carbon::parse('2026-11-25'));

        $this->assertSame(2.0, $row['current']['cpc']);
        $this->assertSame(3.0, $row['current']['roas']);
    }

    /**
     * Combined ROAS is total revenue over total spend, not an average of each
     * store's ROAS — a small store with a lucky week would otherwise move the
     * headline as much as the biggest spender.
     */
    public function test_the_combined_total_sums_spend_and_divides_afresh(): void
    {
        $a = $this->store('Blue Salon', '111111111');
        $b = $this->store('Mosafer', '222222222');

        $service = new GoogleAdsViaGa4Service(function (string $property, array $requests) {
            $values = $property === '111111111'
                ? ['900', '300', '9000', '3', '5', '10', '4500']
                : ['100', '100', '1000', '1', '1', '1', '100'];

            return array_map(fn ($r) => isset($r['dimensions'])
                ? ['rows' => []]
                : ['rows' => [['metricValues' => array_map(fn ($v) => ['value' => $v], $values)]]], $requests);
        });

        $rows   = $service->forStores(collect([$a, $b]), Carbon::parse('2026-11-01'), Carbon::parse('2026-11-25'));
        $totals = $service->totals($rows);

        $this->assertSame(1000.0, $totals['current']['cost']);
        $this->assertSame(400, $totals['current']['clicks']);
        $this->assertSame(4600.0, $totals['current']['revenue']);
        $this->assertSame(2.5, $totals['current']['cpc']);
        $this->assertSame(4.6, $totals['current']['roas']);   // not (5 + 1) / 2
        $this->assertSame(2, $totals['reporting']);
    }

    public function test_top_campaigns_are_read_back_in_the_order_ga4_ranked_them(): void
    {
        $store = $this->store('Blue Salon', '307311411');
        $seen  = [];

        $service = new GoogleAdsViaGa4Service($this->reporter(
            current: ['500', '250', '10000', '2', '4', '12', '2000'],
            campaigns: [
                ['Black Friday – Search', '320.5', '140', '7', '1300'],
                ['Brand – Exact', '120', '80', '4', '600'],
                ['', '59.5', '30', '1', '100'],
            ],
            seen: $seen,
        ));

        [$row] = $service->forStores(collect([$store]), Carbon::parse('2026-11-01'), Carbon::parse('2026-11-25'));

        $this->assertSame(['Black Friday – Search', 'Brand – Exact', 'Unknown'], array_column($row['campaigns'], 'name'));
        $this->assertSame(320.5, $row['campaigns'][0]['cost']);
        $this->assertSame(140, $row['campaigns'][0]['clicks']);
        $this->assertSame(7.0, $row['campaigns'][0]['conversions']);
        $this->assertSame(1300.0, $row['campaigns'][0]['revenue']);

        // Asked of GA4 as the top ten by cost.
        $asked = collect($seen['307311411'])->firstWhere('dimensions.0.name', 'sessionGoogleAdsCampaignName');
        $this->assertSame(10, $asked['limit']);
        $this->assertSame('advertiserAdCost', $asked['orderBys'][0]['metric']['metricName']);
        $this->assertTrue($asked['orderBys'][0]['desc']);
    }

    /**
     * Every report is narrowed to sessions from an ad click, so revenue is
     * the ads' share and not the whole website's — and none of them carries
     * the visitor-sessions country exclusion, which cost data cannot join.
     */
    public function test_every_ad_report_is_narrowed_to_ads_and_never_filtered_by_country(): void
    {
        $store = $this->store('Blue Salon', '307311411');   // the property with excluded countries
        $seen  = [];

        $service = new GoogleAdsViaGa4Service($this->reporter(current: ['1', '1', '1', '1', '1', '1', '1'], seen: $seen));
        $service->forStores(collect([$store]), Carbon::parse('2026-11-01'), Carbon::parse('2026-11-25'));

        $this->assertCount(4, $seen['307311411']);   // current, previous, campaigns, trend — one batch

        foreach ($seen['307311411'] as $request) {
            $this->assertSame('sessionGoogleAdsCampaignName', $request['dimensionFilter']['notExpression']['filter']['fieldName']);
            $this->assertSame('(not set)', $request['dimensionFilter']['notExpression']['filter']['stringFilter']['value']);
            $this->assertStringNotContainsString('country', json_encode($request));
        }
    }

    /** The trend fills the days GA4 left out, so a paused week shows as one. */
    public function test_the_trend_fills_days_with_nothing_on_them(): void
    {
        $store = $this->store('Blue Salon', '307311411');

        $service = new GoogleAdsViaGa4Service($this->reporter(
            current: ['30', '10', '100', '3', '2', '1', '60'],
            trend: ['20261101' => ['10', '20'], '20261103' => ['20', '40']],
        ));

        [$row] = $service->forStores(collect([$store]), Carbon::parse('2026-11-01'), Carbon::parse('2026-11-03'));

        $this->assertSame(['20261101', '20261102', '20261103'], array_column($row['trend'], 'key'));
        $this->assertSame([10.0, 0.0, 20.0], array_column($row['trend'], 'cost'));
        $this->assertSame([20.0, 0.0, 40.0], array_column($row['trend'], 'revenue'));
    }

    /** "All time" has no preceding period, so none is asked for. */
    public function test_no_previous_period_is_asked_for_when_comparison_is_off(): void
    {
        $store = $this->store('Blue Salon', '307311411');
        $seen  = [];

        $service = new GoogleAdsViaGa4Service($this->reporter(current: ['5', '1', '1', '5', '1', '0', '5'], seen: $seen));
        [$row] = $service->forStores(collect([$store]), Carbon::parse('2019-01-01'), Carbon::parse('2026-11-25'), compare: false);

        $this->assertCount(3, $seen['307311411']);
        $this->assertNull($row['previous']);
        $this->assertNull($row['deltas']['cost']);
        // Seven years drawn by month, not as 2,500 days.
        $this->assertSame('yearMonth', collect($seen['307311411'])->last()['dimensions'][0]['name']);
        $this->assertSame('201901', $row['trend'][0]['key']);
    }

    // ── Websites that cannot answer ──────────────────────────────────────────

    public function test_a_store_with_no_property_is_reported_and_google_is_not_asked(): void
    {
        $store  = $this->store('Gold Gourmet', null);
        $called = false;

        $service = new GoogleAdsViaGa4Service(function () use (&$called) {
            $called = true;

            return [];
        });

        [$row] = $service->forStores(collect([$store]), Carbon::parse('2026-11-01'), Carbon::parse('2026-11-25'));

        $this->assertSame('not_configured', $row['status']);
        $this->assertSame('Gold Gourmet', $row['store']);
        $this->assertFalse($called);
    }

    /**
     * A property with no Ads account linked answers perfectly well — with
     * zeros. That is "not linked", not an error and not a spend of nothing.
     */
    public function test_a_linked_property_with_no_ad_cost_reads_as_not_linked(): void
    {
        $this->store('Mosafer', '222222222');
        $this->useReporter($this->reporter(current: ['0', '0', '0', '0', '0', '0', '0']));

        $response = $this->actingAs($this->admin)->get('/management-dashboard?tab=google_ads');

        $response->assertOk();
        $response->assertSee('Mosafer');
        $response->assertSee('Google Ads not linked to this GA4 property');
        $response->assertDontSee('Top campaigns by spend');
    }

    /** It spent last period and nothing this one: plainly linked, just quiet. */
    public function test_no_spend_this_period_after_spend_last_period_is_not_called_unlinked(): void
    {
        $store = $this->store('Mosafer', '222222222');

        $service = new GoogleAdsViaGa4Service($this->reporter(
            current:  ['0', '0', '0', '0', '0', '0', '0'],
            previous: ['250', '90', '4000', '2.7', '3', '2', '750'],
        ));

        [$row] = $service->forStores(collect([$store]), Carbon::parse('2026-11-01'), Carbon::parse('2026-11-25'));

        $this->assertSame('no_spend', $row['status']);
    }

    public function test_a_failing_property_is_reported_without_taking_the_others_down(): void
    {
        $this->store('Blue Salon', '111111111');
        $this->store('Mosafer', '999999999');

        $working = $this->reporter(
            current: ['500', '250', '10000', '2', '4', '12', '2000'],
            campaigns: [['Black Friday – Search', '320', '140', '7', '1300']],
        );

        $this->useReporter(function (string $property, array $requests) use ($working) {
            if ($property === '999999999') {
                throw new \RuntimeException('GA4 report failed (HTTP 403): User does not have sufficient permissions for this property.', 403);
            }

            return $working($property, $requests);
        });

        $response = $this->actingAs($this->admin)->get('/management-dashboard?tab=google_ads');

        $response->assertOk();
        $response->assertSee('Black Friday – Search');
        $response->assertSee('Mosafer');
        $response->assertSee('No access to this property');
        $response->assertSee('1 of 2 websites with Google Ads');
    }

    // ── The tab ──────────────────────────────────────────────────────────────

    public function test_the_tab_renders_totals_the_comparison_and_campaigns(): void
    {
        $this->store('Blue Salon', '307311411');
        $this->useReporter($this->reporter(
            current:  ['1500', '600', '20000', '2.5', '4', '18', '6000'],
            previous: ['1200', '500', '15000', '2.4', '2.5', '10', '3000'],
            campaigns: [
                ['Black Friday – Search', '900', '400', '12', '4200'],
                ['Brand – Exact', '600', '200', '6', '1800'],
            ],
            trend: ['20261101' => ['50', '200']],
        ));

        $response = $this->actingAs($this->admin)->get('/management-dashboard?tab=google_ads');

        $response->assertOk();
        $response->assertSee('data-tab="google_ads"', false);
        $response->assertDontSee('We are working on this.');
        $response->assertSee('data-ads-totals', false);
        $response->assertSee('QAR 1,500');           // spend
        $response->assertSee('600');                 // clicks
        $response->assertSee('QAR 2.50');            // CPC
        $response->assertSee('4.00×');               // ROAS
        $response->assertSee('QAR 6,000');           // revenue from ads
        $response->assertSee('▲ 25.0%');             // spend vs the previous period
        $response->assertSee('vs the previous 25 days');
        $response->assertSee('Amounts in QAR');
        $response->assertSee('Black Friday – Search');
        $response->assertSee('Brand – Exact');
        $response->assertSee('Daily spend and revenue from ads');
    }

    /** Submitting the date bar has to land back on this tab. */
    public function test_the_tab_carries_its_own_date_filter(): void
    {
        $this->store('Blue Salon', '307311411');
        $this->useReporter($this->reporter(current: ['10', '5', '50', '2', '1', '0', '10']));

        $this->actingAs($this->admin)
            ->get('/management-dashboard?tab=google_ads&preset=custom&from=2026-08-01&to=2026-08-31')
            ->assertOk()
            ->assertSee('name="tab" value="google_ads"', false)
            ->assertSee('value="2026-08-01"', false);
    }

    // ── Who sees it ──────────────────────────────────────────────────────────

    /**
     * The same people as Visitor Sessions: the dashboard permission, and only
     * the websites they have been given.
     */
    public function test_staff_see_only_their_own_websites_and_need_the_dashboard_permission(): void
    {
        $mine   = $this->store('Blue Salon', '111111111');
        $theirs = $this->store('Mosafer', '222222222');

        $staff = User::create([
            'name' => 'Sam Rivera', 'email' => 'sam@example.test',
            'password' => 'password', 'is_active' => true, 'is_super_admin' => false,
        ]);
        $mine->users()->attach($staff);

        $this->useReporter($this->reporter(current: ['10', '5', '50', '2', '1', '0', '10']));

        $this->actingAs($staff)->get('/management-dashboard?tab=google_ads')->assertForbidden();

        $staff->update(['perm_orders_dashboard' => true]);

        $this->actingAs($staff->fresh())->get('/management-dashboard?tab=google_ads')
            ->assertOk()
            ->assertSee('Blue Salon')
            ->assertDontSee('Mosafer');
    }
}
