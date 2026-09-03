<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\OrdersSummaryService;
use App\Support\OrdersSummary;
use App\Support\ProductCreationSummary;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
     * The discovered platform list, remembered between page loads.
     *
     * The endpoint echoes back the platforms it actually queried, which is the
     * full list only while nothing is filtered — ask it for two shops and it
     * reports two. The picker needs all of them, so the full list is kept from
     * the last unfiltered answer. New storefronts are auto-discovered from the
     * database schema, so a day is short enough to notice one.
     */
    private const PLATFORM_CACHE = 'orders_dashboard.platforms';

    public function index(Request $request, OrdersSummaryService $orders, #[CurrentUser] User $user): View
    {
        abort_unless($user->hasFeature('orders_dashboard'), 403);

        $filters = $this->filters($request);

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
            'filters'   => $filters,
            'presets'   => $this->presets(),
            'bases'     => OrdersSummaryService::BASES,
            'result'    => $result,
            'summary'   => $result['ok'] && $result['data'] ? $this->shape($result['data'], $previous['data'] ?? null) : null,
            'platforms' => $this->platformList($result['data'] ?? null, $filters['platforms']),
            'products'  => ProductCreationSummary::for($user, $filters['from']->copy()->startOfDay(), $filters['to']->copy()->endOfDay()),
            'fallback'  => $this->fallbackRange($filters),
        ]);
    }

    // ── Filters ──────────────────────────────────────────────────────────────

    /** Ranges people actually ask for, in the order they ask for them. */
    private function presets(): array
    {
        return [
            'today'      => 'Today',
            '7d'         => 'Last 7 days',
            '30d'        => 'Last 30 days',
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
        $preset = \array_key_exists($preset, $this->presets()) || $preset === 'custom' ? $preset : '30d';

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
                $this->date($request->string('from')->toString(), $today->copy()->subDays(29)),
                $this->date($request->string('to')->toString(), $today),
            ],
            default      => [$today->copy()->subDays(29), $today->copy()],
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

            'platforms'     => $data['by_platform'] ?? [],
            'platform_bars' => OrdersSummary::topN($data['by_platform'] ?? [], 'platform'),
            'dormant'       => OrdersSummary::dormant($data['by_platform'] ?? [], $data['filters']['platforms'] ?? []),

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
        $echoed = $data['filters']['platforms'] ?? [];

        if ($echoed && ! $selected) {
            Cache::put(self::PLATFORM_CACHE, $echoed, now()->addDay());

            return $echoed;
        }

        $known = Cache::get(self::PLATFORM_CACHE, []);
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
