<?php

namespace App\Console\Commands;

use App\Models\ProductRequest;
use App\Models\ProductRequestSheetSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Clears everything the tracking sheet imported, so the next sync starts clean.
 *
 * For changing which document the sheet sync reads: the ledger is keyed on the
 * old file's Request No values, and those numbers mean whatever the new file says
 * they mean. Rather than trying to reconcile two numbering schemes, this drops the
 * imported requests and the ledger together and lets the sync rebuild from the
 * new source.
 *
 * Requests raised by hand are never touched — only ones with a ledger row, plus
 * ones carrying a sheet Request No, which is the same set by a different route.
 *
 * This deletes real work. It reports what would go, including anything a person
 * has done to those requests, and does nothing at all without --commit.
 */
class ResetSheetSyncedRequests extends Command
{
    protected $signature = 'product-requests:reset-sheet-sync
                            {--commit : Actually delete, instead of only reporting what would go}
                            {--force : Delete even the requests people have worked on}';

    protected $description = 'Delete everything the tracking sheet imported, so the next sync re-reads it from scratch';

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');
        $force  = (bool) $this->option('force');

        if (!$commit) {
            $this->warn('Dry run — nothing will be deleted. Pass --commit to apply.');
        }

        $linked = ProductRequestSheetSync::whereNotNull('product_request_id')->pluck('product_request_id');

        $requests = ProductRequest::query()
            ->where(fn ($q) => $q->whereIn('id', $linked)->orWhereNotNull('sheet_request_no'))
            ->with(['store', 'skus', 'activities', 'currentAssignments', 'attachments', 'draftProducts'])
            ->orderBy('id')
            ->get();

        $ledgerRows = ProductRequestSheetSync::count();

        if ($requests->isEmpty() && $ledgerRows === 0) {
            $this->info('Nothing imported from the sheet — there is nothing to reset.');
            return self::SUCCESS;
        }

        // Anything a person did is worth naming before it is thrown away, whether
        // or not the run goes ahead: "154 requests deleted" hides the one somebody
        // spent an afternoon on.
        $worked = $requests->filter(fn ($r) => $this->workOn($r) !== null);

        $this->line("{$requests->count()} imported request(s) and {$ledgerRows} ledger row(s) would go.");

        if ($worked->isNotEmpty()) {
            $this->newLine();
            $this->warn("{$worked->count()} of them have work on them:");

            foreach ($worked as $request) {
                $this->line("  {$request->reference} — {$request->brand} / {$request->category}: " . $this->workOn($request));
            }

            if (!$force) {
                $this->newLine();
                $this->warn('Those are kept. Add --force to delete them too, once you are sure that work is not needed.');
            }
        }

        $doomed = $force ? $requests : $requests->reject(fn ($r) => $this->workOn($r) !== null);

        if (!$commit) {
            $this->newLine();
            $this->info($doomed->count() . ' request(s) would be deleted. Re-run the sync afterwards to rebuild from the new sheet.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($doomed, $force) {
            // The ledger goes wholesale: its Request No values belong to the old
            // document, so keeping any of them would make the next sync skip rows
            // it has never actually seen.
            if ($force) {
                ProductRequestSheetSync::query()->delete();
            } else {
                ProductRequestSheetSync::whereIn('product_request_id', $doomed->pluck('id'))
                    ->orWhereNull('product_request_id')
                    ->delete();
            }

            foreach ($doomed as $request) {
                $request->delete();
            }
        });

        $this->newLine();
        $this->info($doomed->count() . ' request(s) deleted. Run product-requests:sync-sheet --commit to rebuild from the sheet.');

        return self::SUCCESS;
    }

    /** What a person has done to this request, in words, or null for nothing. */
    private function workOn(ProductRequest $request): ?string
    {
        $signs = [];

        if ($count = $request->attachments->count()) {
            $signs[] = "{$count} attachment(s)";
        }

        if ($count = $request->activities->where('action', 'comment')->count()) {
            $signs[] = "{$count} comment(s)";
        }

        if ($count = $request->skus->whereNotNull('mapping_set_by')->count()) {
            $signs[] = "{$count} SKU(s) mapped by hand";
        }

        if ($count = $request->draftProducts->where('push_status', 'pushed')->count()) {
            $signs[] = "{$count} product(s) already pushed to Shopify";
        }

        // Past the early stages means somebody has been moving it along.
        if (!in_array($request->status, [
            ProductRequest::SUBMITTED,
            ProductRequest::WAITING_MAPPING,
            ProductRequest::SKU_VERIFIED,
        ], true)) {
            $signs[] = 'at ' . $request->statusLabel();
        }

        return $signs ? implode(', ', $signs) : null;
    }
}
