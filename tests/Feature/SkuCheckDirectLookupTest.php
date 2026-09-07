<?php

namespace Tests\Feature;

use App\Jobs\RunSkuCheckJob;
use App\Models\SkuCheckSession;
use App\Models\User;
use App\Services\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * A SKU check reads the SKUs it was given, straight from Shopify.
 *
 * It used to warm the entire catalogue for any list over 500 and then read the
 * answers back out of a cache — 400k+ variants and twenty minutes on the largest
 * store, to answer a few hundred questions. Worse, an evicted cache entry is
 * indistinguishable from "no such SKU", so a product that exists was reported
 * Not Available. The fakes here fail loudly if either path comes back.
 */
class SkuCheckDirectLookupTest extends TestCase
{
    use RefreshDatabase;

    private function newSession(string $rawSkus): SkuCheckSession
    {
        return SkuCheckSession::create([
            'user_id'  => User::factory()->create()->id,
            'store_id' => null,
            'status'   => 'pending',
            'raw_skus' => $rawSkus,
        ]);
    }

    private function fake(callable $answer): FakeShopifyLookup
    {
        $fake = new FakeShopifyLookup($answer);

        $this->app->bind(ShopifyService::class, fn () => $fake);

        return $fake;
    }

    private function csv(int $sessionId): array
    {
        return array_map(
            'str_getcsv',
            array_filter(explode("\n", trim(file_get_contents(storage_path("app/sku-checks/{$sessionId}.csv")))))
        );
    }

    public function test_only_the_given_skus_are_asked_about(): void
    {
        $session = $this->newSession("AAA\nBBB\nCCC");

        $fake = $this->fake(fn (array $skus) => ['AAA' => [[
            'product_id' => '11', 'product_title' => 'A Thing', 'published' => true,
        ]]]);

        (new RunSkuCheckJob($session->id))->handle();

        $this->assertSame([['AAA', 'BBB', 'CCC']], $fake->asked);

        $session->refresh();
        $this->assertSame('completed', $session->status);
        $this->assertSame(1, $session->available_count);
        $this->assertSame(2, $session->not_available_count);
        $this->assertSame(3, $session->scanned_skus);
    }

    public function test_the_csv_reports_availability_and_publish_state(): void
    {
        $session = $this->newSession("LIVE\nDRAFT\nGONE");

        $this->fake(fn (array $skus) => [
            'LIVE'  => [['product_id' => '1', 'product_title' => 'Live One',  'published' => true]],
            'DRAFT' => [['product_id' => '2', 'product_title' => 'Draft One', 'published' => false]],
        ]);

        (new RunSkuCheckJob($session->id))->handle();

        $rows = $this->csv($session->id);

        $this->assertSame(['SKU', 'Status', 'Product ID', 'Product Name', 'Published'], $rows[0]);
        $this->assertSame(['LIVE',  'Available',     '1', 'Live One',  'TRUE'],  $rows[1]);
        $this->assertSame(['DRAFT', 'Available',     '2', 'Draft One', 'FALSE'], $rows[2]);
        $this->assertSame(['GONE',  'Not Available', '',  '',          ''],      $rows[3]);
    }

    public function test_a_list_over_the_old_warm_threshold_still_only_asks_for_its_own_skus(): void
    {
        // 600 SKUs used to trip the "warm the whole catalogue first" branch.
        $skus    = array_map(fn ($n) => 'SKU-' . $n, range(1, 600));
        $session = $this->newSession(implode("\n", $skus));

        $fake = $this->fake(fn (array $batch) => []);

        (new RunSkuCheckJob($session->id))->handle();

        $session->refresh();
        $this->assertSame('completed', $session->status);
        $this->assertSame(600, $session->not_available_count);

        // Asked in progress-sized chunks, and never for anything outside the list.
        $this->assertSame($skus, array_merge(...$fake->asked));
    }

    public function test_a_failed_lookup_fails_the_session_rather_than_reporting_not_available(): void
    {
        $session = $this->newSession("AAA\nBBB");

        $this->fake(function (array $skus) {
            throw new RuntimeException('Shopify SKU lookup failed: Throttled');
        });

        (new RunSkuCheckJob($session->id))->handle();

        $session->refresh();

        // The failure mode that matters: a real SKU must never be reported as
        // Not Available because the lookup broke.
        $this->assertSame('failed', $session->status);
        $this->assertStringContainsString('Throttled', $session->error_message);
        $this->assertSame(0, $session->not_available_count);
    }

    public function test_progress_moves_while_a_long_list_runs(): void
    {
        $session = $this->newSession(implode("\n", array_map(fn ($n) => 'S' . $n, range(1, 450))));

        $seen = [];

        $this->fake(function (array $batch) use ($session, &$seen) {
            $seen[] = SkuCheckSession::find($session->id)->scanned_skus;

            return [];
        });

        (new RunSkuCheckJob($session->id))->handle();

        // 200 per chunk: the counter has already moved before the last batch.
        $this->assertSame([0, 200, 400], $seen);
        $this->assertSame(450, $session->refresh()->scanned_skus);
    }
}

/**
 * Answers batched lookups from a callable, and treats any use of the warm cache
 * as a failure — that is the behaviour being removed, not an implementation
 * detail.
 */
class FakeShopifyLookup extends ShopifyService
{
    /** @var list<list<string>> */
    public array $asked = [];

    public function __construct(private $answer) {}

    public function findVariantsBySkus(array $skus, bool $throwOnFailure = false): array
    {
        $this->asked[] = $skus;

        return ($this->answer)($skus);
    }

    public function warmSkuCache(): int
    {
        throw new \LogicException('a SKU check must not warm the whole catalogue');
    }

    public function isSkuCacheWarmed(): bool
    {
        throw new \LogicException('a SKU check must not consult the warm cache');
    }

    public function findVariantsBySkuCached(string $sku, bool $throwOnFailure = false): array
    {
        throw new \LogicException('a SKU check must not read the warm cache');
    }
}
