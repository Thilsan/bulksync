<?php

namespace Tests\Feature;

use App\Models\SkuCheckSession;
use App\Models\User;
use App\Services\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * A finished check answers "is this SKU in Shopify". The question that follows
 * is asked one row at a time: which colours the product carries, and which
 * colours and sizes already have a photo of their own.
 *
 * The results live only as a CSV — a run writes no DB rows — so the table reads
 * that file, and the breakdown is a live Shopify call made only when a row is
 * opened. Collecting it during the run would pay for a lookup per SKU to answer
 * a question asked about a handful.
 */
class SkuCheckVariantBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Checker', 'email' => 'checker@example.test',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    private function completedSession(array $rows): SkuCheckSession
    {
        $session = SkuCheckSession::create([
            'user_id'  => $this->user->id,
            'store_id' => null,
            'status'   => 'completed',
            'total_skus' => count($rows),
        ]);

        $dir = storage_path('app/sku-checks');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $handle = fopen("{$dir}/{$session->id}.csv", 'w');
        fputcsv($handle, ['SKU', 'Status', 'Product ID', 'Product Name', 'Published']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return $session;
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/sku-checks/*.csv')) as $file) {
            unlink($file);
        }

        parent::tearDown();
    }

    public function test_the_results_table_reads_the_csv_and_filters_it(): void
    {
        $session = $this->completedSession([
            ['AAA', 'Available',     '1', 'A Thing', 'TRUE'],
            ['BBB', 'Not Available', '',  '',        ''],
            ['ACB', 'Available',     '2', 'Another', 'FALSE'],
        ]);

        $all = $this->actingAs($this->user)
            ->getJson(route('sku-checker.results', $session))
            ->assertOk()
            ->json();

        $this->assertSame(3, $all['total']);
        $this->assertSame(['AAA', 'BBB', 'ACB'], array_column($all['rows'], 'sku'));
        $this->assertTrue($all['rows'][0]['published']);
        $this->assertFalse($all['rows'][2]['published']);

        $mapped = $this->actingAs($this->user)
            ->getJson(route('sku-checker.results', $session) . '?filter=not_available')
            ->json();

        $this->assertSame(['BBB'], array_column($mapped['rows'], 'sku'));

        $searched = $this->actingAs($this->user)
            ->getJson(route('sku-checker.results', $session) . '?q=ac')
            ->json();

        $this->assertSame(['ACB'], array_column($searched['rows'], 'sku'));
    }

    public function test_the_checker_opens_on_the_work_already_done(): void
    {
        $this->completedSession([
            ['AAA', 'Available',     '1', 'A Thing', 'TRUE'],
            ['BBB', 'Not Available', '',  '',        ''],
        ])->update([
            'total_skus'          => 2,
            'available_count'     => 1,
            'not_available_count' => 1,
        ]);

        $page = $this->actingAs($this->user)->get(route('sku-checker.index'))->assertOk();

        // The totals are one aggregate query, which is the kind of thing that
        // works until a driver disagrees about COALESCE or aliases.
        $page->assertSee('Checks run')
             ->assertSee('Recent checks')
             ->assertSee('SKUs checked');
    }

    public function test_the_history_page_renders(): void
    {
        $this->completedSession([['AAA', 'Available', '1', 'A Thing', 'TRUE']])
            ->update(['total_skus' => 1, 'available_count' => 1]);

        $this->actingAs($this->user)
            ->get(route('sku-checker.history'))
            ->assertOk()
            ->assertSee('Coverage');
    }

    public function test_the_results_page_renders(): void
    {
        $session = $this->completedSession([['AAA', 'Available', '1', 'A Thing', 'TRUE']]);

        $page = $this->actingAs($this->user)
            ->get(route('sku-checker.show', $session))
            ->assertOk();

        // The figures count up rather than snapping, so the tiles read from a
        // display mirror — a typo in it would leave four blank cards.
        $page->assertSee('shown.total.toLocaleString()', false)
             ->assertSee('skuCheckPage(', false);
    }

    public function test_another_users_results_are_not_readable(): void
    {
        $session = $this->completedSession([['AAA', 'Available', '1', 'A Thing', 'TRUE']]);

        $stranger = User::create([
            'name' => 'Stranger', 'email' => 'stranger@example.test',
            'password' => 'password', 'is_active' => true,
        ]);

        $this->actingAs($stranger)->getJson(route('sku-checker.results', $session))->assertForbidden();
        $this->actingAs($stranger)
            ->getJson(route('sku-checker.variants', $session) . '?sku=AAA')
            ->assertForbidden();
    }

    public function test_a_row_opens_into_colours_and_the_sizes_that_have_a_photo(): void
    {
        $session = $this->completedSession([['RED-M', 'Available', '1', 'A Dress', 'TRUE']]);

        $this->app->bind(ShopifyService::class, fn () => new FakeBreakdownLookup([
            'sku'              => 'RED-M',
            'product_title'    => 'A Dress',
            'variant_count'    => 3,
            'with_image_count' => 2,
            'colours'          => [
                ['colour' => 'Red', 'variant_count' => 2, 'with_image_count' => 2, 'sizes' => [
                    ['size' => 'M', 'has_image' => true], ['size' => 'L', 'has_image' => true],
                ]],
                ['colour' => 'Blue', 'variant_count' => 1, 'with_image_count' => 0, 'sizes' => [
                    ['size' => 'M', 'has_image' => false],
                ]],
            ],
        ]));

        $body = $this->actingAs($this->user)
            ->getJson(route('sku-checker.variants', $session) . '?sku=RED-M')
            ->assertOk()
            ->json();

        $this->assertSame(['Red', 'Blue'], array_column($body['colours'], 'colour'));
        $this->assertSame(0, $body['colours'][1]['with_image_count']);
        $this->assertFalse($body['colours'][1]['sizes'][0]['has_image']);
    }

    public function test_a_sku_with_no_variant_says_so_rather_than_showing_an_empty_panel(): void
    {
        $session = $this->completedSession([['GONE', 'Available', '1', 'A Dress', 'TRUE']]);

        $this->app->bind(ShopifyService::class, fn () => new FakeBreakdownLookup(null));

        $this->actingAs($this->user)
            ->getJson(route('sku-checker.variants', $session) . '?sku=GONE')
            ->assertNotFound()
            ->assertJsonFragment(['error' => 'No variant in Shopify carries the SKU GONE.']);
    }

    public function test_a_broken_lookup_reports_the_failure_instead_of_an_empty_breakdown(): void
    {
        $session = $this->completedSession([['AAA', 'Available', '1', 'A Dress', 'TRUE']]);

        $this->app->bind(ShopifyService::class, fn () => new FakeBreakdownLookup(
            new RuntimeException('Shopify variant breakdown failed for AAA: Throttled')
        ));

        // An empty panel would read as "this product has no colours", which is a
        // different and wrong answer.
        $this->actingAs($this->user)
            ->getJson(route('sku-checker.variants', $session) . '?sku=AAA')
            ->assertStatus(502)
            ->assertJsonFragment(['error' => 'Shopify variant breakdown failed for AAA: Throttled']);
    }
}

class FakeBreakdownLookup extends ShopifyService
{
    public function __construct(private array|RuntimeException|null $answer) {}

    public function getSkuVariantBreakdown(string $sku, bool $throwOnFailure = false): ?array
    {
        if ($this->answer instanceof RuntimeException) {
            throw $this->answer;
        }

        return $this->answer;
    }
}
