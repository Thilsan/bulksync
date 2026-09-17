<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One row of visitor and session figures per website, from Google Analytics.
 *
 * Shopify knows what a website sold and nothing about who visited it, so the
 * traffic half of the picture comes from here. The two are deliberately kept
 * apart: a store can report sales with no analytics property, or traffic with
 * a Shopify token that cannot read orders, and neither failure should take
 * the other's figures down with it.
 *
 * A website that cannot answer is reported rather than dropped, for the same
 * reason as the sales tab: an absent row reads as "nobody visited", which is
 * a different and much worse claim than "this website is not wired up".
 */
class Ga4AnalyticsService
{
    private const CACHE_TTL_MINUTES = 15;

    /** How many landing pages, channels and countries fit on a card. */
    private const TOP_ROWS = 6;

    /**
     * The market these websites actually sell into.
     *
     * It is pinned onto the location list wherever it lands. A property
     * carrying datacentre traffic ranks its home market nowhere near the top
     * — on one of these, Qatar sits behind Singapore, Brazil, Mexico and
     * Chile — so sorting by sessions alone drops the only line anybody opened
     * the card to read.
     */
    private const HOME_COUNTRY = 'Qatar';

    /**
     * Countries are fetched far deeper than they are shown, so the home
     * market can be found however far down the order it has fallen.
     */
    private const LOCATION_FETCH = 100;

    /** @var callable(string, array): array */
    private $reporter;

    /**
     * Takes a plain callable rather than a Ga4Client type hint so tests can
     * answer with a canned payload without a key or a network.
     */
    public function __construct(?callable $reporter = null)
    {
        $this->reporter = $reporter ?? fn (string $propertyId, array $requests) => app(Ga4Client::class)->batchReport($propertyId, $requests);
    }

    /** @param  Collection<int, Store>  $stores */
    public function forStores(Collection $stores, Carbon $from, Carbon $to): array
    {
        return $stores->map(fn (Store $store) => $this->forStore($store, $from, $to))->all();
    }

    private function forStore(Store $store, Carbon $from, Carbon $to): array
    {
        $base = ['store' => $store->name, 'store_id' => $store->id];

        if (! $store->ga4_property_id) {
            return $base + [
                'status'  => 'not_configured',
                'message' => 'No GA4 property is set for this website.',
            ];
        }

        $key = sprintf('ga4_analytics.%d.%s.%s', $store->id, $from->toDateString(), $to->toDateString());

        try {
            $data = Cache::remember($key, now()->addMinutes(self::CACHE_TTL_MINUTES), function () use ($store, $from, $to) {
                return $this->shape(
                    ($this->reporter)($store->ga4_property_id, $this->requests($from, $to)),
                );
            });
        } catch (\Throwable $e) {
            Log::warning("GA4 analytics failed for store {$store->id}: " . $e->getMessage());

            // The key is gitignored and so does not travel with a deploy.
            // Matched on the wording Ga4Client throws, which is two files
            // away in this same codebase.
            if (str_contains($e->getMessage(), 'No Google Analytics credentials')) {
                $path = (string) config('services.ga4.credentials');

                return $base + [
                    'status'  => 'no_credentials',
                    // An empty path is not a missing file: it is a config that
                    // never loaded, which on a deployed server means a config
                    // cache built before this setting existed. Saying "copy
                    // the key" there sends somebody to check a file that is
                    // already sitting exactly where they put it.
                    'message' => $path === ''
                        ? 'No Google Analytics key path is configured. This is usually a cached config built before the setting existed — run config:clear and config:cache on this server.'
                        : "The Google Analytics key could not be read at {$path}. Copy the service account JSON there, check the web user can read it and traverse the folders above it, or point GA4_CREDENTIALS_PATH somewhere else.",
                ];
            }

            // Google answers both "never shared with this account" and "that
            // is not a property id" with the same 403, so the message names
            // both rather than guessing at which one it was.
            if ($e->getCode() === 403 || str_contains($e->getMessage(), 'HTTP 403')) {
                return $base + [
                    'status'  => 'no_access',
                    'message' => 'Add the service account as a Viewer on this property, and check the ID is the GA4 property ID rather than a Universal Analytics one.',
                ];
            }

            // Blocked egress, a clock too far out for Google to accept the
            // signed request, and a rejected query all land here and read
            // identically without it. Trimmed, because a Guzzle failure runs
            // to paragraphs and this is a line on a card.
            return $base + [
                'status'  => 'unavailable',
                'message' => 'Google Analytics could not be reached: ' . Str::limit($e->getMessage(), 240),
            ];
        }

        return $base + $data + ['status' => 'ok'];
    }

    /**
     * The four questions asked of every property, in one batch.
     *
     * Order matters: shape() reads the answers back by position, which is how
     * the API returns them.
     */
    private function requests(Carbon $from, Carbon $to): array
    {
        $range = [['startDate' => $from->toDateString(), 'endDate' => $to->toDateString()]];

        $breakdown = fn (string $dimension, int $limit) => [
            'dateRanges' => $range,
            'dimensions' => [['name' => $dimension]],
            'metrics'    => [['name' => 'sessions']],
            'orderBys'   => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
            'limit'      => $limit,
        ];

        return [
            [
                'dateRanges' => $range,
                'metrics'    => [
                    ['name' => 'sessions'],
                    ['name' => 'totalUsers'],
                    ['name' => 'newUsers'],
                    ['name' => 'screenPageViews'],
                ],
            ],
            // Every device category is worth showing; there are only ever a
            // handful and a missing one is a question in itself.
            $breakdown('deviceCategory', 10),
            $breakdown('landingPagePlusQueryString', self::TOP_ROWS),
            $breakdown('sessionDefaultChannelGroup', self::TOP_ROWS),
            $breakdown('country', self::LOCATION_FETCH),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $reports  as batchReport returns them
     */
    private function shape(array $reports): array
    {
        // The totals report carries one dimensionless row, and an empty range
        // carries none at all.
        $totals = $reports[0]['rows'][0]['metricValues'] ?? [];

        $metric = fn (int $i) => (int) ($totals[$i]['value'] ?? 0);

        return [
            'sessions'        => $metric(0),
            'users'           => $metric(1),
            'new_users'       => $metric(2),
            'page_views'      => $metric(3),
            'by_device'       => $this->breakdown($reports[1] ?? []),
            'by_landing_page' => $this->breakdown($reports[2] ?? []),
            'by_channel'      => $this->breakdown($reports[3] ?? []),
            'by_location'     => $this->withHomeCountry($this->breakdown($reports[4] ?? [])),
        ];
    }

    /**
     * The busiest countries, with the home market kept whatever its rank.
     *
     * A home market with no sessions at all still gets a line reading zero.
     * That is a fact worth stating on a card about a website that sells
     * there, and it is not the same as the row quietly not being drawn.
     *
     * @param  list<array{label: string, sessions: int}>  $rows  busiest first
     */
    private function withHomeCountry(array $rows): array
    {
        $shown = array_slice($rows, 0, self::TOP_ROWS);

        foreach ($shown as $row) {
            if ($row['label'] === self::HOME_COUNTRY) {
                return $shown;
            }
        }

        $home = null;

        foreach ($rows as $row) {
            if ($row['label'] === self::HOME_COUNTRY) {
                $home = $row;
                break;
            }
        }

        $shown[] = $home ?? ['label' => self::HOME_COUNTRY, 'sessions' => 0];

        return $shown;
    }

    /**
     * One dimension's rows as label/sessions pairs. Every value the API sends
     * is a string, including the numbers.
     *
     * @param  array<string, mixed>  $report
     */
    private function breakdown(array $report): array
    {
        return array_values(array_map(function ($row) {
            // A real property's busiest landing page came back with an empty
            // label — not null, which is why it slipped through — and drew a
            // bar of sessions belonging to nothing.
            $label = trim((string) ($row['dimensionValues'][0]['value'] ?? ''));

            return [
                'label'    => $label === '' ? 'Unknown' : $label,
                'sessions' => (int) ($row['metricValues'][0]['value'] ?? 0),
            ];
        }, $report['rows'] ?? []));
    }
}
