<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Everything the orders endpoint reports faithfully and no dashboard should
 * render raw.
 *
 * Six years of accumulated production data arrives exactly as it was typed:
 * the same payment method spelled four ways by four storefronts, quiet days
 * missing from the time series rather than sitting at zero, revenue counted
 * gross across cancelled orders, and twenty-three platforms of wildly
 * different size. None of that is the endpoint being wrong — it is the shape
 * the data actually has, and this is where it becomes readable.
 *
 * Pure functions on arrays, so every rule here is testable without a request.
 */
class OrdersSummary
{
    /**
     * The same payment method, spelled by different storefronts and eras.
     *
     * Card-on-delivery is deliberately not folded into cash-on-delivery. Both
     * are paid at the door, but one is a card terminal and the other is notes
     * in a hand — merging them would misreport how customers actually pay.
     *
     * Anything not listed keeps its own value: the raw list grows whenever a
     * storefront invents a spelling, and a method silently vanishing from the
     * chart is worse than one with an ugly name.
     */
    public const PAYMENT_LABELS = [
        'cod'                                          => 'Cash on Delivery',
        'cash on delivery (cod)'                       => 'Cash on Delivery',
        'cash_on_delivery'                             => 'Cash on Delivery',
        'cashondelivery'                               => 'Cash on Delivery',
        'payment with credit/debit card upon delivery.' => 'Card on Delivery',
        'myfatoorah'                                   => 'MyFatoorah',
        'myfatoorah_v2'                                => 'MyFatoorah',
        'card'                                         => 'Card',
        'mpgs_hostedcheckout'                          => 'Card',
        'tns_hosted'                                   => 'Card',
        'online'                                       => 'Online',
        'cash'                                         => 'Cash',
        'unknown'                                      => 'Not recorded',
    ];

    /**
     * Two legacy spellings of one idea. Kept separate everywhere in the data,
     * shown as one thing, because nobody in the business thinks of them as two
     * different ways to receive a parcel.
     */
    public const SHIPPING_LABELS = [
        'local_delivery' => 'Local delivery',
        'delivery'       => 'Local delivery',
        'pickup'         => 'Pickup',
        'intl_shipping'  => 'International',
        'unknown'        => 'Not recorded',
    ];

    /**
     * Where an order ended up, by status id.
     *
     * Revenue from the endpoint is gross — it includes cancelled, returned and
     * failed orders, which is a deliberate decision on its side, not an
     * oversight. Grouping by outcome is what lets the page show a net figure
     * without asking for the data twice.
     */
    public const OUTCOMES = [
        'completed' => ['label' => 'Completed', 'ids' => [3, 4, 10],                    'tone' => 'emerald'],
        'in_flight' => ['label' => 'In flight', 'ids' => [0, 1, 2, 5, 7, 11, 14, 15, 16], 'tone' => 'sky'],
        'lost'      => ['label' => 'Lost',      'ids' => [6, 8, 12, 17],                'tone' => 'rose'],
    ];

    /** Cancelled, returned to hub, returned and failed — what net revenue drops. */
    public const LOST_IDS = [6, 8, 12, 17];

    /**
     * Gross revenue minus everything that will never be collected.
     *
     * Shown beneath the headline rather than instead of it: gross alone
     * overstates the business, and net alone silently contradicts the number
     * the endpoint itself calls total_revenue.
     */
    public static function netRevenue(array $byStatus): float
    {
        $net = 0.0;

        foreach ($byStatus as $row) {
            if (! \in_array($row['status_id'] ?? null, self::LOST_IDS, true)) {
                $net += (float) ($row['revenue'] ?? 0);
            }
        }

        return $net;
    }

    /** Orders in statuses that will never be collected. */
    public static function lostOrders(array $byStatus): int
    {
        $lost = 0;

        foreach ($byStatus as $row) {
            if (\in_array($row['status_id'] ?? null, self::LOST_IDS, true)) {
                $lost += (int) ($row['orders'] ?? 0);
            }
        }

        return $lost;
    }

    /** Statuses rolled into completed / in flight / lost, plus whatever fits nowhere. */
    public static function outcomes(array $byStatus): array
    {
        $groups = [];

        foreach (self::OUTCOMES as $key => $group) {
            $groups[$key] = $group + ['orders' => 0, 'revenue' => 0.0, 'statuses' => []];
        }

        // A NULL status arrives as "Unknown" with a null id, and an id nobody
        // mapped arrives as "Unmapped status <id>". Neither belongs in an
        // outcome, and neither should be dropped.
        $groups['other'] = ['label' => 'Unclassified', 'ids' => [], 'tone' => 'gray', 'orders' => 0, 'revenue' => 0.0, 'statuses' => []];

        foreach ($byStatus as $row) {
            $id  = $row['status_id'] ?? null;
            $key = 'other';

            foreach (self::OUTCOMES as $candidate => $group) {
                if (\in_array($id, $group['ids'], true)) {
                    $key = $candidate;
                    break;
                }
            }

            $groups[$key]['orders']  += (int) ($row['orders'] ?? 0);
            $groups[$key]['revenue'] += (float) ($row['revenue'] ?? 0);
            $groups[$key]['statuses'][] = $row;
        }

        return array_values(array_filter($groups, fn ($g) => $g['orders'] > 0));
    }

    /**
     * Twenty-one raw payment strings folded into the six or so methods that
     * actually exist. Sorted by revenue, like everything else on the page.
     */
    public static function payments(array $rows): array
    {
        return self::fold($rows, 'payment_method', self::PAYMENT_LABELS);
    }

    /** The two delivery spellings merged; see SHIPPING_LABELS. */
    public static function shipping(array $rows): array
    {
        return self::fold($rows, 'shipping_method', self::SHIPPING_LABELS);
    }

    /** Source values are readable enough raw — they just need capital letters. */
    public static function sources(array $rows): array
    {
        return self::fold($rows, 'source', ['unknown' => 'Not recorded', 'online_web' => 'Website']);
    }

    /**
     * Merge rows whose display label is the same thing, recomputing the shares
     * and the average from the merged totals rather than averaging averages.
     */
    private static function fold(array $rows, string $key, array $labels): array
    {
        $merged      = [];
        $totalOrders = 0;
        $totalRevenue = 0.0;

        foreach ($rows as $row) {
            $raw   = (string) ($row[$key] ?? 'unknown');
            $label = $labels[Str::lower(trim($raw))] ?? self::titleise($raw);

            $merged[$label] ??= ['label' => $label, 'orders' => 0, 'revenue' => 0.0, 'raw' => []];
            $merged[$label]['orders']  += (int) ($row['orders'] ?? 0);
            $merged[$label]['revenue'] += (float) ($row['revenue'] ?? 0);
            $merged[$label]['raw'][]    = $raw;

            $totalOrders  += (int) ($row['orders'] ?? 0);
            $totalRevenue += (float) ($row['revenue'] ?? 0);
        }

        $merged = array_values($merged);

        foreach ($merged as &$row) {
            $row['average_order_value'] = $row['orders'] > 0 ? $row['revenue'] / $row['orders'] : 0.0;
            $row['share_of_orders']     = $totalOrders > 0 ? $row['orders'] / $totalOrders * 100 : 0.0;
            $row['share_of_revenue']    = $totalRevenue > 0 ? $row['revenue'] / $totalRevenue * 100 : 0.0;
        }
        unset($row);

        usort($merged, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        return $merged;
    }

    /** `local_delivery` → `Local delivery`, for values no map has an opinion on. */
    private static function titleise(string $raw): string
    {
        return Str::ucfirst(str_replace('_', ' ', Str::lower(trim($raw)))) ?: 'Not recorded';
    }

    /**
     * The time series, with quiet buckets filled in.
     *
     * The endpoint omits buckets that hold no orders rather than sending them
     * as zero. Plotted as-is, a fortnight of nothing compresses to nothing at
     * all and the line reads as steady trade — so the full spine is generated
     * here and the returned buckets are joined onto it.
     */
    public static function series(array $byPeriod, string $from, string $to, string $granularity): array
    {
        $keyed = [];
        foreach ($byPeriod as $row) {
            $keyed[(string) ($row['period'] ?? '')] = $row;
        }

        $monthly = $granularity === 'monthly';
        $cursor  = Carbon::parse($from)->startOfDay();
        $end     = Carbon::parse($to)->startOfDay();
        $spine   = [];

        // A long all-time range is thousands of days; monthly buckets keep the
        // loop and the chart to a few dozen either way.
        while ($cursor->lessThanOrEqualTo($end)) {
            $period = $monthly ? $cursor->format('Y-m') : $cursor->format('Y-m-d');
            $row    = $keyed[$period] ?? null;

            $spine[] = [
                'period'  => $period,
                'label'   => $monthly ? $cursor->format('M Y') : $cursor->format('j M'),
                'orders'  => (int) ($row['orders'] ?? 0),
                'revenue' => (float) ($row['revenue'] ?? 0),
            ];

            $monthly ? $cursor->addMonthNoOverflow()->startOfMonth() : $cursor->addDay();
        }

        return $spine;
    }

    /**
     * Top N platforms and one "Other".
     *
     * Volumes across the twenty-three storefronts differ by three orders of
     * magnitude — Bluesalon against wcmq — so a chart of all of them is one
     * readable bar and twenty-two slivers.
     */
    public static function topN(array $rows, string $key, int $limit = 8): array
    {
        if (\count($rows) <= $limit + 1) {
            return array_map(fn ($r) => $r + ['label' => (string) $r[$key]], $rows);
        }

        $top  = \array_slice($rows, 0, $limit);
        $rest = \array_slice($rows, $limit);

        $top = array_map(fn ($r) => $r + ['label' => (string) $r[$key]], $top);

        $top[] = [
            'label'   => \count($rest) . ' others',
            $key      => null,
            'orders'  => array_sum(array_column($rest, 'orders')),
            'revenue' => array_sum(array_column($rest, 'revenue')),
        ];

        return $top;
    }

    /**
     * Storefronts with no orders in the range, so a shop that has stopped
     * selling reads as a zero rather than quietly disappearing off the list.
     */
    public static function dormant(array $byPlatform, array $allPlatforms): array
    {
        return array_values(array_diff($allPlatforms, array_column($byPlatform, 'platform')));
    }

    /**
     * Percentage change against the preceding period.
     *
     * Null when there is nothing to compare against: growth from zero is not
     * a percentage, and printing one invites reading it as a real figure.
     */
    public static function delta(?float $current, ?float $previous): ?float
    {
        if ($previous === null || $previous == 0.0) {
            return null;
        }

        return (($current ?? 0.0) - $previous) / abs($previous) * 100;
    }
}
