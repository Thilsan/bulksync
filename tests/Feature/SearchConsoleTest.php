<?php

namespace Tests\Feature;

use App\Services\SearchConsoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Search Console answers a different question from Analytics: not how many
 * people arrived, but how many were shown the page and chose to click.
 *
 * What is worth holding still is the folding — one product reached by half a
 * dozen URL shapes is one page to a merchant — and the arithmetic that goes
 * with it, which is easy to get subtly wrong in a way nobody notices.
 */
class SearchConsoleTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, array{0:int,1:int,2:float}> $pages */
    private function service(array $pages): SearchConsoleService
    {
        $rows = array_map(fn ($url, $f) => [
            'keys'        => [$url],
            'impressions' => $f[0],
            'clicks'      => $f[1],
            'position'    => $f[2],
        ], array_keys($pages), $pages);

        return new SearchConsoleService(fn (string $site, array $request) => $rows);
    }

    private function range(): array
    {
        return [Carbon::parse('2026-08-01'), Carbon::parse('2026-08-28')];
    }

    public function test_click_through_rate_is_computed_from_the_folded_totals(): void
    {
        [$from, $to] = $this->range();

        $result = $this->service([
            'https://test.com/products/linen-shirt'           => [8000, 160, 6.0],
            'https://test.com/products/linen-shirt?variant=2' => [2000, 40,  6.0],
        ])->performanceByHandle('sc-domain:test.com', $from, $to);

        $this->assertSame(10000, $result['linen-shirt']['impressions']);
        $this->assertSame(200, $result['linen-shirt']['clicks']);
        // Not the mean of the two rows' rates — the rate of the totals.
        $this->assertSame(2.0, $result['linen-shirt']['ctr']);
    }

    public function test_a_product_inside_a_collection_url_belongs_to_the_product(): void
    {
        [$from, $to] = $this->range();

        $result = $this->service([
            'https://test.com/collections/shirts/products/linen-shirt' => [100, 5, 4.0],
            'https://test.com/collections/shirts'                      => [900, 50, 3.0],
        ])->performanceByHandle('sc-domain:test.com', $from, $to);

        // "/collections/shirts/products/linen-shirt" is the shirt's page, not
        // the collection's, even though both prefixes appear in it.
        $this->assertSame(100, $result['linen-shirt']['impressions']);
        $this->assertSame(900, $result['shirts']['impressions']);
    }

    public function test_pages_that_are_neither_products_nor_collections_are_ignored(): void
    {
        [$from, $to] = $this->range();

        $result = $this->service([
            'https://test.com/'                    => [5000, 400, 1.0],
            'https://test.com/pages/about-us'      => [300,  20,  9.0],
            'https://test.com/products/linen-shirt'=> [100,  5,   4.0],
        ])->performanceByHandle('sc-domain:test.com', $from, $to);

        $this->assertSame(['linen-shirt'], array_keys($result));
    }

    public function test_average_position_is_weighted_by_impressions(): void
    {
        [$from, $to] = $this->range();

        $result = $this->service([
            'https://test.com/products/linen-shirt'          => [9900, 100, 5.0],
            'https://test.com/products/linen-shirt?page=2'   => [100,  0,   95.0],
        ])->performanceByHandle('sc-domain:test.com', $from, $to);

        // A straight mean would say 50th place for a page that essentially
        // always sits 5th.
        $this->assertSame(5.9, $result['linen-shirt']['position']);
    }

    public function test_a_page_with_no_impressions_does_not_divide_by_zero(): void
    {
        [$from, $to] = $this->range();

        $result = $this->service([
            'https://test.com/products/linen-shirt' => [0, 0, 0.0],
        ])->performanceByHandle('sc-domain:test.com', $from, $to);

        $this->assertSame(0.0, $result['linen-shirt']['ctr']);
        $this->assertSame(0.0, $result['linen-shirt']['position']);
    }

    public function test_top_queries_come_back_best_first_with_their_figures(): void
    {
        [$from, $to] = $this->range();

        $service = new SearchConsoleService(fn (string $site, array $request) => [
            ['keys' => ['cabin suitcase'],   'impressions' => 900, 'clicks' => 40, 'position' => 4.2],
            ['keys' => ['carry on luggage'], 'impressions' => 400, 'clicks' => 12, 'position' => 7.8],
        ]);

        $queries = $service->topQueriesForPage('sc-domain:test.com', '/products/linen-shirt', $from, $to);

        $this->assertSame('cabin suitcase', $queries[0]['query']);
        $this->assertSame(40, $queries[0]['clicks']);
        $this->assertSame(4.2, $queries[0]['position']);
    }

    public function test_the_latest_answerable_day_stops_short_of_todays_missing_data(): void
    {
        // Search Console returns a partial window rather than an error for its
        // last few days, which reads as a collapse in traffic.
        $this->assertTrue(
            SearchConsoleService::latestCompleteDay()->lessThan(now()->subDays(SearchConsoleService::LAG_DAYS - 1)),
        );
    }
}
