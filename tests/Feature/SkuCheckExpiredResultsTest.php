<?php

namespace Tests\Feature;

use App\Models\SkuCheckSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A check's results live only in its CSV, and the daily export sweep deletes
 * that file after 30 days while the session row stays. The page used to keep
 * offering downloads that answered with a bare 404 Not Found.
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
