<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\User;
use App\Services\Ga4AnalyticsService;
use App\Services\OrdersSummaryService;
use App\Services\ShopifyAnalyticsService;
use App\Support\OrdersSummary;
use App\Support\WorkspaceSummary;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Orders across every storefront, on one screen.
 *
 * Twenty-three Shopify-and-manual shops run through one delivery operation,
 * and the way to see all of them used to be a set of cron'd scripts that
 * emailed spreadsheets. The numbers here come from a single pre-aggregated
 * endpoint on the ecommerce server — one call answers the whole page — and
 * the product creation panel comes from this application's own tables, so
 * what sold and what is on its way sit side by side.
 *
 * Filters live in the query string rather than in a client-side store: a view
 * worth looking at is worth sending to somebody, and the page has to survive
 * being pasted into a message. That also keeps the shared API token on this
 * server, which is the whole reason the browser does not call the endpoint
 * itself.
 *
 * Times need no conversion anywhere on this page. The endpoint pins itself to
 * Asia/Qatar and this application is configured to the same zone, so a date
 * typed into the filter bar means the same day at both ends.
 */
class OrdersDashboardController extends Controller
{
    /**
     * How far back "All time" reaches. The oldest orders are from 2019 and
     * the endpoint has no "everything" mode, so a floor has to be named.
     */
    private const FLOOR = '2019-01-01';

    /**
     * What every tab opens on. The month is the unit these screens are read
     * in — targets, reviews and comparisons are all monthly — so a rolling
     * thirty days answered a question nobody was asking.
     */
    private const DEFAULT_PRESET = 'this_month';

    /**
     * The discovered platform list, remembered between page loads.
     *
     * The endpoint echoes back the platforms it actually queried, which is the
     * full list only while nothing is filtered — ask it for two shops and it
     * reports two. The picker needs all of them, so the full list is kept from
     * the last unfiltered answer. New storefronts are auto-discovered from the
     * database schema, so a day is short enough to notice one.
     */
    private const PLATFORM_CACHE = 'orders_dashboard.platforms';

    /**
     * Kept off this page entirely — the picker, the breakdown table and the
     * quiet-platforms line all drop it, and a URL edited to name it directly
     * is stripped back to nothing rather than honoured.
     *
     * The endpoint itself is still asked for every platform it has, unfiltered
     * by default, so a genuinely new storefront is auto-discovered exactly as
     * before. Only what this page keeps from that answer is narrower. Because
     * of that, the headline totals — pre-aggregated on the endpoint's side
     * across whatever was requested — still include this platform's orders;
     * only the per-platform breakdown this page computes locally is trimmed.
     */
    private const EXCLUDED_PLATFORMS = ['nespresso'];

    /**
     * The tabs on the screen. Which one is open lives in the URL, so the keys
     * are what shared links carry and stay as they are however the labels
     * beside them get renamed.
     */
    private const TABS = [
        'orders'    => 'Ecom Delivery',
        'analytics' => 'Ecom Order Analytics',
        'sessions'  => 'Customer Sessions',
        'studio'    => 'AI Studio',
    ];

    public function index(Request $request, OrdersSummaryService $orders, ShopifyAnalyticsService $analytics, Ga4AnalyticsService $sessions, #[CurrentUser] User $user): View
    {
        abort_unless($user->hasFeature('orders_dashboard'), 403);

        $tab = $request->string('tab')->toString();
        $tab = \array_key_exists($tab, self::TABS) ? $tab : 'orders';

        $filters = $this->filters($request);

        // The studio tab is the workspace picture on its own fortnightly clock,
        // so the orders endpoint is not called for it at all — no point paying
        // for two API round trips to render a page that shows neither.
        if ($tab === 'studio') {
            return view('orders.dashboard', [
                'tab'       => $tab,
                'tabs'      => self::TABS,
                'filters'   => $filters,
                'presets'   => $this->presets(),
                'bases'     => OrdersSummaryService::BASES,
                'result'    => ['ok' => true, 'status' => 100, 'message' => '', 'data' => null],
                'summary'   => null,
                'platforms' => $this->platformList(null, $filters['platforms']),
                'fallback'  => null,
                'workspace' => WorkspaceSummary::for($user),
                'analytics' => null,
                'analyticsTotals' => null,
                'sessions'        => null,
                'sessionsTotals'  => null,
            ]);
        }

        // Each website's own Shopify, not the ecommerce-server endpoint the
        // Orders tab reads — so this tab is answered without that call either.
        if ($tab === 'analytics') {
            $stores = Store::accessibleBy($user)->orderBy('name')->get();
            $rows   = $analytics->forStores($stores, $filters['from'], $filters['to']);

            // Connected stores lead, sorted by revenue; a not-connected or
            // unavailable store has no revenue to sort by and falls to the end.
            $rows = collect($rows)
                ->sortByDesc(fn ($row) => $row['status'] === 'ok' ? $row['revenue'] : -1)
                ->values()
                ->all();

            return view('orders.dashboard', [
                'tab'             => $tab,
                'tabs'            => self::TABS,
                'filters'         => $filters,
                'presets'         => $this->presets(),
                'bases'           => OrdersSummaryService::BASES,
                'result'          => ['ok' => true, 'status' => 100, 'message' => '', 'data' => null],
                'summary'         => null,
                'platforms'       => $this->platformList(null, $filters['platforms']),
                'fallback'        => null,
                'workspace'       => null,
                'analytics'       => $rows,
                'analyticsTotals' => $this->analyticsTotals($rows),
                'sessions'        => null,
                'sessionsTotals'  => null,
            ]);
        }

        // Google Analytics, not Shopify: the traffic half of the picture, and
        // answered without either of the other two tabs' calls.
        if ($tab === 'sessions') {
            $stores = Store::accessibleBy($user)->orderBy('name')->get();
            $rows   = $sessions->forStores($stores, $filters['from'], $filters['to']);

            // Websites that reported lead, busiest first; one that cannot
            // answer has no session count to sort by and falls to the end.
            $rows = collect($rows)
                ->sortByDesc(fn ($row) => $row['status'] === 'ok' ? $row['sessions'] : -1)
                ->values()
                ->all();

            return view('orders.dashboard', [
                'tab'             => $tab,
                'tabs'            => self::TABS,
                'filters'         => $filters,
                'presets'         => $this->presets(),
                'bases'           => OrdersSummaryService::BASES,
                'result'          => ['ok' => true, 'status' => 100, 'message' => '', 'data' => null],
                'summary'         => null,
                'platforms'       => $this->platformList(null, $filters['platforms']),
                'fallback'        => null,
                'workspace'       => null,
                'analytics'       => null,
                'analyticsTotals' => null,
                'sessions'        => $rows,
                'sessionsTotals'  => $this->sessionsTotals($rows),
            ]);
        }

        $result   = ['ok' => false, 'status' => 0, 'message' => '', 'data' => null];
        $previous = $result;

        if (! $orders->configured()) {
            $result['message'] = 'The orders service is not configured on this server. Set ORDERS_API_TOKEN and try again.';
            $result['status']  = 401;
        } elseif ($filters['compare']) {
            $pair     = $orders->fetchPair($this->query($filters), $this->query($filters, previous: true));
            $result   = $pair['current'];
            $previous = $pair['previous'];
        } else {
            // All time has no preceding period to compare against, and asking
            // for one doubles the heaviest query on the page for a number that
            // could not be shown anyway.
            $result = $orders->fetch($this->query($filters));
        }

        return view('orders.dashboard', [
            'tab'       => $tab,
            'tabs'      => self::TABS,
            'filters'   => $filters,
            'presets'   => $this->presets(),
            'bases'     => OrdersSummaryService::BASES,
            'result'    => $result,
            'summary'   => $result['ok'] && $result['data'] ? $this->shape($result['data'], $previous['data'] ?? null) : null,
            'platforms' => $this->platformList($result['data'] ?? null, $filters['platforms']),
            'fallback'  => $this->fallbackRange($filters),
            'workspace' => null,
            'analytics' => null,
            'analyticsTotals' => null,
            'sessions'        => null,
            'sessionsTotals'  => null,
        ]);
    }

    /**
     * The headline figures above the session cards.
     *
     * Only websites that answered are counted. A website with no property, or
     * one this service account cannot read, is not a website with no
     * visitors — leaving it out of the totals is the difference between
     * reporting what is known and inventing a zero.
     */
    private function sessionsTotals(array $rows): array
    {
        $reporting = collect($rows)->where('status', 'ok');

        return [
            'sessions'   => (int) $reporting->sum('sessions'),
            'users'      => (int) $reporting->sum('users'),
            'new_users'  => (int) $reporting->sum('new_users'),
            'page_views' => (int) $reporting->sum('page_views'),
            'reporting'  => $reporting->count(),
            'total'      => count($rows),
        ];
    }

    /**
     * The headline figures above the per-website cards.
     *
     * Revenue is summed only across stores sharing the commonest currency.
     * Every one of these storefronts bills in QAR today, but nothing stops a
     * store in another currency being added, and quietly adding AED to QAR
     * would put a wrong number at the top of a management screen. Orders are
     * a count and carry no currency, so they are summed the same way only to
     * keep the average order value honest against the revenue beside it.
     */
    private function analyticsTotals(array $rows): array
    {
        $selling = collect($rows)->where('status', 'ok');

        if ($selling->isEmpty()) {
            return ['currency' => 'QAR', 'revenue' => 0.0, 'orders' => 0, 'aov' => 0.0,
                    'selling' => 0, 'connected' => 0, 'other_currencies' => 0];
        }

        $currency = $selling->groupBy('currency')
            ->map(fn ($group) => $group->sum('revenue'))
            ->sortDesc()
            ->keys()
            ->first();

        $counted = $selling->where('currency', $currency);
        $revenue = (float) $counted->sum('revenue');
        $orders  = (int) $counted->sum('orders');

        return [
            'currency'         => $currency,
            'revenue'          => $revenue,
            'orders'           => $orders,
            'aov'              => $orders > 0 ? $revenue / $orders : 0.0,
            'selling'          => $selling->where('orders', '>', 0)->count(),
            'connected'        => $selling->count(),
            'other_currencies' => $selling->count() - $counted->count(),
        ];
    }

    /**
     * One platform's own Web/Manual split, for the platform table's expand
     * control.
     *
     * The endpoint answers "orders by platform" and "orders by type" as two
     * separate breakdowns, never crossed — there is no single number here for
     * "this platform's manual orders" in the page's one whole-screen call.
     * Scoping a fresh request to just this platform gets it: filtered that
     * way, every breakdown the endpoint returns — including this one — comes
     * back scoped to it, the same mechanism the platform filter already uses.
     *
     * Asked for on click rather than for every row on every load, so a table
     * of twenty-three platforms costs one extra call only for the row someone
     * actually opens, not twenty-three on a page built around a single one.
     */
    public function platformOrderTypes(Request $request, OrdersSummaryService $orders, #[CurrentUser] User $user, string $platform): JsonResponse
    {
        abort_unless($user->hasFeature('orders_dashboard'), 403);

        // Never offered in the table, and not answerable by asking directly
        // either — the same rule the main breakdown holds.
        abort_if(\in_array($platform, self::EXCLUDED_PLATFORMS, true), 404);

        $from  = $this->date($request->string('from')->toString(), Carbon::today()->startOfMonth());
        $to    = $this->date($request->string('to')->toString(), Carbon::today());
        $basis = $request->string('basis')->toString();
        $basis = \array_key_exists($basis, OrdersSummaryService::BASES) ? $basis : 'created';

        $result = $orders->fetch([
            'from'       => $from->format('Y-m-d'),
            'to'         => $to->format('Y-m-d'),
            'date_basis' => $basis,
            'platform'   => $platform,
        ]);

        if (! $result['ok']) {
            $status = $result['status'] >= 100 && $result['status'] <= 599 ? $result['status'] : 500;

            return response()->json(['ok' => false, 'message' => $result['message']], $status);
        }

        $types = collect($result['data']['by_order_type'] ?? [])
            ->keyBy(fn ($row) => Str::lower((string) $row['order_type']));

        $side = fn (string $type) => [
            'orders'  => (int) ($types[$type]['orders'] ?? 0),
            'revenue' => (float) ($types[$type]['revenue'] ?? 0),
        ];

        return response()->json([
            'ok'     => true,
            'web'    => $side('web'),
            'manual' => $side('manual'),
        ]);
    }

    // ── Filters ──────────────────────────────────────────────────────────────

    /** Ranges people actually ask for, in the order they ask for them. */
    private function presets(): array
    {
        return [
            'today'      => 'Today',
            '7d'         => 'Last 7 days',
            'this_month' => 'This month',
            'last_month' => 'Last month',
            'this_year'  => 'This year',
            'all'        => 'All time',
        ];
    }

    /**
     * Read the filter bar out of the query string.
     *
     * Anything unrecognised falls back to a sensible default rather than
     * failing the page — a hand-edited URL should still render something.
     * Unknown platform slugs are the exception and are passed straight
     * through: the endpoint names the valid ones in its rejection, which is
     * more use than anything this could say.
     */
    private function filters(Request $request): array
    {
        $preset = $request->string('preset')->toString();
        // Anything unrecognised lands on the default rather than failing the
        // page, which also catches links bookmarked against the old rolling
        // 30-day preset now that the month is what these screens report on.
        $preset = \array_key_exists($preset, $this->presets()) || $preset === 'custom' ? $preset : self::DEFAULT_PRESET;

        [$from, $to] = $this->range($preset, $request);

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        $basis = $request->string('basis')->toString();
        $basis = \array_key_exists($basis, OrdersSummaryService::BASES) ? $basis : 'created';

        $platforms = collect($request->input('platforms', []))
            ->filter(fn ($p) => \is_string($p) && $p !== '')
            ->map(fn ($p) => trim($p))
            ->unique()
            // Never sent to the endpoint, so a bookmarked or hand-edited URL
            // naming it directly cannot bring it back onto the page.
            ->reject(fn ($p) => \in_array($p, self::EXCLUDED_PLATFORMS, true))
            ->values()
            ->all();

        $days = (int) $from->diffInDays($to) + 1;

        return [
            'preset'    => $preset,
            'from'      => $from,
            'to'        => $to,
            'days'      => $days,
            'basis'     => $basis,
            'platforms' => $platforms,

            // All time is measured against nothing: there is no earlier
            // period, and asking for one only doubles the slowest query.
            'compare'   => $preset !== 'all',
        ];
    }

    /** Turn a preset into two dates. "Custom" reads them off the query string. */
    private function range(string $preset, Request $request): array
    {
        $today = Carbon::today();

        return match ($preset) {
            'today'      => [$today->copy(), $today->copy()],
            '7d'         => [$today->copy()->subDays(6), $today->copy()],
            'this_month' => [$today->copy()->startOfMonth(), $today->copy()],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
            'this_year'  => [$today->copy()->startOfYear(), $today->copy()],
            'all'        => [Carbon::parse(self::FLOOR), $today->copy()],
            'custom'     => [
                $this->date($request->string('from')->toString(), $today->copy()->startOfMonth()),
                $this->date($request->string('to')->toString(), $today),
            ],
            default      => [$today->copy()->startOfMonth(), $today->copy()],
        };
    }

    private function date(string $value, Carbon $fallback): Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $fallback;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /**
     * The parameters for one request.
     *
     * The comparison period is the same number of days ending the day before
     * this one starts, so a 30-day view is measured against the 30 days before
     * it rather than against "last month", which is a different length.
     */
    private function query(array $filters, bool $previous = false): array
    {
        $from = $filters['from'];
        $to   = $filters['to'];

        if ($previous) {
            $to   = $filters['from']->copy()->subDay();
            $from = $to->copy()->subDays($filters['days'] - 1);
        }

        $params = [
            'from'       => $from->format('Y-m-d'),
            'to'         => $to->format('Y-m-d'),
            'date_basis' => $filters['basis'],
        ];

        if ($filters['platforms']) {
            $params['platform'] = implode(',', $filters['platforms']);
        }

        return $params;
    }

    // ── Shaping ──────────────────────────────────────────────────────────────

    /**
     * Everything the view renders, worked out once here rather than in Blade.
     *
     * @param  array  $data      the current period
     * @param  ?array $previous  the preceding one, or null when it failed or was skipped
     */
    private function shape(array $data, ?array $previous): array
    {
        $totals   = $data['totals'] ?? [];
        $byStatus = $data['by_status'] ?? [];
        $before   = $previous['totals'] ?? null;

        $net = OrdersSummary::netRevenue($byStatus);

        // Excluded platforms never had their own row here, so nothing past
        // this point — the breakdown, its chart and the quiet-platforms line
        // below it — has to know they exist. The totals above are the one
        // exception: the endpoint pre-aggregates them across whatever it was
        // asked for, and by default that is still every platform it has.
        $byPlatform = array_values(array_filter(
            $data['by_platform'] ?? [],
            fn ($row) => ! \in_array($row['platform'] ?? null, self::EXCLUDED_PLATFORMS, true),
        ));
        $queried = array_diff($data['filters']['platforms'] ?? [], self::EXCLUDED_PLATFORMS);

        return [
            'totals'   => $totals,
            'filters'  => $data['filters'] ?? [],
            'quality'  => $data['data_quality'] ?? [],
            'currency' => $totals['currency'] ?? 'QAR',
            'empty'    => (int) ($totals['total_orders'] ?? 0) === 0,

            'net'        => $net,
            'lost'       => OrdersSummary::lostOrders($byStatus),
            'lost_value' => (float) ($totals['total_revenue'] ?? 0) - $net,

            'deltas' => [
                'orders'  => OrdersSummary::delta((float) ($totals['total_orders'] ?? 0), $before ? (float) ($before['total_orders'] ?? 0) : null),
                'revenue' => OrdersSummary::delta((float) ($totals['total_revenue'] ?? 0), $before ? (float) ($before['total_revenue'] ?? 0) : null),

                // An empty range reports a null average rather than zero, and
                // a change measured against null is not a change.
                'aov'     => OrdersSummary::delta(
                    ($totals['average_order_value'] ?? null) !== null ? (float) $totals['average_order_value'] : null,
                    ($before['average_order_value'] ?? null) !== null ? (float) $before['average_order_value'] : null,
                ),
                'net'     => OrdersSummary::delta($net, $previous ? OrdersSummary::netRevenue($previous['by_status'] ?? []) : null),
            ],
            'previous' => $before,

            'series'   => OrdersSummary::series(
                $data['by_period'] ?? [],
                $data['filters']['from'] ?? '',
                $data['filters']['to'] ?? '',
                $data['filters']['granularity'] ?? 'daily',
            ),
            'monthly'  => ($data['filters']['granularity'] ?? 'daily') === 'monthly',

            'platforms'     => $byPlatform,
            'platform_bars' => OrdersSummary::topN($byPlatform, 'platform'),
            'dormant'       => OrdersSummary::dormant($byPlatform, $queried),

            'outcomes' => OrdersSummary::outcomes($byStatus),
            'statuses' => $byStatus,
            'payments' => OrdersSummary::payments($data['by_payment_method'] ?? []),
            'types'    => $data['by_order_type'] ?? [],
            'sources'  => OrdersSummary::sources($data['by_source'] ?? []),
            'shipping' => OrdersSummary::shipping($data['by_shipping_method'] ?? []),
        ];
    }

    /**
     * Every platform the picker offers.
     *
     * Refreshed from any unfiltered answer. A shared URL that already carries
     * a filter can land here with nothing remembered, so whatever it selected
     * is folded in — the picker is then short, but it never drops the shops
     * the person is actually looking at.
     */
    private function platformList(?array $data, array $selected): array
    {
        // Dropped before it ever reaches the cache, so a name excluded today
        // cannot resurface tomorrow from what an earlier answer remembered.
        $echoed = array_values(array_diff($data['filters']['platforms'] ?? [], self::EXCLUDED_PLATFORMS));

        if ($echoed && ! $selected) {
            Cache::put(self::PLATFORM_CACHE, $echoed, now()->addDay());

            return $echoed;
        }

        // Defensive against a cache entry written before the exclusion existed
        // — a day-old cache should not outlive the list it was built from.
        $known = array_diff(Cache::get(self::PLATFORM_CACHE, []), self::EXCLUDED_PLATFORMS);
        $all   = array_values(array_unique([...$known, ...$echoed, ...$selected]));

        sort($all);

        return $all;
    }

    /**
     * A narrower range to offer when the endpoint answers with nothing.
     *
     * Ranges reaching back before 2024 come back empty — the endpoint fails to
     * encode a handful of old rows and sends a zero-length body. Until that is
     * fixed on its side, the error state can at least hand over a range that
     * does work rather than leaving somebody to guess.
     */
    private function fallbackRange(array $filters): ?array
    {
        if ($filters['from']->year >= 2024) {
            return null;
        }

        return [
            'preset' => 'custom',
            'from'   => '2024-01-01',
            'to'     => $filters['to']->format('Y-m-d'),
            'basis'  => $filters['basis'],
        ];
    }
}
