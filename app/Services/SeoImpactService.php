<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Organic search sessions per product URL, for a date range.
 *
 * This is the half of the SEO work that says whether any of it mattered. The
 * AI Content Generator writes titles and descriptions; this reads back what
 * Google sent to those pages, so the spend can be argued about with numbers.
 */
class SeoImpactService
{
    /**
     * GA4's name for search traffic it did not charge for. Paid search is a
     * different channel and would credit a rewritten meta description with
     * clicks that were bought.
     */
    private const ORGANIC_CHANNEL = 'Organic Search';

    /** Shopify serves every product from this prefix. */
    private const PRODUCT_PATH = '/products/';

    /**
     * Rows per report. A large catalogue can have tens of thousands of product
     * URLs with at least one organic session in a month; GA4 caps a single
     * report at 100,000 rows.
     */
    private const ROW_LIMIT = 100000;

    /** @var callable */
    private $reporter;

    /**
     * Takes a plain callable rather than a Ga4Client type hint so tests can
     * answer with a canned payload without a key or a network — the same seam
     * Ga4AnalyticsService uses.
     */
    public function __construct(?callable $reporter = null)
    {
        $this->reporter = $reporter ?? fn (string $propertyId, array $requests) => app(Ga4Client::class)->batchReport($propertyId, $requests);
    }

    /**
     * Organic sessions per product handle across two ranges, in one call.
     *
     * @return array{before: array<string, int>, after: array<string, int>}
     */
    public function sessionsByHandle(
        string $propertyId,
        Carbon $beforeFrom,
        Carbon $beforeTo,
        Carbon $afterFrom,
        Carbon $afterTo,
    ): array {
        $reports = ($this->reporter)($propertyId, [
            $this->request($beforeFrom, $beforeTo),
            $this->request($afterFrom, $afterTo),
        ]);

        return [
            'before' => $this->foldToHandles($reports[0] ?? []),
            'after'  => $this->foldToHandles($reports[1] ?? []),
        ];
    }

    private function request(Carbon $from, Carbon $to): array
    {
        return [
            'dateRanges' => [['startDate' => $from->toDateString(), 'endDate' => $to->toDateString()]],
            'dimensions' => [['name' => 'landingPagePlusQueryString']],
            'metrics'    => [['name' => 'sessions']],
            'dimensionFilter' => [
                'andGroup' => ['expressions' => [
                    ['filter' => [
                        'fieldName'    => 'sessionDefaultChannelGroup',
                        'stringFilter' => ['matchType' => 'EXACT', 'value' => self::ORGANIC_CHANNEL],
                    ]],
                    ['filter' => [
                        'fieldName'    => 'landingPagePlusQueryString',
                        'stringFilter' => ['matchType' => 'BEGINS_WITH', 'value' => self::PRODUCT_PATH],
                    ]],
                ]],
            ],
            'limit' => self::ROW_LIMIT,
        ];
    }

    /**
     * Landing-page rows folded down to one total per handle.
     *
     * The same product arrives under several rows — with a tracking query
     * string, with a collection prefix, with a trailing slash — and all of them
     * are the same page to a merchant, so they are summed rather than competing
     * for the one that happens to be read first.
     *
     * @return array<string, int>
     */
    private function foldToHandles(array $report): array
    {
        $totals = [];

        foreach ($report['rows'] ?? [] as $row) {
            $path   = (string) ($row['dimensionValues'][0]['value'] ?? '');
            $handle = $this->handleFromPath($path);

            if ($handle === null) {
                continue;
            }

            $totals[$handle] = ($totals[$handle] ?? 0) + (int) ($row['metricValues'][0]['value'] ?? 0);
        }

        return $totals;
    }

    /**
     * "/collections/shirts/products/linen-shirt?utm_source=x" -> "linen-shirt".
     *
     * Returns null for anything that is not a product URL, including the
     * "(not set)" and "(other)" rows GA4 adds when it cannot attribute or has
     * run out of room.
     */
    private function handleFromPath(string $path): ?string
    {
        $position = strpos($path, self::PRODUCT_PATH);

        if ($position === false) {
            return null;
        }

        $handle = substr($path, $position + strlen(self::PRODUCT_PATH));

        // Everything after the handle is somebody else's: a query string, a
        // fragment, a variant path segment, or a trailing slash.
        $handle = preg_split('/[?#\/]/', $handle)[0] ?? '';

        return $handle !== '' ? strtolower($handle) : null;
    }
}
