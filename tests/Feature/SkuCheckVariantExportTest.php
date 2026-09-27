<?php

namespace Tests\Feature;

use App\Jobs\BuildVariantBreakdownCsvJob;
use App\Models\SkuCheckSession;
use App\Models\User;
use App\Services\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * The colour/size breakdown, asked for as a file instead of a row at a time.
 *
 * One row per variant, so a buyer can sort thousands of SKUs by "which colours
 * have no picture". It costs one Shopify lookup per mapped SKU where the check
 * itself costs one per fifty, so it is opt-in and runs in the background rather
 * than being folded into every check.
 */
class SkuCheckVariantExportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Checker', 'email' => 'export@example.test',
            'password' => 'password', 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/sku-checks/*.csv')) as $file) {
            unlink($file);
        }

        parent::tearDown();
    }

    private function checkSession(array $rows, string $status = 'completed'): SkuCheckSession
    {
        $session = SkuCheckSession::create([
            'user_id' => $this->user->id, 'store_id' => null,
            'status'  => $status, 'total_skus' => count($rows),
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

    private function exportRows(int $sessionId): array
    {
        return array_map('str_getcsv', array_filter(explode("\n", trim(
            file_get_contents(storage_path("app/sku-checks/{$sessionId}-variants.csv"))
        ))));
    }

    /** @param  array<string, array|RuntimeException|null>  $answers */
    private function fake(array $answers): void
    {
        $this->app->bind(ShopifyService::class, fn () => new FakeExportLookup($answers));
    }

    private function breakdown(string $title, array $colours): array
    {
        return [
            'product_id'    => '77',
            'product_title' => $title,
            'published'     => true,
            'gallery_count' => 9,
            'colours'       => $colours,
        ];
    }

    private function colour(string $name, array $sizes): array
    {
        return [
            'colour'           => $name,
            'variant_count'    => count($sizes),
            'with_image_count' => count(array_filter($sizes, fn ($s) => $s['has_image'])),
            'sizes'            => $sizes,
        ];
    }

    private function variantSize(string $size, bool $hasImage, int $count = 0): array
    {
        return [
            'size' => $size, 'sku' => "SKU-{$size}", 'variant_id' => '5' . strlen($size),
            'has_image' => $hasImage, 'image_count' => $count, 'preview' => null,
            'is_match' => false,
        ];
    }

    public function test_the_file_holds_one_row_per_variant(): void
    {
        $session = $this->checkSession([['AAA', 'Available', '77', 'A Dress', 'TRUE']]);

        $this->fake(['AAA' => $this->breakdown('A Dress', [
            $this->colour('Red',  [$this->variantSize('M', true, 2), $this->variantSize('L', false)]),
            $this->colour('Blue', [$this->variantSize('M', false)]),
        ])]);

        (new BuildVariantBreakdownCsvJob($session->id))->handle();

        $rows = $this->exportRows($session->id);

        $this->assertSame([
            'SKU Checked', 'Status', 'Product ID', 'Product Name', 'Published',
            'Colour', 'Size', 'Variant SKU', 'Variant ID',
            'Has Image', 'Image Count', 'Sizes In Colour', 'Sizes With Image', 'Gallery Images',
        ], $rows[0]);

        $this->assertCount(4, $rows); // header + three variants

        $this->assertSame(
            ['AAA', 'Available', '77', 'A Dress', 'TRUE', 'Red', 'M', 'SKU-M', '51', 'YES', '2', '2', '1', '9'],
            $rows[1]
        );
        $this->assertSame('NO', $rows[2][9]);

        // The colour totals travel on every row of that colour, so a pivot on
        // Colour answers "which colours have nothing" without a second pass.
        $this->assertSame(['1', '0'], [$rows[3][11], $rows[3][12]]);

        $session->refresh();
        $this->assertSame('completed', $session->variant_export_status);
        $this->assertSame(1, $session->variant_export_scanned);
        $this->assertSame(0, $session->variant_export_failed);
    }

    public function test_two_skus_of_one_product_are_written_once_and_both_named(): void
    {
        // Both are sizes of AMERICAN TOURISTER TRAILON, so the product's six
        // variants must not be written twice under one SKU each.
        $session = $this->checkSession([
            ['GAT207LUG00325', 'Available', '10622287642934', 'AMERICAN TOURISTER TRAILON LUGGAGE', 'TRUE'],
            ['GAT207LUG00323', 'Available', '10622287642934', 'AMERICAN TOURISTER TRAILON LUGGAGE', 'TRUE'],
        ]);

        $answers = ['GAT207LUG00325' => $this->breakdown('AMERICAN TOURISTER TRAILON LUGGAGE', [
            $this->colour('DARK FOREST', [
                $this->variantSize('Cabin', true, 1), $this->variantSize('Medium', true, 1), $this->variantSize('Large', true, 1),
            ]),
            $this->colour('BLACK', [
                $this->variantSize('Cabin', true, 1), $this->variantSize('Medium', true, 1), $this->variantSize('Large', true, 1),
            ]),
        ])];

        $fake = new FakeExportLookup($answers);
        $this->app->bind(ShopifyService::class, fn () => $fake);

        (new BuildVariantBreakdownCsvJob($session->id))->handle();

        $rows = $this->exportRows($session->id);

        // Six variants, six rows — not twelve.
        $this->assertCount(7, $rows);

        // And the same product is asked about once, not once per SKU.
        $this->assertSame(['GAT207LUG00325'], $fake->asked);

        // Either pasted SKU finds the rows.
        foreach (array_slice($rows, 1) as $row) {
            $this->assertSame('GAT207LUG00325, GAT207LUG00323', $row[0]);
        }

        $session->refresh();
        $this->assertSame(1, $session->variant_export_total);
        $this->assertSame(1, $session->variant_export_scanned);
    }

    public function test_skus_with_no_product_id_are_not_merged_into_one_block(): void
    {
        // An empty product id is not a product they share — grouping on it
        // would collapse unrelated SKUs into a single block.
        $session = $this->checkSession([
            ['AAA', 'Available', '', 'A Dress', 'TRUE'],
            ['BBB', 'Available', '', 'A Shirt', 'TRUE'],
        ]);

        $this->fake([
            'AAA' => $this->breakdown('A Dress', [$this->colour('Red',   [$this->variantSize('M', true, 1)])]),
            'BBB' => $this->breakdown('A Shirt', [$this->colour('Green', [$this->variantSize('S', true, 1)])]),
        ]);

        (new BuildVariantBreakdownCsvJob($session->id))->handle();

        $rows = $this->exportRows($session->id);

        $this->assertSame('AAA', $rows[1][0]);
        $this->assertSame('BBB', $rows[2][0]);
    }

    public function test_a_not_mapped_sku_stays_in_the_file(): void
    {
        $session = $this->checkSession([
            ['AAA', 'Available',     '77', 'A Dress', 'TRUE'],
            ['ZZZ', 'Not Available', '',   '',        ''],
        ]);

        $this->fake(['AAA' => $this->breakdown('A Dress', [$this->colour('Red', [$this->variantSize('M', true, 1)])])]);

        (new BuildVariantBreakdownCsvJob($session->id))->handle();

        $rows = $this->exportRows($session->id);

        // Dropping it would read as "this SKU has no colours" rather than
        // "this SKU is not in Shopify" — a different and wrong answer.
        $this->assertSame(['ZZZ', 'Not Available'], array_slice($rows[2], 0, 2));
        $this->assertSame(2, $session->refresh()->variant_export_scanned);
    }

    public function test_one_failed_lookup_is_named_and_does_not_lose_the_rest(): void
    {
        $session = $this->checkSession([
            ['AAA', 'Available', '77', 'A Dress', 'TRUE'],
            ['BBB', 'Available', '78', 'A Shirt', 'TRUE'],
        ]);

        $this->fake([
            'AAA' => new RuntimeException('Shopify variant breakdown failed for AAA: Throttled'),
            'BBB' => $this->breakdown('A Shirt', [$this->colour('Green', [$this->variantSize('S', true, 1)])]),
        ]);

        (new BuildVariantBreakdownCsvJob($session->id))->handle();

        $rows = $this->exportRows($session->id);

        $this->assertSame(['AAA', 'Lookup Failed'], array_slice($rows[1], 0, 2));
        $this->assertSame(['BBB', 'Available'],     array_slice($rows[2], 0, 2));

        $session->refresh();
        $this->assertSame('completed', $session->variant_export_status);
        $this->assertSame(1, $session->variant_export_failed);

        // Why it failed, not just that it did — one refused query fails every
        // row the same way, and that fact belongs on the page.
        $this->assertStringContainsString('Throttled', $session->variant_export_error);
    }

    public function test_a_sku_shopify_no_longer_carries_is_marked_rather_than_left_blank(): void
    {
        $session = $this->checkSession([['GONE', 'Available', '77', 'A Dress', 'TRUE']]);

        $this->fake(['GONE' => null]);

        (new BuildVariantBreakdownCsvJob($session->id))->handle();

        $this->assertSame(['GONE', 'No Variants Found'], array_slice($this->exportRows($session->id)[1], 0, 2));
    }

    public function test_the_export_is_opt_in_and_not_started_twice(): void
    {
        Queue::fake();

        $session = $this->checkSession([['AAA', 'Available', '77', 'A Dress', 'TRUE']]);

        $this->actingAs($this->user)
            ->postJson(route('sku-checker.variant-export', $session))
            ->assertOk();

        Queue::assertPushed(BuildVariantBreakdownCsvJob::class, 1);

        $session->update(['variant_export_status' => 'running']);

        $this->actingAs($this->user)
            ->postJson(route('sku-checker.variant-export', $session))
            ->assertStatus(409);

        Queue::assertPushed(BuildVariantBreakdownCsvJob::class, 1);
    }

    public function test_an_unfinished_check_has_nothing_to_export(): void
    {
        Queue::fake();

        $session = $this->checkSession([['AAA', 'Available', '77', 'A Dress', 'TRUE']], 'running');

        $this->actingAs($this->user)
            ->postJson(route('sku-checker.variant-export', $session))
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_deleting_a_session_takes_the_export_file_with_it(): void
    {
        $session = $this->checkSession([['AAA', 'Available', '77', 'A Dress', 'TRUE']]);

        $this->fake(['AAA' => $this->breakdown('A Dress', [$this->colour('Red', [$this->variantSize('M', true, 1)])])]);
        (new BuildVariantBreakdownCsvJob($session->id))->handle();

        $export = storage_path("app/sku-checks/{$session->id}-variants.csv");
        $this->assertFileExists($export);

        $this->actingAs($this->user)->delete(route('sku-checker.destroy', $session));

        // The directory has filled a server's disk before; an export that
        // outlives its session is exactly how that happens again.
        $this->assertFileDoesNotExist($export);
        $this->assertFileDoesNotExist(storage_path("app/sku-checks/{$session->id}.csv"));
    }

    public function test_progress_is_readable_while_the_export_runs(): void
    {
        $session = $this->checkSession(array_map(
            fn ($n) => ["SKU{$n}", 'Available', "77{$n}", "Dress {$n}", 'TRUE'],
            range(1, 25)
        ));

        $answers = [];
        foreach (range(1, 25) as $n) {
            $answers["SKU{$n}"] = $this->breakdown("Dress {$n}", [$this->colour('Red', [$this->variantSize('M', true, 1)])]);
        }
        $this->fake($answers);

        (new BuildVariantBreakdownCsvJob($session->id))->handle();

        $session->refresh();
        $this->assertSame(25, $session->variant_export_total);
        $this->assertSame(25, $session->variant_export_scanned);
        $this->assertSame(100, $session->variantExportProgressPercent());
    }
}

class FakeExportLookup extends ShopifyService
{
    /** @var list<string> the SKUs Shopify was actually asked about */
    public array $asked = [];

    /** @param  array<string, array|RuntimeException|null>  $answers */
    public function __construct(private array $answers) {}

    public function getSkuVariantBreakdown(string $sku, bool $throwOnFailure = false): ?array
    {
        $this->asked[] = $sku;

        $answer = $this->answers[$sku] ?? null;

        if ($answer instanceof RuntimeException) {
            throw $answer;
        }

        return $answer;
    }
}
