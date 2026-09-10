<?php

namespace App\Jobs;

use App\Services\ProductRequestSheetSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The scheduled half of "Sync from Sheet". The button on the dashboard stays —
 * somebody who has just filed a row wants their request now — but nobody should
 * have to press it for the sync to happen at all, so this runs every two hours
 * on its own. See routes/console.php.
 *
 * It is the same service the button calls, so nothing here decides differently
 * from a hand-pressed sync: rows already synced are recognised by the ledger and
 * left alone, and unmatched rows are reported rather than dropped.
 */
class SyncProductRequestsFromSheetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Reading every category tab takes minutes on a slow connection. */
    public int $timeout = 1800;

    /**
     * One attempt. A failed run is not worth retrying inside the same slot —
     * the next scheduled run is along shortly and will see the same rows.
     */
    public int $tries = 1;

    public function handle(ProductRequestSheetSyncService $service): void
    {
        // The same lock the dashboard button takes, so a scheduled run and a
        // person's run can never read the workbook side by side.
        $lock = Cache::lock('product-request-sheet-sync', 1800);

        if (!$lock->get()) {
            Log::info('SyncProductRequestsFromSheetJob: a sync is already running, skipping this slot.');
            return;
        }

        try {
            $result = $service->run(commit: true);
        } catch (\Throwable $e) {
            Log::error('SyncProductRequestsFromSheetJob failed: ' . $e->getMessage());
            throw $e;
        } finally {
            $lock->release();
        }

        $flagged = $result['unmatched_department'] + $result['unmatched_store']
            + $result['unmatched_skus'] + $result['errors'];

        Log::info('SyncProductRequestsFromSheetJob: sheet sync finished', [
            'created'    => $result['created'],
            'backfilled' => $result['backfilled'],
            'skus_added' => $result['skus_added'],
            'flagged'    => $flagged,
        ]);
    }
}
