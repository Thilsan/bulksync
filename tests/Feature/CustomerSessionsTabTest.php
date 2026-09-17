<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\User;
use App\Services\Ga4AnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Customer Sessions tab: visitors and sessions per website, from Google
 * Analytics. Ga4AnalyticsService is swapped for a fake in the container, so
 * nothing here reaches Google.
 */
class CustomerSessionsTabTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Ada Okonkwo', 'email' => 'ada@example.test',
            'password' => 'password', 'is_active' => true, 'is_super_admin' => true,
        ]);
    }

    /** Answers the four batched reports the service asks for, in order. */
    private function fakeReporter(int $sessions = 1000): callable
    {
        $row = fn (array $dims, string $value) => [
            'dimensionValues' => array_map(fn ($d) => ['value' => $d], $dims),
            'metricValues'    => [['value' => $value]],
        ];

        return fn (string $property, array $requests) => [
            ['rows' => [['metricValues' => [
                ['value' => (string) $sessions],
                ['value' => '900'],
                ['value' => '800'],
                ['value' => '2500'],
            ]]]],
            ['rows' => [$row(['desktop'], '600'), $row(['mobile'], '400')]],
            ['rows' => [$row(['/summer-sale'], '120')]],
            ['rows' => [$row(['Organic Search'], '700')]],
        ];
    }

    public function test_the_tab_shows_sessions_and_visitors_per_website(): void
    {
        Store::create([
            'name' => 'Blue Salon', 'shopify_domain' => 'bluesalon.myshopify.com',
            'ga4_property_id' => '307311411',
        ]);

        $this->app->instance(Ga4AnalyticsService::class, new Ga4AnalyticsService($this->fakeReporter()));

        $response = $this->actingAs($this->admin)->get('/management-dashboard?tab=sessions');

        $response->assertOk();
        $response->assertSee('data-tab="sessions"', false);
        $response->assertSee('Customer Sessions');
        $response->assertSee('Blue Salon');
        $response->assertSee('1,000');          // sessions
        $response->assertSee('900');            // visitors
        $response->assertSee('desktop');
        $response->assertSee('Organic Search');
        $response->assertSee('/summer-sale');
    }

    /** Its own dates, like the other tabs — and submitting must stay on this tab. */
    public function test_the_tab_carries_its_own_date_filter(): void
    {
        Store::create([
            'name' => 'Blue Salon', 'shopify_domain' => 'bluesalon.myshopify.com',
            'ga4_property_id' => '307311411',
        ]);

        $this->app->instance(Ga4AnalyticsService::class, new Ga4AnalyticsService($this->fakeReporter()));

        $response = $this->actingAs($this->admin)
            ->get('/management-dashboard?tab=sessions&preset=custom&from=2026-08-01&to=2026-08-31');

        $response->assertOk();
        $response->assertSee('name="tab" value="sessions"', false);
        $response->assertSee('value="2026-08-01"', false);
    }

    /**
     * A tab that has to ask Shopify or Google for an uncached range takes
     * seconds, and a plain link gives no sign it was even clicked.
     */
    public function test_switching_tabs_puts_the_page_into_a_loading_state(): void
    {
        Store::create([
            'name' => 'Blue Salon', 'shopify_domain' => 'bluesalon.myshopify.com',
            'ga4_property_id' => '307311411',
        ]);

        $this->app->instance(Ga4AnalyticsService::class, new Ga4AnalyticsService($this->fakeReporter()));

        $response = $this->actingAs($this->admin)->get('/management-dashboard?tab=sessions');

        $response->assertOk();

        // The tab being left marks itself busy on the way out...
        $response->assertSee("busy = true; going = 'analytics'", false);
        // ...the tab already open does not, since it goes nowhere.
        $response->assertDontSee("going = 'sessions'", false);
        // ...and the skeleton is on the page ready to take over.
        $response->assertSee('aria-busy="true"', false);
    }

    /**
     * A website with no property has not had no visitors — it has not been
     * asked. The card has to say which.
     */
    public function test_a_website_without_a_property_says_so_rather_than_showing_zero(): void
    {
        Store::create(['name' => 'Gold Gourmet', 'shopify_domain' => 'gg.myshopify.com']);

        $response = $this->actingAs($this->admin)->get('/management-dashboard?tab=sessions');

        $response->assertOk();
        $response->assertSee('Gold Gourmet');
        $response->assertSee('No GA4 property set');
    }

    public function test_a_property_the_service_account_cannot_read_is_named_as_such(): void
    {
        Store::create([
            'name' => 'Mosafer', 'shopify_domain' => 'mosafer.myshopify.com',
            'ga4_property_id' => '999999999',
        ]);

        $this->app->instance(Ga4AnalyticsService::class, new Ga4AnalyticsService(
            fn () => throw new \RuntimeException('GA4 report failed (HTTP 403): User does not have sufficient permissions for this property.', 403),
        ));

        $response = $this->actingAs($this->admin)->get('/management-dashboard?tab=sessions');

        $response->assertOk();
        $response->assertSee('No access to this property');
        $response->assertSee('Viewer');
    }
}
