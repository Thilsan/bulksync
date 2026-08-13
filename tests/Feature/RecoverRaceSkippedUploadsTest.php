<?php

namespace Tests\Feature;

use App\Jobs\ProcessUploadItemJob;
use App\Models\UploadItem;
use App\Models\UploadSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RecoverRaceSkippedUploadsTest extends TestCase
{
    use RefreshDatabase;

    private UploadSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->session = UploadSession::create([
            'onedrive_link' => 'https://example.test/folder',
            'matching_mode' => 'sku_barcode',
        ]);
    }

    public function test_it_requeues_a_skip_that_a_sibling_upload_proves_was_wrong(): void
    {
        // Variant 42 landed a file, so it cannot have had a photo of its own
        // when the batch began — the third file's skip was the race.
        $this->item('a.jpg', 'SKU-A', '42', 'uploaded');
        $this->item('b.jpg', 'SKU-A', '42', 'uploaded');
        $dropped = $this->item('c.jpg', 'SKU-A', '42', 'exists');

        $this->artisan('bulksync:recover-race-skips', ['session' => $this->session->id])
            ->assertSuccessful();

        $this->assertSame('pending', $dropped->fresh()->status);
        $this->assertNull($dropped->fresh()->error_message);

        Queue::assertPushed(
            ProcessUploadItemJob::class,
            fn (ProcessUploadItemJob $job) => $job->itemId === $dropped->id,
        );

        // Seeded so the re-run does not skip on the photo its own siblings added,
        // and does not steal the main-image slot a sibling already holds.
        $this->assertDatabaseHas('upload_sku_baselines', [
            'upload_session_id'     => $this->session->id,
            'scope'                 => 'variant',
            'scope_id'              => '42',
            'has_existing_image'    => false,
            'variant_image_claimed' => true,
        ]);
    }

    public function test_it_leaves_a_genuine_skip_alone(): void
    {
        // Nothing uploaded for variant 99, so "Already Has Image" stands.
        $genuine = $this->item('d.jpg', 'SKU-B', '99', 'exists');

        $this->artisan('bulksync:recover-race-skips', ['session' => $this->session->id])
            ->assertSuccessful();

        $this->assertSame('exists', $genuine->fresh()->status);

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('upload_sku_baselines', 0);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->item('a.jpg', 'SKU-A', '42', 'uploaded');
        $dropped = $this->item('c.jpg', 'SKU-A', '42', 'exists');

        $this->artisan('bulksync:recover-race-skips', [
            'session'   => $this->session->id,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame('exists', $dropped->fresh()->status);
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('upload_sku_baselines', 0);
    }

    public function test_it_fails_on_an_unknown_session(): void
    {
        $this->artisan('bulksync:recover-race-skips', ['session' => 999999])
            ->assertFailed();
    }

    private function item(string $filename, string $sku, string $variantId, string $status): UploadItem
    {
        return UploadItem::create([
            'upload_session_id' => $this->session->id,
            'filename'          => $filename,
            'sku_detected'      => $sku,
            'product_id'        => '1000',
            'variant_id'        => $variantId,
            'status'            => $status,
            'error_message'     => $status === 'exists' ? 'Already has image on Shopify — upload skipped' : null,
        ]);
    }
}
