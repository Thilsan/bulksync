<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * What Google's search results did for a store's pages: impressions, clicks,
 * click-through rate, average position — and the queries people actually typed.
 *
 * This is the diagnostic layer the impact report was missing. Analytics can say
 * that sessions rose; only this can say whether that was because the page
 * climbed the results or because a better description persuaded more of the
 * same audience to click.
 */
class SearchConsoleService
{
    /** Shopify serves every product from this prefix, and collections from theirs. */
    private const PRODUCT_PATH    = '/products/';
    private const COLLECTION_PATH = '/collections/';

    /** Search Console caps a query at 25,000 rows. */
    private const ROW_LIMIT = 25000;

    /**
     * Search Console has no data for roughly the last three days, and returns
     * a partial answer rather than an error for them. Windows are pulled back
     * by this much so a comparison is never half-populated.
     */
    public const LAG_DAYS = 3;

    /** @var callable */
    private $reporter;

    /**
     * Takes a plain callable rather than a client type hint so tests can answer
     * with canned rows without a key or a network — the same seam the analytics
     * services use.
     */
    public function __construct(?callable $reporter = null)
    {
        $this->reporter = $reporter ?? fn (string $siteUrl, array $request) => app(SearchConsoleClient::class)->query($siteUrl, $request);
    }

    /**
     * Per-page search performance across a range, keyed by product or
     * collection handle.
     *
     * @return array<string, array{impressions: int, clicks: int, ctr: float, position: float}>
     */
    public function performanceByHandle(string $siteUrl, Carbon $from, Carbon $to): array
    {
        $rows = ($this->reporter)($siteUrl, [
            'startDate'  => $from->toDateString(),
            'endDate'    => $to->toDateString(),
            'dimensions' => ['page'],
            'rowLimit'   => self::ROW_LIMIT,
        ]);

        $totals = [];

        foreach ($rows as $row) {
            $handle = $this->handleFromUrl((string) ($row['keys'][0] ?? ''));

            if ($handle === null) {
                continue;
            }

            $impressions = (int) ($row['impressions'] ?? 0);
            $clicks      = (int) ($row['clicks'] ?? 0);
            $position    = (float) ($row['position'] ?? 0);

            $existing = $totals[$handle] ?? ['impressions' => 0, 'clicks' => 0, 'weighted_position' => 0.0];

            // Positions are averaged by impression, not by row: a URL variant
            // shown twice must not weigh as heavily as one shown ten thousand
            // times when the two are folded together.
            $totals[$handle] = [
                'impressions'       => $existing['impressions'] + $impressions,
                'clicks'            => $existing['clicks'] + $clicks,
                'weighted_position' => $existing['weighted_position'] + ($position * $impressions),
            ];
        }

        return array_map(fn (array $t) => [
            'impressions' => $t['impressions'],
            'clicks'      => $t['clicks'],
            'ctr'         => $t['impressions'] > 0 ? round($t['clicks'] / $t['impressions'] * 100, 2) : 0.0,
            'position'    => $t['impressions'] > 0 ? round($t['weighted_position'] / $t['impressions'], 1) : 0.0,
        ], $totals);
    }

    /**
     * The search terms one page is already being shown for, best first.
     *
     * This is what turns generated content from a description of a photograph
     * into an answer to something somebody typed — the model otherwise has no
     * idea what anyone is looking for.
     *
     * @return list<array{query: string, impressions: int, clicks: int, position: float}>
     */
    public function topQueriesForPage(string $siteUrl, string $pageUrl, Carbon $from, Carbon $to, int $limit = 10): array
    {
        $rows = ($this->reporter)($siteUrl, [
            'startDate'  => $from->toDateString(),
            'endDate'    => $to->toDateString(),
            'dimensions' => ['query'],
            'dimensionFilterGroups' => [[
                'filters' => [[
                    'dimension'  => 'page',
                    'operator'   => 'contains',
                    'expression' => $pageUrl,
                ]],
            ]],
            'rowLimit' => $limit,
        ]);

        return array_map(fn (array $row) => [
            'query'       => (string) ($row['keys'][0] ?? ''),
            'impressions' => (int) ($row['impressions'] ?? 0),
            'clicks'      => (int) ($row['clicks'] ?? 0),
            'position'    => round((float) ($row['position'] ?? 0), 1),
        ], $rows);
    }

    /**
     * The last full day Search Console can answer for.
     *
     * Asking for today returns a window that is mostly empty and looks like a
     * collapse in traffic rather than a reporting delay.
     */
    public static function latestCompleteDay(): Carbon
    {
        return now()->subDays(self::LAG_DAYS)->endOfDay();
    }

    /**
     * "https://shop.com/collections/x/products/linen-shirt?v=2" -> "linen-shirt".
     *
     * Collections resolve to their own handle. Anything that is neither
     * returns null — the home page and policy pages are not being measured.
     */
    private function handleFromUrl(string $url): ?string
    {
        // A product path wins over a collection one: "/collections/bags/
        // products/tote" is the tote's page, not the collection's.
        foreach ([self::PRODUCT_PATH, self::COLLECTION_PATH] as $prefix) {
            $position = strrpos($url, $prefix);

            if ($position === false) {
                continue;
            }

            $handle = substr($url, $position + strlen($prefix));
            $handle = preg_split('/[?#\/]/', $handle)[0] ?? '';

            if ($handle !== '') {
                return strtolower($handle);
            }
        }

        return null;
    }
}
