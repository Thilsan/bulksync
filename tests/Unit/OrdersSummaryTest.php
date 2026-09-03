<?php

namespace Tests\Unit;

use App\Support\OrdersSummary;
use PHPUnit\Framework\TestCase;

/**
 * The reshaping rules, without a request in the way.
 *
 * The endpoint guarantees that every breakdown sums to the totals it reports.
 * Nothing here is allowed to break that: folding twenty-one payment spellings
 * into six names and twenty-three platforms into "top eight and the rest" are
 * both ways to lose orders quietly, and a dashboard whose parts no longer add
 * up to its headline is worse than no dashboard.
 */
class OrdersSummaryTest extends TestCase
{
    private function row(string $key, string $value, int $orders, float $revenue): array
    {
        return [$key => $value, 'orders' => $orders, 'revenue' => $revenue];
    }

    public function test_folding_payment_methods_keeps_every_order(): void
    {
        $rows = [
            $this->row('payment_method', 'cod', 700, 500000),
            $this->row('payment_method', 'Cash on Delivery (COD)', 300, 300000),
            $this->row('payment_method', 'cashondelivery', 97, 27150.65),
            $this->row('payment_method', 'myfatoorah_v2', 250, 200000),
            $this->row('payment_method', 'MyFatoorah', 150, 60000),
        ];

        $folded = OrdersSummary::payments($rows);

        $this->assertSame(['Cash on Delivery', 'MyFatoorah'], array_column($folded, 'label'));
        $this->assertSame(1497, array_sum(array_column($folded, 'orders')));
        $this->assertEqualsWithDelta(1087150.65, array_sum(array_column($folded, 'revenue')), 0.001);

        // Averages are recomputed from the merged totals rather than averaged.
        $this->assertEqualsWithDelta(827150.65 / 1097, $folded[0]['average_order_value'], 0.001);
    }

    /** Paid at the door with a card is not paid at the door with cash. */
    public function test_card_on_delivery_stays_separate_from_cash_on_delivery(): void
    {
        $folded = OrdersSummary::payments([
            $this->row('payment_method', 'cod', 10, 100),
            $this->row('payment_method', 'Payment with Credit/Debit card upon delivery.', 5, 50),
        ]);

        $this->assertSame(['Cash on Delivery', 'Card on Delivery'], array_column($folded, 'label'));
    }

    /** A spelling nobody has mapped yet must survive, not vanish. */
    public function test_unmapped_values_keep_their_own_bucket(): void
    {
        $folded = OrdersSummary::payments([
            $this->row('payment_method', 'split', 4, 40),
            $this->row('payment_method', 'pending', 2, 20),
        ]);

        $this->assertSame(['Split', 'Pending'], array_column($folded, 'label'));
    }

    public function test_the_two_delivery_spellings_become_one(): void
    {
        $folded = OrdersSummary::shipping([
            $this->row('shipping_method', 'local_delivery', 1000, 800000),
            $this->row('shipping_method', 'delivery', 500, 400000),
            $this->row('shipping_method', 'pickup', 20, 5000),
        ]);

        $this->assertSame('Local delivery', $folded[0]['label']);
        $this->assertSame(1500, $folded[0]['orders']);

        // Shares are recomputed across the rows given, which the endpoint
        // guarantees already add up to the totals: 1500 of 1520 orders.
        $this->assertSame(98.7, round($folded[0]['share_of_orders'], 1));
    }

    public function test_top_n_keeps_the_tail_rather_than_dropping_it(): void
    {
        $rows = [];
        foreach (range(1, 12) as $i) {
            $rows[] = $this->row('platform', "shop{$i}", 100 - $i, 1000 - $i);
        }

        $bars = OrdersSummary::topN($rows, 'platform');

        $this->assertCount(9, $bars);
        $this->assertSame('4 others', $bars[8]['label']);
        $this->assertSame(array_sum(array_column($rows, 'orders')), array_sum(array_column($bars, 'orders')));
        $this->assertSame(array_sum(array_column($rows, 'revenue')), array_sum(array_column($bars, 'revenue')));
    }

    /** Nine platforms fit; rolling one of them into "1 other" helps nobody. */
    public function test_a_short_list_is_left_alone(): void
    {
        $rows = [];
        foreach (range(1, 9) as $i) {
            $rows[] = $this->row('platform', "shop{$i}", $i, $i * 10);
        }

        $this->assertCount(9, OrdersSummary::topN($rows, 'platform'));
    }

    public function test_net_revenue_drops_only_the_lost_statuses(): void
    {
        $statuses = [
            ['status' => 'FullFilled', 'status_id' => 10, 'orders' => 100, 'revenue' => 1000.0],
            ['status' => 'Cancelled',  'status_id' => 6,  'orders' => 10,  'revenue' => 100.0],
            ['status' => 'Failed',     'status_id' => 17, 'orders' => 5,   'revenue' => 50.0],
            ['status' => 'Unknown',    'status_id' => null, 'orders' => 2, 'revenue' => 20.0],
        ];

        $this->assertSame(1020.0, OrdersSummary::netRevenue($statuses));
        $this->assertSame(15, OrdersSummary::lostOrders($statuses));

        // An unmapped status is not silently counted as lost, and not dropped.
        $outcomes = OrdersSummary::outcomes($statuses);
        $this->assertSame(117, array_sum(array_column($outcomes, 'orders')));
        $this->assertContains('Unclassified', array_column($outcomes, 'label'));
    }

    public function test_the_series_is_filled_out_to_the_full_range(): void
    {
        $spine = OrdersSummary::series(
            [['period' => '2026-08-01', 'orders' => 5, 'revenue' => 100.0],
             ['period' => '2026-08-04', 'orders' => 3, 'revenue' => 60.0]],
            '2026-08-01', '2026-08-05', 'daily'
        );

        $this->assertCount(5, $spine);
        $this->assertSame([5, 0, 0, 3, 0], array_column($spine, 'orders'));
        $this->assertSame('1 Aug', $spine[0]['label']);
    }

    public function test_a_monthly_series_walks_months_not_days(): void
    {
        $spine = OrdersSummary::series(
            [['period' => '2026-03', 'orders' => 7, 'revenue' => 70.0]],
            '2026-01-15', '2026-04-10', 'monthly'
        );

        $this->assertSame(['2026-01', '2026-02', '2026-03', '2026-04'], array_column($spine, 'period'));
        $this->assertSame([0, 0, 7, 0], array_column($spine, 'orders'));
        $this->assertSame('Mar 2026', $spine[2]['label']);
    }

    /** Growth measured against nothing is not a percentage. */
    public function test_a_delta_needs_something_to_compare_against(): void
    {
        $this->assertNull(OrdersSummary::delta(100.0, null));
        $this->assertNull(OrdersSummary::delta(100.0, 0.0));
        $this->assertSame(50.0, OrdersSummary::delta(150.0, 100.0));
        $this->assertSame(-25.0, OrdersSummary::delta(75.0, 100.0));
    }

    public function test_dormant_platforms_are_the_ones_that_sold_nothing(): void
    {
        $this->assertSame(
            ['wcmq'],
            OrdersSummary::dormant(
                [['platform' => 'bluesalon'], ['platform' => 'nespresso']],
                ['bluesalon', 'nespresso', 'wcmq'],
            )
        );
    }
}
