<?php

namespace App\Console\Commands;

use App\Jobs\ProcessUploadItemJob;
use App\Models\UploadItem;
use App\Models\UploadSession;
use App\Services\UploadBaselineResolver;
use Illuminate\Console\Command;

/**
 * Re-queues files that a pre-fix run dropped as "Already Has Image" when they
 * should have uploaded.
 *
 * The old duplicate check ran per file, mid-upload. With parallel workers the
 * first file of a SKU folder assigned the variant image, and a sibling still
 * in flight read that brand-new photo as pre-existing and skipped itself. The
 * giveaway is a SKU with BOTH uploaded and 'exists' files in one session: a
 * genuinely pre-covered SKU would have skipped every one of its files, so any
 * uploaded sibling proves the SKU had no photo of its own when the batch began.
 */
class RecoverRaceSkippedUploads extends Command
{
    protected $signature = 'bulksync:recover-race-skips
                            {session : Upload session ID}
                            {--dry-run : List what would be re-queued, change nothing}';

    protected $description = 'Re-queue files wrongly skipped as Already Has Image by the pre-fix duplicate check';

    public function handle(UploadBaselineResolver $baselines): int
    {
        $session = UploadSession::find($this->argument('session'));

        if (!$session) {
            $this->error("Upload session {$this->argument('session')} not found.");
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $scope  = ($session->matching_mode ?? 'sku_barcode') === 'style_code'
            ? UploadBaselineResolver::SCOPE_PRODUCT
            : UploadBaselineResolver::SCOPE_VARIANT;

        $column = $scope === UploadBaselineResolver::SCOPE_PRODUCT ? 'product_id' : 'variant_id';

        // SKUs in this session that did land at least one file — the proof set.
        $uploadedScopeIds = UploadItem::where('upload_session_id', $session->id)
            ->where('status', 'uploaded')
            ->whereNotNull($column)
            ->distinct()
            ->pluck($column)
            ->map(fn ($id) => (string) $id)
            ->all();

        $skipped = UploadItem::where('upload_session_id', $session->id)
            ->where('status', 'exists')
            ->whereNotNull($column)
            ->get();

        $recoverable = $skipped->filter(
            fn (UploadItem $item) => in_array((string) $item->{$column}, $uploadedScopeIds, true)
        );

        $this->line("Session {$session->id}: {$skipped->count()} file(s) marked Already Has Image, "
            . "{$recoverable->count()} recoverable.");

        if ($recoverable->isEmpty()) {
            $this->info('Nothing to recover — every skipped file belongs to a SKU that uploaded nothing, '
                . 'so those skips look genuine.');
            return self::SUCCESS;
        }

        foreach ($recoverable as $item) {
            $scopeId = (string) $item->{$column};

            $this->line(($dryRun ? '[dry-run] ' : '') . "re-queue {$item->filename} ({$item->sku_detected})");

            if ($dryRun) {
                continue;
            }

            // Record the verdict we already know to be true, so the re-run does
            // not re-probe and skip on the photo its own siblings just added.
            // The main-image slot stays claimed: a sibling already holds it.
            $baselines->seed(
                $session->id,
                $scope,
                $scopeId,
                hasExistingImage: false,
                variantImageClaimed: true,
            );

            $item->update(['status' => 'pending', 'error_message' => null]);

            ProcessUploadItemJob::dispatch($item->id)->onQueue('bulkupload');
        }

        if ($dryRun) {
            $this->info('Dry run — nothing changed. Re-run without --dry-run to re-queue.');
            return self::SUCCESS;
        }

        $session->update(['status' => 'processing']);

        $this->info("Re-queued {$recoverable->count()} file(s).");

        return self::SUCCESS;
    }
}
