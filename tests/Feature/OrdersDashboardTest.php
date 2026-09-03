<?php

namespace Tests\Feature;

use App\Models\ProductRequest;
use App\Models\ProductRequestDraftProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The cross-platform orders screen.
 *
 * Every number on it comes from one endpoint on the ecommerce server, so the
 * tests fake that endpoint and check the two things the page is responsible
 * for: sending what the filter bar says, and rendering six years of untidy
 * production data in a way that is not misleading.
 */
class OrdersDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.orders_api.url'   => 'https://orders.test/orders_summary.php',
            'services.orders_api.token' => 'test-token-value',
        ]);

        // Inactive accounts are bounced to the login screen before any of this
        // runs, which reads as a permissions failure and is not one.
        $this->admin = User::create([
            'name' => 'Ada Okonkwo', 'email' => 'ada@example.test',
            'password' => 'password', 'is_active' => true, 'is_super_admin' => true,
        ]);
    }

    /** A realistic August, matching the shape the live endpoint returns. */
    private function payload(array $overrides = []): array
    {
        $data = [
            'filters' => [
                'from' => '2026-08-01', 'to' => '2026-08-31', 'days_in_range' => 31,
                'date_basis' => 'created', 'date_column' => 'created', 'granularity' => 'daily',
                'platforms' => ['bluesalon', 'nespresso', 'wcmq'], 'platform_count' => 3,
                'generated_at' => '2026-09-02 08:35:01',
            ],
            'totals' => [
                'total_orders' => 1797, 'total_revenue' => 1327150.65, 'average_order_value' => 738.54,
                'currency' => 'QAR', 'orders_with_value' => 1797, 'min_order_value' => 0,
                'max_order_value' => 51876, 'first_order_at' => '2026-08-01 01:06:55',
                'last_order_at' => '2026-08-31 23:48:27', 'platforms_with_orders' => 2, 'platforms_queried' => 3,
            ],
            'by_platform' => [
                ['platform' => 'bluesalon', 'orders' => 1400, 'revenue' => 1000150.65, 'average_order_value' => 714.39,
                 'share_of_orders' => 77.9, 'share_of_revenue' => 75.36, 'orders_with_value' => 1400,
                 'min_order_value' => 0, 'max_order_value' => 51876,
                 'first_order_at' => '2026-08-01 02:16:49', 'last_order_at' => '2026-08-31 23:48:27'],
                ['platform' => 'nespresso', 'orders' => 397, 'revenue' => 327000, 'average_order_value' => 823.68,
                 'share_of_orders' => 22.1, 'share_of_revenue' => 24.64, 'orders_with_value' => 397,
                 'min_order_value' => 10, 'max_order_value' => 9000,
                 'first_order_at' => '2026-08-02 09:00:00', 'last_order_at' => '2026-08-30 18:00:00'],
            ],
            'by_status' => [
                ['status' => 'FullFilled', 'status_id' => 10, 'orders' => 1200, 'revenue' => 900150.65,
                 'average_order_value' => 750.13, 'share_of_orders' => 66.78, 'share_of_revenue' => 67.83],
                ['status' => 'Delivered', 'status_id' => 3, 'orders' => 497, 'revenue' => 377000,
                 'average_order_value' => 758.55, 'share_of_orders' => 27.66, 'share_of_revenue' => 28.41],
                ['status' => 'Cancelled', 'status_id' => 6, 'orders' => 100, 'revenue' => 50000,
                 'average_order_value' => 500, 'share_of_orders' => 5.56, 'share_of_revenue' => 3.77],
            ],
            // The spellings that make the raw chart unreadable, all present at once.
            'by_payment_method' => [
                ['payment_method' => 'cod', 'orders' => 700, 'revenue' => 500000, 'average_order_value' => 714.29, 'share_of_orders' => 38.95, 'share_of_revenue' => 37.68],
                ['payment_method' => 'Cash on Delivery (COD)', 'orders' => 300, 'revenue' => 300000, 'average_order_value' => 1000, 'share_of_orders' => 16.69, 'share_of_revenue' => 22.61],
                ['payment_method' => 'cash_on_delivery', 'orders' => 97, 'revenue' => 27150.65, 'average_order_value' => 279.9, 'share_of_orders' => 5.4, 'share_of_revenue' => 2.05],
                ['payment_method' => 'Payment with Credit/Debit card upon delivery.', 'orders' => 200, 'revenue' => 200000, 'average_order_value' => 1000, 'share_of_orders' => 11.13, 'share_of_revenue' => 15.07],
                ['payment_method' => 'myfatoorah_v2', 'orders' => 250, 'revenue' => 200000, 'average_order_value' => 800, 'share_of_orders' => 13.91, 'share_of_revenue' => 15.07],
                ['payment_method' => 'MyFatoorah', 'orders' => 150, 'revenue' => 60000, 'average_order_value' => 400, 'share_of_orders' => 8.35, 'share_of_revenue' => 4.52],
                ['payment_method' => 'mpgs_hostedcheckout', 'orders' => 50, 'revenue' => 20000, 'average_order_value' => 400, 'share_of_orders' => 2.78, 'share_of_revenue' => 1.51],
                ['payment_method' => 'card', 'orders' => 30, 'revenue' => 15000, 'average_order_value' => 500, 'share_of_orders' => 1.67, 'share_of_revenue' => 1.13],
                ['payment_method' => 'unknown', 'orders' => 20, 'revenue' => 5000, 'average_order_value' => 250, 'share_of_orders' => 1.11, 'share_of_revenue' => 0.38],
            ],
            'by_order_type' => [
                ['order_type' => 'Web', 'orders' => 1608, 'revenue' => 1057481.35, 'average_order_value' => 657.64, 'share_of_orders' => 89.48, 'share_of_revenue' => 79.69],
                ['order_type' => 'Manual', 'orders' => 189, 'revenue' => 269669.3, 'average_order_value' => 1426.82, 'share_of_orders' => 10.52, 'share_of_revenue' => 20.31],
            ],
            'by_source' => [
                ['source' => 'unknown', 'orders' => 1600, 'revenue' => 1200000, 'average_order_value' => 750, 'share_of_orders' => 89.04, 'share_of_revenue' => 90.42],
                ['source' => 'online_web', 'orders' => 197, 'revenue' => 127150.65, 'average_order_value' => 645.44, 'share_of_orders' => 10.96, 'share_of_revenue' => 9.58],
            ],
            'by_shipping_method' => [
                ['shipping_method' => 'local_delivery', 'orders' => 1000, 'revenue' => 800000, 'average_order_value' => 800, 'share_of_orders' => 55.65, 'share_of_revenue' => 60.28],
                ['shipping_method' => 'delivery', 'orders' => 500, 'revenue' => 400000, 'average_order_value' => 800, 'share_of_orders' => 27.83, 'share_of_revenue' => 30.14],
                ['shipping_method' => 'unknown', 'orders' => 297, 'revenue' => 127150.65, 'average_order_value' => 428.12, 'share_of_orders' => 16.53, 'share_of_revenue' => 9.58],
            ],
            // A quiet day in the middle is missing rather than zero, on purpose.
            'by_period' => [
                ['period' => '2026-08-01', 'orders' => 53, 'revenue' => 36176.05, 'average_order_value' => 682.57],
                ['period' => '2026-08-02', 'orders' => 65, 'revenue' => 47450.85, 'average_order_value' => 730.01],
                ['period' => '2026-08-04', 'orders' => 1679, 'revenue' => 1243523.75, 'average_order_value' => 740.63],
            ],
            'data_quality' => [
                'orders_counted_in_revenue' => 1797, 'orders_missing_total' => 0, 'orders_unparseable_total' => 0,
                'revenue_coverage_pct' => 100, 'suspected_outlier_orders' => 0, 'outlier_threshold' => 500000,
            ],
        ];

        return ['status_code' => 100, 'message' => '1797 orders across 3 platforms.', 'data' => array_replace_recursive($data, $overrides)];
    }

    private function fakeOk(array $overrides = []): void
    {
        Http::fake(['orders.test/*' => Http::response($this->payload($overrides))]);
    }

    private function grant(User $user): User
    {
        $user->update(['is_super_admin' => false, 'perm_orders_dashboard' => true]);

        return $user;
    }

    // ── Access ───────────────────────────────────────────────────────────────

    public function test_the_page_is_closed_without_the_permission(): void
    {
        $this->fakeOk();

        $staff = User::create([
            'name' => 'Rui Barbosa', 'email' => 'rui@example.test',
            'password' => 'password', 'is_active' => true,
        ]);

        $this->actingAs($staff)->get(route('orders.dashboard'))->assertForbidden();
    }

    public function test_the_permission_opens_it_without_super_admin(): void
    {
        $this->fakeOk();

        $viewer = $this->grant(User::create([
            'name' => 'Mei Lin', 'email' => 'mei@example.test',
            'password' => 'password', 'is_active' => true,
        ]));

        $this->actingAs($viewer)->get(route('orders.dashboard'))->assertOk();
    }

    /**
     * Granting it has to work through the form people actually use. The
     * permission columns are mass-assigned, so one missing from the model's
     * fillable list saves silently and hands nobody anything.
     */
    public function test_the_permission_can_be_granted_from_the_admin_panel(): void
    {
        $staff = User::create([
            'name' => 'Rui Barbosa', 'email' => 'rui@example.test',
            'password' => 'password', 'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('super-admin.users.permissions', $staff), ['perm_orders_dashboard' => '1'])
            ->assertRedirect();

        $this->assertTrue($staff->fresh()->perm_orders_dashboard);
        $this->assertTrue($staff->fresh()->hasFeature('orders_dashboard'));
    }

    /**
     * The whole reason this page is server-rendered. One shared token unlocks
     * company-wide revenue, and the browser must never be handed it.
     */
    public function test_the_api_token_never_reaches_the_browser(): void
    {
        $this->fakeOk();

        $html = $this->actingAs($this->admin)->get(route('orders.dashboard'))->getContent();

        $this->assertStringNotContainsString('test-token-value', $html);

        Http::assertSent(fn (Request $r) => $r->hasHeader('X-Api-Token', 'test-token-value'));
    }

    // ── Filters reaching the endpoint ────────────────────────────────────────

    public function test_the_date_basis_is_sent_and_shown(): void
    {
        $this->fakeOk(['filters' => ['date_basis' => 'delivered', 'date_column' => 'delivered_date']]);

        $this->actingAs($this->admin)
            ->get(route('orders.dashboard', ['preset' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-31', 'basis' => 'delivered']))
            ->assertOk()
            ->assertSee('value="delivered" selected', false);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'date_basis=delivered'));
    }

    public function test_selected_platforms_are_sent_as_one_comma_separated_list(): void
    {
        $this->fakeOk();

        $this->actingAs($this->admin)
            ->get(route('orders.dashboard', ['platforms' => ['bluesalon', 'nespresso']]))
            ->assertOk()
            ->assertSee('2 platforms');

        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'platform=bluesalon,nespresso'));
    }

    /**
     * Each KPI is shown against the same number of days immediately before the
     * range — not against "last month", which is a different length.
     */
    public function test_the_preceding_period_is_fetched_alongside(): void
    {
        $this->fakeOk();

        $this->actingAs($this->admin)
            ->get(route('orders.dashboard', ['preset' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'from=2026-08-01') && str_contains($r->url(), 'to=2026-08-31'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'from=2026-07-01') && str_contains($r->url(), 'to=2026-07-31'));
    }

    /** All time has nothing before it, so it asks once rather than twice. */
    public function test_all_time_skips_the_comparison_request(): void
    {
        $this->fakeOk();

        $this->actingAs($this->admin)->get(route('orders.dashboard', ['preset' => 'all']))->assertOk();

        Http::assertSentCount(1);
    }

    // ── Rendering ────────────────────────────────────────────────────────────

    public function test_the_headline_numbers_render(): void
    {
        $this->fakeOk();

        $this->actingAs($this->admin)
            ->get(route('orders.dashboard'))
            ->assertOk()
            ->assertSee('1,797')
            ->assertSee('QAR 1,327,150.65')
            ->assertSee('QAR 738.54');
    }

    /**
     * Revenue from the endpoint is gross across every status, cancelled
     * included. Showing only that overstates the business.
     */
    public function test_net_revenue_drops_cancelled_and_returned_orders(): void
    {
        $this->fakeOk();

        $this->actingAs($this->admin)
            ->get(route('orders.dashboard'))
            ->assertOk()
            ->assertSee('QAR 1,277,150.65')   // gross less the 50,000 cancelled
            ->assertSee('Excludes 100 cancelled, returned or failed');
    }

    public function test_payment_methods_are_folded_into_readable_names(): void
    {
        $this->fakeOk();

        $response = $this->actingAs($this->admin)->get(route('orders.dashboard'))->assertOk();

        $response->assertSee('Cash on Delivery')
                 ->assertSee('Card on Delivery')
                 ->assertSee('MyFatoorah')
                 ->assertSee('Not recorded');

        // Card on delivery is not cash on delivery — folding the two together
        // would misreport how customers actually pay.
        $html = $response->getContent();
        $this->assertSame(1, substr_count($html, '>Cash on Delivery<'));
        $this->assertStringNotContainsString('>cash_on_delivery<', $html);

        // The raw strings stay on the page for auditing, behind a toggle.
        $this->assertStringContainsString('Payment with Credit/Debit card upon delivery.', $html);
    }

    public function test_quiet_days_are_filled_in_rather_than_dropped(): void
    {
        $this->fakeOk();

        // 2026-08-03 has no bucket in the payload; the spine still runs to the
        // 31st, so the axis cannot silently compress.
        $this->actingAs($this->admin)
            ->get(route('orders.dashboard', ['preset' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->assertSee('3 Aug')
            ->assertSee('31 Aug')
            ->assertSee('Daily orders and revenue');
    }

    public function test_a_long_range_relabels_the_axis_to_months(): void
    {
        $this->fakeOk(['filters' => ['from' => '2025-01-01', 'to' => '2026-08-31', 'granularity' => 'monthly']]);

        $this->actingAs($this->admin)
            ->get(route('orders.dashboard', ['preset' => 'custom', 'from' => '2025-01-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->assertSee('Monthly orders and revenue')
            ->assertDontSee('Daily orders and revenue');
    }

    /**
     * Four orders carry a reference number where a price belongs. They parse
     * fine, so nothing server-side can catch them — but they visibly distort
     * all-time revenue, and hiding that is worse than showing it.
     */
    public function test_outliers_raise_a_warning_next_to_revenue(): void
    {
        $this->fakeOk(['data_quality' => ['suspected_outlier_orders' => 4, 'revenue_coverage_pct' => 97.99]]);

        $this->actingAs($this->admin)
            ->get(route('orders.dashboard'))
            ->assertOk()
            ->assertSee('4 orders above QAR 500,000 included')
            ->assertSee('Based on 98.0% of orders')
            ->assertSee('needs a look');
    }

    /** A shop that stops selling should read as a zero, not disappear. */
    public function test_platforms_with_no_orders_still_appear(): void
    {
        $this->fakeOk();

        $this->actingAs($this->admin)
            ->get(route('orders.dashboard'))
            ->assertOk()
            ->assertSee('1 with no orders');
    }

    // ── States ───────────────────────────────────────────────────────────────

    public function test_an_empty_range_renders_an_empty_state_rather_than_nan(): void
    {
        Http::fake(['orders.test/*' => Http::response([
            'status_code' => 100,
            'message'     => '0 orders across 23 platforms.',
            'data'        => [
                'filters' => ['from' => '2019-01-01', 'to' => '2019-02-01', 'days_in_range' => 32,
                              'date_basis' => 'created', 'date_column' => 'created', 'granularity' => 'daily',
                              'platforms' => ['bluesalon'], 'platform_count' => 1, 'generated_at' => '2026-09-03 11:26:37'],
                'totals'  => ['total_orders' => 0, 'total_revenue' => 0, 'average_order_value' => null,
                              'currency' => 'QAR', 'orders_with_value' => 0, 'min_order_value' => null,
                              'max_order_value' => null, 'first_order_at' => null, 'last_order_at' => null,
                              'platforms_with_orders' => 0, 'platforms_queried' => 1],
                'by_platform' => [], 'by_status' => [], 'by_payment_method' => [], 'by_order_type' => [],
                'by_source' => [], 'by_shipping_method' => [], 'by_period' => [],
                'data_quality' => ['orders_counted_in_revenue' => 0, 'orders_missing_total' => 0,
                                   'orders_unparseable_total' => 0, 'revenue_coverage_pct' => null,
                                   'suspected_outlier_orders' => 0, 'outlier_threshold' => 500000],
            ],
        ])]);

        $response = $this->actingAs($this->admin)
            ->get(route('orders.dashboard', ['preset' => 'custom', 'from' => '2019-01-01', 'to' => '2019-02-01']))
            ->assertOk()
            ->assertSee('No orders in this range');

        $this->assertStringNotContainsString('NaN', $response->getContent());
    }

    /** The endpoint writes its messages for people; they are shown as written. */
    public function test_an_unauthorised_response_shows_the_services_own_message(): void
    {
        Http::fake(['orders.test/*' => Http::response(
            ['status_code' => 401, 'message' => 'Unauthorized: missing or invalid API token.', 'data' => null],
            401
        )]);

        $this->actingAs($this->admin)
            ->get(route('orders.dashboard'))
            ->assertOk()
            ->assertSee('Unauthorized: missing or invalid API token.')
            ->assertSee('Orders service not authorised');
    }

    public function test_a_rejected_filter_shows_the_services_own_message(): void
    {
        Http::fake(['orders.test/*' => Http::response(
            ['status_code' => 400, 'message' => 'Unknown platform(s): shopify. Available: americantourister, avon.', 'data' => null],
            400
        )]);

        $this->actingAs($this->admin)
            ->get(route('orders.dashboard', ['platforms' => ['shopify']]))
            ->assertOk()
            ->assertSee('Unknown platform(s): shopify.', false);
    }

    /**
     * Ranges reaching back before 2024 answer HTTP 200 with a zero-length
     * body — the endpoint gives up encoding a few old rows. A blank page is
     * the one thing that must not happen.
     */
    public function test_an_empty_body_becomes_an_error_with_a_way_out(): void
    {
        Http::fake(['orders.test/*' => Http::response('', 200)]);

        $this->actingAs($this->admin)
            ->get(route('orders.dashboard', ['preset' => 'all']))
            ->assertOk()
            ->assertSee('nothing readable')
            ->assertSee('Try 2024 onwards');
    }

    public function test_a_missing_token_is_reported_as_configuration(): void
    {
        config(['services.orders_api.token' => null]);
        Http::fake();

        $this->actingAs($this->admin)
            ->get(route('orders.dashboard'))
            ->assertOk()
            ->assertSee('Set ORDERS_API_TOKEN');

        Http::assertNothingSent();
    }

    // ── Tabs ─────────────────────────────────────────────────────────────────

    public function test_the_orders_tab_is_the_default(): void
    {
        $this->fakeOk();

        $this->actingAs($this->admin)
            ->get(route('orders.dashboard'))
            ->assertOk()
            ->assertSee('Daily orders and revenue')
            ->assertDontSee('Workload — last 14 days', false);
    }

    /**
     * The studio tab shows the workspace picture on its own fortnightly clock,
     * so calling the orders endpoint for it would be two round trips spent on
     * numbers the page does not show.
     */
    public function test_the_studio_tab_shows_the_workspace_dashboard_without_calling_the_api(): void
    {
        $this->fakeOk();

        $this->actingAs($this->admin)
            ->get(route('orders.dashboard', ['tab' => 'studio']))
            ->assertOk()
            ->assertSee('Workload — last 14 days', false)
            ->assertDontSee('Daily orders and revenue');

        Http::assertNothingSent();
    }

    /** Both tabs are reachable and neither is rendered on top of the other. */
    public function test_an_unknown_tab_falls_back_to_orders(): void
    {
        $this->fakeOk();

        $this->actingAs($this->admin)
            ->get(route('orders.dashboard', ['tab' => 'nonsense']))
            ->assertOk()
            ->assertSee('Daily orders and revenue');
    }

    /** The studio tab renders for someone who owns none of the other tools. */
    public function test_the_studio_tab_renders_with_no_module_permissions(): void
    {
        $viewer = $this->grant(User::create([
            'name' => 'Mei Lin', 'email' => 'mei2@example.test',
            'password' => 'password', 'is_active' => true,
        ]));

        $this->actingAs($viewer)
            ->get(route('orders.dashboard', ['tab' => 'studio']))
            ->assertOk()
            ->assertSee('Workload — last 14 days', false);
    }

    // ── Product creation ─────────────────────────────────────────────────────

    public function test_the_product_creation_panel_counts_requests_skus_and_uploads(): void
    {
        $this->fakeOk();

        $open = $this->request(['status' => ProductRequest::WAITING_MAPPING, 'total_skus' => 30, 'mapped_skus' => 18, 'pending_skus' => 7, 'not_mapped_skus' => 5]);
        $this->request(['status' => ProductRequest::PUBLISHED, 'total_skus' => 12, 'mapped_skus' => 12, 'published_at' => now()->subDay()]);
        $this->request(['status' => ProductRequest::SUBMITTED, 'total_skus' => 8, 'on_hold' => true]);

        ProductRequestDraftProduct::create([
            'product_request_id' => $open->id, 'handle' => 'a-shirt', 'title' => 'A Shirt',
            'push_status' => ProductRequestDraftProduct::PUSHED, 'pushed_at' => now()->subHours(2),
        ]);
        ProductRequestDraftProduct::create([
            'product_request_id' => $open->id, 'handle' => 'b-shirt', 'title' => 'B Shirt',
            'push_status' => ProductRequestDraftProduct::FAILED,
        ]);

        $this->actingAs($this->admin)
            ->get(route('orders.dashboard'))
            ->assertOk()
            ->assertSee('Product creation')
            ->assertSee('Waiting on brand managers to map')
            ->assertSee('Open by stage')
            ->assertSee('Waiting for Mapping');
    }

    /**
     * Requested and published are counted inside the range; what is still open
     * is counted as it stands, or a request stuck since June disappears the
     * moment somebody looks at this week.
     */
    public function test_open_requests_are_counted_outside_the_date_range(): void
    {
        $this->fakeOk();

        $this->request(['status' => ProductRequest::WAITING_MAPPING, 'created_at' => now()->subYear()]);

        $response = $this->actingAs($this->admin)
            ->get(route('orders.dashboard', ['preset' => 'today']))
            ->assertOk();

        // Raised long before the range, still shown as open work.
        $this->assertStringContainsString('Waiting for Mapping', $response->getContent());
    }

    private function request(array $attributes = []): ProductRequest
    {
        static $n = 0;
        $n++;

        return ProductRequest::create(array_merge([
            'reference' => 'PCR-2026-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT),
            'user_id'   => $this->admin->id,
            'brand'     => 'Bluesalon',
            'category'  => 'Fashion',
            'status'    => ProductRequest::SUBMITTED,
            'priority'  => 'medium',
        ], $attributes));
    }
}
