<?php

namespace App\Services;

use App\Models\Store;
use App\Support\OrdersSummary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Google Ads spend and what it brought in, per website, read through GA4.
 *
 * Not the Google Ads API. Each store's Ads account is linked to its GA4
 * property with auto-tagging on, and GA4 then carries the cost, clicks and
 * impressions beside its own sessions and revenue. That means one key, one
 * client and one permission model for the whole dashboard, rather than a
 * second Google integration with its own developer token and OAuth consent.
 *
 * The price is that GA4's view of an ad is session-scoped: revenue and
 * conversions here are what GA4 credits to sessions that arrived from a
 * Google Ads click, not what the Ads account reports under its own
 * attribution. The two will differ, and the card says whose numbers they are.
 *
 * Built the same way as Ga4AnalyticsService, and for the same reason a
 * website that cannot answer is reported rather than dropped.
 */
class GoogleAdsViaGa4Service
{
    private const CACHE_TTL_MINUTES = 15;

    /** How many campaigns fit on a card. */
    private const TOP_CAMPAIGNS = 10;

    /**
     * GA4 reports cost in the property's own currency and the API does not
     * say which that is. Every property behind these stores is set to QAR;
     * the card names it so a figure is never read without a unit.
     */
    public const CURRENCY = 'QAR';

    /** Longer ranges are trended by month, or "All time" draws 2,500 slivers. */
    private const DAILY_TREND_MAX_DAYS = 92;

    /**
     * The value sessionGoogleAdsCampaignName takes for every session that did
     * not come from a Google Ads click: organic, direct, email, social.
     *
     * Excluded from every report here. Cost only ever lands on a real
     * campaign, so this leaves spend untouched and narrows revenue and
     * conversions to the Ads-driven share. Without it the totals row would
     * divide the whole website's revenue by the ad spend and call it ROAS.
     */
    private const NOT_FROM_ADS = '(not set)';

    /** @var callable(string, array): array */
    private $reporter;

    /** Same seam as Ga4AnalyticsService: tests answer with a canned payload. */
    public function __construct(?callable $reporter = null)
    {
        $this->reporter = $reporter ?? fn (string $propertyId, array $requests) => app(Ga4Client::class)->batchReport($propertyId, $requests);
    }

    /**
     * @param  Collection<int, Store>  $stores
     * @param  bool  $compare  false for "All time", which has no preceding period
     */
    public function forStores(Collection $stores, Carbon $from, Carbon $to, bool $compare = true): array
    {
        return $stores->map(fn (Store $store) => $this->forStore($store, $from, $to, $compare))->all();
    }

    /**
     * The combined figures across every website that reported, for the
     * headline above the cards.
     *
     * Only stores with spend are counted. A store that is not linked, or that
     * could not answer, is not a store that spent nothing, and summing it in
     * as zero would say it was.
     */
    public function totals(array $rows): array
    {
        $ok = collect($rows)->where('status', 'ok');

        $sum = fn (string $period) => $ok->reduce(function (?array $carry, array $row) use ($period) {
            $t = $row[$period] ?? null;

            if ($t === null) {
                return $carry;
            }

            $carry ??= ['cost' => 0.0, 'clicks' => 0, 'impressions' => 0, 'conversions' => 0.0, 'revenue' => 0.0];

            foreach ($carry as $k => $v) {
                $carry[$k] = $v + $t[$k];
            }

            return $carry;
        });

        $current  = $this->ratios($sum('current') ?? ['cost' => 0.0, 'clicks' => 0, 'impressions' => 0, 'conversions' => 0.0, 'revenue' => 0.0]);
        $previous = $sum('previous');
        $previous = $previous ? $this->ratios($previous) : null;

        // Day by day across every store, so the combined trend is the sum of
        // the per-store ones rather than a separate question to Google.
        $trend = [];
        foreach ($ok as $row) {
            foreach ($row['trend'] as $point) {
                $trend[$point['key']] ??= ['key' => $point['key'], 'label' => $point['label'], 'cost' => 0.0, 'revenue' => 0.0];
                $trend[$point['key']]['cost']    += $point['cost'];
                $trend[$point['key']]['revenue'] += $point['revenue'];
            }
        }
        ksort($trend);

        return [
            'current'   => $current,
            'previous'  => $previous,
            'deltas'    => $this->deltas($current, $previous),
            'trend'     => array_values($trend),
            'currency'  => self::CURRENCY,
            'reporting' => $ok->count(),
            'total'     => count($rows),
        ];
    }

    private function forStore(Store $store, Carbon $from, Carbon $to, bool $compare): array
    {
        $base = ['store' => $store->name, 'store_id' => $store->id, 'currency' => self::CURRENCY];

        if (! $store->ga4_property_id) {
            return $base + [
                'status'  => 'not_configured',
                'message' => 'No GA4 property is set for this website.',
            ];
        }

        $key = sprintf(
            'google_ads_ga4.%d.%s.%s.%s',
            $store->id,
            $from->toDateString(),
            $to->toDateString(),
            $compare ? 'cmp' : 'solo',
        );

        try {
            $data = Cache::remember($key, now()->addMinutes(self::CACHE_TTL_MINUTES), function () use ($store, $from, $to, $compare) {
                $requests = $this->requests($from, $to, $compare);

                return $this->shape(
                    array_combine(
                        array_keys($requests),
                        // The API answers in request order, which is what
                        // lets the names be put back on the reports here.
                        array_pad(($this->reporter)($store->ga4_property_id, array_values($requests)), \count($requests), []),
                    ),
                    $from,
                    $to,
                );
            });
        } catch (\Throwable $e) {
            Log::warning("Google Ads via GA4 failed for store {$store->id}: " . $e->getMessage());

            return $base + Ga4AnalyticsService::describeFailure($e);
        }

        $current = $data['current'];

        // No cost, no clicks, no impressions: GA4 has nothing from Google Ads
        // for this property. Shown as that, not as a row of zeros, because a
        // zero spend reads as a decision somebody made.
        if ($current['cost'] <= 0 && $current['clicks'] === 0 && $current['impressions'] === 0) {
            // ...unless it spent in the period before. Then the link is plainly
            // there and nothing ran this time, which is a different sentence.
            if (($data['previous']['cost'] ?? 0) > 0) {
                return $base + [
                    'status'  => 'no_spend',
                    'message' => 'Google Ads is linked, but nothing was spent in this range.',
                ];
            }

            return $base + [
                'status'  => 'not_linked',
                'message' => 'GA4 holds no Google Ads cost for this range. Link the Ads account to this property in GA4 Admin → Product links, with auto-tagging on.',
            ];
        }

        return $base + $data + ['status' => 'ok'];
    }

    /**
     * The reports asked of every property, by name, in one batch.
     *
     * Deliberately not batched with Ga4AnalyticsService's reports, and
     * deliberately without its EXCLUDED_COUNTRIES filter. Google Ads cost is
     * imported per campaign and day with no visitor country attached, so the
     * advertiser* metrics are not compatible with a country dimension or
     * filter, and one incompatible report fails every report in its batch.
     * Even where a property would accept it, filtering revenue by country
     * while the spend beside it stays whole would skew ROAS. The datacentre
     * traffic that filter exists for does not click paid ads, so it costs
     * these figures nothing. `php artisan ga4:ads-probe` checks all of this
     * against a live property.
     *
     * Public so the probe can check exactly what the cards ask.
     *
     * @return array<string, array<string, mixed>>
     */
    public function requests(Carbon $from, Carbon $to, bool $compare = true): array
    {
        $range = fn (Carbon $a, Carbon $b) => [['startDate' => $a->toDateString(), 'endDate' => $b->toDateString()]];

        $filter = ['dimensionFilter' => ['notExpression' => ['filter' => [
            'fieldName'    => 'sessionGoogleAdsCampaignName',
            'stringFilter' => ['matchType' => 'EXACT', 'value' => self::NOT_FROM_ADS],
        ]]]];

        $totals = fn (Carbon $a, Carbon $b) => $filter + [
            'dateRanges' => $range($a, $b),
            'metrics'    => array_map(fn ($m) => ['name' => $m], [
                'advertiserAdCost',
                'advertiserAdClicks',
                'advertiserAdImpressions',
                'advertiserAdCostPerClick',
                'returnOnAdSpend',
                'keyEvents',
                'totalRevenue',
            ]),
        ];

        $requests = ['current' => $totals($from, $to)];

        if ($compare) {
            [$prevFrom, $prevTo] = self::previousRange($from, $to);
            $requests['previous'] = $totals($prevFrom, $prevTo);
        }

        $requests['campaigns'] = $filter + [
            'dateRanges' => $range($from, $to),
            'dimensions' => [['name' => 'sessionGoogleAdsCampaignName']],
            'metrics'    => [
                ['name' => 'advertiserAdCost'],
                ['name' => 'advertiserAdClicks'],
                ['name' => 'keyEvents'],
                ['name' => 'totalRevenue'],
            ],
            'orderBys'   => [['metric' => ['metricName' => 'advertiserAdCost'], 'desc' => true]],
            'limit'      => self::TOP_CAMPAIGNS,
        ];

        $bucket = self::trendDimension($from, $to);

        $requests['trend'] = $filter + [
            'dateRanges' => $range($from, $to),
            'dimensions' => [['name' => $bucket]],
            'metrics'    => [['name' => 'advertiserAdCost'], ['name' => 'totalRevenue']],
            'orderBys'   => [['dimension' => ['dimensionName' => $bucket]]],
            'limit'      => 400,
        ];

        return $requests;
    }

    /**
     * The same number of days ending the day before this range starts — the
     * comparison every other card on the dashboard makes.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function previousRange(Carbon $from, Carbon $to): array
    {
        $days    = (int) $from->diffInDays($to) + 1;
        $prevTo  = $from->copy()->subDay();

        return [$prevTo->copy()->subDays($days - 1), $prevTo];
    }

    private static function trendDimension(Carbon $from, Carbon $to): string
    {
        return $from->diffInDays($to) + 1 > self::DAILY_TREND_MAX_DAYS ? 'yearMonth' : 'date';
    }

    /** @param  array<string, array<string, mixed>>  $reports  keyed as requests() names them */
    private function shape(array $reports, Carbon $from, Carbon $to): array
    {
        $current  = $this->totalsRow($reports['current'] ?? []);
        $previous = isset($reports['previous']) ? $this->totalsRow($reports['previous']) : null;

        return [
            'current'   => $current,
            'previous'  => $previous,
            'deltas'    => $this->deltas($current, $previous),
            'campaigns' => $this->campaigns($reports['campaigns'] ?? []),
            'trend'     => $this->trend($reports['trend'] ?? [], $from, $to),
        ];
    }

    /**
     * One period's headline figures. Every value the API sends is a string,
     * and a range with no rows at all sends no row, hence the zeros.
     */
    private function totalsRow(array $report): array
    {
        $values = $report['rows'][0]['metricValues'] ?? [];
        $v      = fn (int $i) => (float) ($values[$i]['value'] ?? 0);

        $row = [
            'cost'        => $v(0),
            'clicks'      => (int) $v(1),
            'impressions' => (int) $v(2),
            'conversions' => $v(5),
            'revenue'     => $v(6),
        ];

        // GA4's own CPC and ROAS where it gave them. They are the same
        // divisions done on Google's side, and computing them here as well
        // is only the fallback for a row where it sent nothing.
        $ratios = $this->ratios($row);

        return $row + [
            'cpc'  => $v(3) > 0 ? $v(3) : $ratios['cpc'],
            'roas' => $v(4) > 0 ? $v(4) : $ratios['roas'],
        ];
    }

    /** CPC and ROAS from the sums, for a combined row GA4 never saw. */
    private function ratios(array $row): array
    {
        return $row + [
            'cpc'  => $row['clicks'] > 0 ? $row['cost'] / $row['clicks'] : null,
            'roas' => $row['cost'] > 0 ? $row['revenue'] / $row['cost'] : null,
        ];
    }

    private function deltas(array $current, ?array $previous): array
    {
        $d = fn (string $k) => OrdersSummary::delta(
            $current[$k] === null ? null : (float) $current[$k],
            $previous && $previous[$k] !== null ? (float) $previous[$k] : null,
        );

        return [
            'cost'    => $d('cost'),
            'clicks'  => $d('clicks'),
            'cpc'     => $d('cpc'),
            'roas'    => $d('roas'),
            'revenue' => $d('revenue'),
        ];
    }

    private function campaigns(array $report): array
    {
        return array_values(array_map(function ($row) {
            $label = trim((string) ($row['dimensionValues'][0]['value'] ?? ''));
            $m     = fn (int $i) => (float) ($row['metricValues'][$i]['value'] ?? 0);

            return [
                'name'        => $label === '' ? 'Unknown' : $label,
                'cost'        => $m(0),
                'clicks'      => (int) $m(1),
                'conversions' => $m(2),
                'revenue'     => $m(3),
            ];
        }, $report['rows'] ?? []));
    }

    /**
     * Cost and revenue per day (or per month for a long range), with the
     * buckets GA4 left out filled in as zero.
     *
     * GA4 omits a day with nothing on it. Drawn as given, a fortnight with a
     * paused week would close the gap and look like steady spend.
     */
    private function trend(array $report, Carbon $from, Carbon $to): array
    {
        $monthly = self::trendDimension($from, $to) === 'yearMonth';

        $found = [];
        foreach ($report['rows'] ?? [] as $row) {
            $key = (string) ($row['dimensionValues'][0]['value'] ?? '');
            $found[$key] = [
                (float) ($row['metricValues'][0]['value'] ?? 0),
                (float) ($row['metricValues'][1]['value'] ?? 0),
            ];
        }

        $points = [];
        $cursor = $monthly ? $from->copy()->startOfMonth() : $from->copy()->startOfDay();

        while ($cursor->lte($to)) {
            $key = $cursor->format($monthly ? 'Ym' : 'Ymd');

            $points[] = [
                'key'     => $key,
                'label'   => $cursor->format($monthly ? 'M Y' : 'd M'),
                'cost'    => $found[$key][0] ?? 0.0,
                'revenue' => $found[$key][1] ?? 0.0,
            ];

            $monthly ? $cursor->addMonthNoOverflow() : $cursor->addDay();
        }

        return $points;
    }
}
