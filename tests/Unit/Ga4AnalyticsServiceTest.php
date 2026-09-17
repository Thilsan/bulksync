<?php

namespace Tests\Unit;

use App\Models\Store;
use App\Services\Ga4AnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * One website's visitors and sessions, from Google Analytics.
 *
 * The payloads here are shaped from what the Data API actually answered for a
 * live property — metric values arrive as strings, totals come back as a
 * single dimensionless row — rather than from how the documentation describes
 * it. A mock built on an assumption is how the Shopify channel breakdown
 * passed its tests while failing in production.
 */
class Ga4AnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function store(array $overrides = []): Store
    {
        return Store::create(array_merge([
            'name'            => 'Blue Salon',
            'shopify_domain'  => 'bluesalon.myshopify.com',
            'ga4_property_id' => '307311411',
        ], $overrides));
    }

    /** @param  list<list<array{dims?: list<string>, metrics: list<string>}>>  $reports */
    private function answer(array $reports): array
    {
        return array_map(fn ($rows) => [
            'rows' => array_map(fn ($row) => [
                'dimensionValues' => array_map(fn ($d) => ['value' => $d], $row['dims'] ?? []),
                'metricValues'    => array_map(fn ($m) => ['value' => $m], $row['metrics']),
            ], $rows),
            'rowCount' => count($rows),
        ], $reports);
    }

    /** A reporter standing in for the live API, answering the four batched reports. */
    private function reporter(array $reports): callable
    {
        return fn (string $propertyId, array $requests) => $this->answer($reports);
    }

    private function service(callable $reporter): Ga4AnalyticsService
    {
        return new Ga4AnalyticsService($reporter);
    }

    private function fullAnswer(): array
    {
        return [
            // totals: one row, no dimensions
            [['metrics' => ['1111084', '1119111', '1089153', '1339429']]],
            // by device
            [
                ['dims' => ['desktop'], 'metrics' => ['1053948']],
                ['dims' => ['mobile'], 'metrics' => ['67213']],
            ],
            // by landing page
            [
                ['dims' => ['/'], 'metrics' => ['37246']],
                ['dims' => ['/pages/exceptional-offers'], 'metrics' => ['6049']],
            ],
            // by channel
            [
                ['dims' => ['Direct'], 'metrics' => ['1025485']],
                ['dims' => ['Paid Search'], 'metrics' => ['11821']],
            ],
            // by location
            [
                ['dims' => ['Singapore'], 'metrics' => ['858912']],
                ['dims' => ['Qatar'], 'metrics' => ['73284']],
            ],
        ];
    }

    private function rows(Ga4AnalyticsService $service, Store $store): array
    {
        return $service->forStores(
            collect([$store]),
            Carbon::parse('2026-08-18'),
            Carbon::parse('2026-09-16'),
        );
    }

    public function test_a_configured_store_carries_its_sessions_and_visitors(): void
    {
        $rows = $this->rows($this->service($this->reporter($this->fullAnswer())), $this->store());

        $this->assertSame('ok', $rows[0]['status']);
        $this->assertSame(1111084, $rows[0]['sessions']);
        $this->assertSame(1119111, $rows[0]['users']);
        $this->assertSame(1089153, $rows[0]['new_users']);
        $this->assertSame(1339429, $rows[0]['page_views']);
    }

    public function test_the_breakdowns_are_carried_through_in_order(): void
    {
        $rows = $this->rows($this->service($this->reporter($this->fullAnswer())), $this->store());

        $this->assertSame('desktop', $rows[0]['by_device'][0]['label']);
        $this->assertSame(1053948, $rows[0]['by_device'][0]['sessions']);

        $this->assertSame('/', $rows[0]['by_landing_page'][0]['label']);
        $this->assertSame(37246, $rows[0]['by_landing_page'][0]['sessions']);

        $this->assertSame('Direct', $rows[0]['by_channel'][0]['label']);
        $this->assertSame(1025485, $rows[0]['by_channel'][0]['sessions']);

        $this->assertSame('Singapore', $rows[0]['by_location'][0]['label']);
        $this->assertSame(858912, $rows[0]['by_location'][0]['sessions']);
    }

    /**
     * The home market is the one figure on this panel anybody is actually
     * looking for, and on a property carrying datacentre traffic it is
     * nowhere near the top by volume. Sorted purely by sessions it drops off
     * the card entirely.
     */
    public function test_the_home_market_is_kept_even_when_it_is_not_among_the_busiest(): void
    {
        $reports = $this->fullAnswer();
        $reports[4] = [
            ['dims' => ['Singapore'], 'metrics' => ['858912']],
            ['dims' => ['Brazil'], 'metrics' => ['138906']],
            ['dims' => ['Mexico'], 'metrics' => ['54499']],
            ['dims' => ['Chile'], 'metrics' => ['43859']],
            ['dims' => ['Pakistan'], 'metrics' => ['42115']],
            ['dims' => ['India'], 'metrics' => ['38000']],
            ['dims' => ['Vietnam'], 'metrics' => ['21000']],
            ['dims' => ['Qatar'], 'metrics' => ['7331']],
        ];

        $rows = $this->rows($this->service($this->reporter($reports)), $this->store());

        $labels = array_column($rows[0]['by_location'], 'label');

        $this->assertContains('Qatar', $labels);
        $this->assertSame(7331, collect($rows[0]['by_location'])->firstWhere('label', 'Qatar')['sessions']);

        // Qatar is kept because it is the home market, not because the list
        // is long enough to reach it: Vietnam outranks Qatar and is still cut,
        // while the six genuinely busiest are all kept.
        $this->assertNotContains('Vietnam', $labels);
        $this->assertContains('Singapore', $labels);
        $this->assertContains('India', $labels);
        $this->assertCount(7, $labels);
    }

    public function test_the_home_market_is_not_listed_twice_when_already_among_the_busiest(): void
    {
        $rows = $this->rows($this->service($this->reporter($this->fullAnswer())), $this->store());

        $labels = array_column($rows[0]['by_location'], 'label');

        $this->assertSame(1, count(array_keys($labels, 'Qatar')));
    }

    /** No sessions from the home market is itself worth saying out loud. */
    public function test_the_home_market_reads_zero_rather_than_disappearing(): void
    {
        $reports = $this->fullAnswer();
        $reports[4] = [['dims' => ['Singapore'], 'metrics' => ['858912']]];

        $rows = $this->rows($this->service($this->reporter($reports)), $this->store());

        $qatar = collect($rows[0]['by_location'])->firstWhere('label', 'Qatar');

        $this->assertNotNull($qatar);
        $this->assertSame(0, $qatar['sessions']);
    }

    /**
     * Five is the most the API takes in one batch, and this tab asks for
     * exactly five. A sixth would need splitting into a second call, so the
     * count is worth holding onto.
     */
    public function test_every_report_travels_in_a_single_batch(): void
    {
        $asked = new class { public array $requests = []; };

        $service = $this->service(function (string $propertyId, array $requests) use ($asked) {
            $asked->requests = $requests;

            return $this->answer($this->fullAnswer());
        });

        $this->rows($service, $this->store());

        $this->assertLessThanOrEqual(5, count($asked->requests));

        $dimensions = array_map(
            fn ($request) => $request['dimensions'][0]['name'] ?? 'totals',
            $asked->requests,
        );

        $this->assertSame(
            ['totals', 'deviceCategory', 'landingPagePlusQueryString', 'sessionDefaultChannelGroup', 'country'],
            $dimensions,
        );
    }

    /**
     * The busiest landing page on a real property came back with an empty
     * label, which rendered as a row of sessions attached to nothing. Null
     * was already handled; an empty string is a different value and was not.
     */
    public function test_a_blank_dimension_label_is_given_a_name(): void
    {
        $reports = $this->fullAnswer();
        $reports[2] = [['dims' => [''], 'metrics' => ['44307']]];

        $rows = $this->rows($this->service($this->reporter($reports)), $this->store());

        $this->assertSame('Unknown', $rows[0]['by_landing_page'][0]['label']);
        $this->assertSame(44307, $rows[0]['by_landing_page'][0]['sessions']);
    }

    public function test_a_store_with_no_property_id_is_reported_rather_than_dropped(): void
    {
        $service = $this->service(fn () => throw new \LogicException('should never be called'));

        $rows = $this->rows($service, $this->store(['ga4_property_id' => null]));

        $this->assertSame('not_configured', $rows[0]['status']);
    }

    /**
     * A 403 is the one failure worth naming: it means the property exists but
     * this service account was never let into it, which is a thing somebody
     * can go and fix, unlike a network wobble.
     */
    public function test_a_refused_property_is_reported_as_needing_access(): void
    {
        $service = $this->service(function () {
            throw new \RuntimeException('GA4 report failed (HTTP 403): User does not have sufficient permissions for this property.', 403);
        });

        $rows = $this->rows($service, $this->store());

        $this->assertSame('no_access', $rows[0]['status']);
        $this->assertStringContainsString('Viewer', $rows[0]['message']);
    }

    /**
     * The key is gitignored, so it does not travel with a deploy — the first
     * time this ran on the live server every website read "Unavailable",
     * which gives whoever is looking at it nowhere to start.
     */
    public function test_a_missing_key_on_this_server_says_so(): void
    {
        $service = $this->service(
            fn () => throw new \RuntimeException('No Google Analytics credentials at /app/storage/app/google/analytics.json.'),
        );

        $rows = $this->rows($service, $this->store());

        $this->assertSame('no_credentials', $rows[0]['status']);
        $this->assertStringContainsString('storage/app/google', $rows[0]['message']);
    }

    /**
     * The key sat exactly where the message told somebody to put it while the
     * app was reading a config that had no such setting in it. Naming the
     * path actually consulted is the difference between that being obvious
     * and being an afternoon.
     */
    public function test_the_missing_key_message_names_the_path_it_looked_at(): void
    {
        config(['services.ga4.credentials' => '/srv/releases/07/storage/app/google/analytics.json']);

        $service = $this->service(
            fn () => throw new \RuntimeException('No Google Analytics credentials at /srv/releases/07/storage/app/google/analytics.json.'),
        );

        $rows = $this->rows($service, $this->store());

        $this->assertStringContainsString('/srv/releases/07/storage/app/google/analytics.json', $rows[0]['message']);
    }

    /** An empty path is a config that never loaded, not a file that is absent. */
    public function test_an_unset_credentials_config_says_so_in_its_own_words(): void
    {
        config(['services.ga4.credentials' => null]);

        $service = $this->service(
            fn () => throw new \RuntimeException('No Google Analytics credentials at .'),
        );

        $rows = $this->rows($service, $this->store());

        $this->assertSame('no_credentials', $rows[0]['status']);
        $this->assertStringContainsString('config', $rows[0]['message']);
    }

    public function test_any_other_failure_is_reported_without_breaking_the_page(): void
    {
        $service = $this->service(fn () => throw new \RuntimeException('connection reset'));

        $rows = $this->rows($service, $this->store());

        $this->assertSame('unavailable', $rows[0]['status']);
    }

    /**
     * "Could not be reached" covers a blocked firewall, a clock too far out
     * for Google to accept the signed request, and a rejected query alike.
     * Carrying the reason through is the difference between reading the
     * screen and reading the logs.
     */
    public function test_an_unreachable_google_carries_its_reason_to_the_screen(): void
    {
        $service = $this->service(
            fn () => throw new \RuntimeException('cURL error 6: Could not resolve host: oauth2.googleapis.com'),
        );

        $rows = $this->rows($service, $this->store());

        $this->assertSame('unavailable', $rows[0]['status']);
        $this->assertStringContainsString('Could not resolve host', $rows[0]['message']);
    }

    /** A wall of Guzzle output does not belong on a dashboard card. */
    public function test_a_long_failure_is_trimmed_rather_than_printed_whole(): void
    {
        $service = $this->service(fn () => throw new \RuntimeException(str_repeat('a very long failure. ', 100)));

        $rows = $this->rows($service, $this->store());

        $this->assertLessThan(320, strlen($rows[0]['message']));
    }

    public function test_a_second_look_at_the_same_range_does_not_ask_again(): void
    {
        $counter = new class { public int $calls = 0; };
        $answer  = $this->fullAnswer();

        $service = $this->service(function () use ($counter, $answer) {
            $counter->calls++;

            return $this->answer($answer);
        });

        $store = $this->store();
        $this->rows($service, $store);
        $this->rows($service, $store);

        $this->assertSame(1, $counter->calls);
    }
}
