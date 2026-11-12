<?php

namespace Tests\Feature;

use App\Models\SkuCheckSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

/**
 * A check's results live only in its CSV, and the daily export sweep deletes
 * that file after RESULT_RETENTION_DAYS while the session row stays. The page
 * used to keep offering downloads that answered with a bare 404 Not Found.
 */
class SkuCheckExpiredResultsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Checker', 'email' => 'expired@example.test',
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

    private function completedSession(): SkuCheckSession
    {
        return SkuCheckSession::create([
            'user_id' => $this->user->id, 'store_id' => null,
            'status'  => 'completed', 'total_skus' => 1,
            'scanned_skus' => 1, 'available_count' => 0, 'not_available_count' => 1,
        ]);
    }

    private function writeResult(SkuCheckSession $session): void
    {
        $dir = storage_path('app/sku-checks');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents("{$dir}/{$session->id}.csv", "SKU,Status\nAAA,Not Available\n");
    }

    /** Run the scheduled sweep itself, so one that was never registered fails here too. */
    private function sweep(): void
    {
        $event = collect(Schedule::events())
            ->first(fn ($e) => str_contains((string) $e->description, 'prune-old-csv-exports'));

        $this->assertNotNull($event, 'the export sweep is not on the schedule');

        $callback = (new \ReflectionProperty($event, 'callback'))->getValue($event);

        $this->app->call($callback);
    }

    private function fileAged(string $name, int $days): string
    {
        $dir = storage_path('app/sku-checks');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $path = "{$dir}/{$name}";
        file_put_contents($path, "SKU,Status\n");
        touch($path, now()->subDays($days)->timestamp);

        return $path;
    }

    public function test_results_are_kept_for_the_retention_period_and_uploads_for_30_days(): void
    {
        $keep = SkuCheckSession::RESULT_RETENTION_DAYS;

        $result       = $this->fileAged('500.csv', $keep - 1);
        $variants     = $this->fileAged('500-variants.csv', $keep - 1);
        $oldResult    = $this->fileAged('501.csv', $keep + 1);
        $oldVariants  = $this->fileAged('501-variants.csv', $keep + 1);
        // A failed comparison's upload can be 100 MB; it must not inherit the longer period.
        $upload       = $this->fileAged('shopify_502.csv', 31);
        $freshUpload  = $this->fileAged('shopify_503.csv', 29);

        $this->sweep();

        $this->assertFileExists($result);
        $this->assertFileExists($variants);
        $this->assertFileDoesNotExist($oldResult);
        $this->assertFileDoesNotExist($oldVariants);
        $this->assertFileDoesNotExist($upload);
        $this->assertFileExists($freshUpload);
    }

    public function test_a_download_with_no_file_goes_back_to_the_page_and_says_why(): void
    {
        $session = $this->completedSession();

        foreach (['all', 'available', 'not_available'] as $filter) {
            $this->actingAs($this->user)
                ->get(route('sku-checker.download', $session) . "?filter={$filter}")
                ->assertRedirect(route('sku-checker.show', $session))
                ->assertSessionHas('warning');
        }

        $this->actingAs($this->user)
            ->get(route('sku-checker.variant-export.download', $session))
            ->assertRedirect(route('sku-checker.show', $session))
            ->assertSessionHas('warning');
    }

    public function test_the_page_says_the_results_are_gone_instead_of_offering_downloads(): void
    {
        $session = $this->completedSession();

        $this->actingAs($this->user)
            ->get(route('sku-checker.show', $session))
            ->assertOk()
            ->assertSee('Results no longer available')
            ->assertDontSee('Download All');
    }

    public function test_a_check_with_its_file_still_downloads(): void
    {
        $session = $this->completedSession();
        $this->writeResult($session);

        $this->actingAs($this->user)
            ->get(route('sku-checker.show', $session))
            ->assertOk()
            ->assertSee('Download All')
            ->assertDontSee('Results no longer available');

        $this->actingAs($this->user)
            ->get(route('sku-checker.download', $session))
            ->assertOk()
            ->assertDownload("sku-check-{$session->id}-all.csv");
    }
}
