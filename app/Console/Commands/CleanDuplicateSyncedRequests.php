<?php

namespace App\Console\Commands;

use App\Models\ProductRequest;
use App\Models\ProductRequestSheetSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes the requests left behind when two sync runs raced each other.
 *
 * Both runs saw no ledger entry for the same sheet row, both created a request,
 * and the ledger — one row per (Request No, website token) — ended up pointing at
 * whichever finished last. The other is an orphan: identical to its twin, but
 * with nothing tying it back to the sheet, so no future sync will ever touch it.
 *
 * A request is only removed when all of this holds:
 *   - it has no sheet ledger row of its own
 *   - a twin exists with the same website, brand, category and SKU count, and
 *     that twin does have a ledger row
 *   - both were created within a few minutes of each other
 *   - nobody has worked on it: no attachments, no comments, no hand-made moves
 *
 * Anything failing one of those is left alone and reported, because a request
 * someone has touched is not a stray copy.
 */
class CleanDuplicateSyncedRequests extends Command
{
    protected $signature = 'product-requests:clean-duplicate-syncs
                            {--commit : Actually delete, instead of only reporting what would go}';

    protected $description = 'Delete request copies left behind by two sync runs racing on the same sheet row';

    /** How far apart two copies of one sheet row can be and still be the same accident. */
    private const WINDOW_MINUTES = 10;

    /**
     * How long the importer takes to finish writing its own history for a
     * request — the creation entry, the SKU check's status move, the category
     * staffing. Anything logged after this was a person, not the import.
     */
    private const SETTLE_MINUTES = 5;

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');

        if (!$commit) {
            $this->warn('Dry run — nothing will be deleted. Pass --commit to apply.');
        }

        $linked = ProductRequestSheetSync::whereNotNull('product_request_id')->pluck('product_request_id')->all();

        // Candidates: requests with no ledger row of their own, still early enough
        // in the pipeline that nothing downstream depends on them.
        $orphans = ProductRequest::query()
            ->whereNotIn('id', $linked ?: [0])
            ->whereIn('status', [ProductRequest::SUBMITTED, ProductRequest::WAITING_MAPPING, ProductRequest::SKU_VERIFIED])
            ->with('store')
            ->orderBy('id')
            ->get();

        $removed = 0;
        $kept    = [];

        foreach ($orphans as $orphan) {
            $twin = ProductRequest::query()
                ->whereKeyNot($orphan->id)
                ->whereIn('id', $linked ?: [0])
                ->where('store_id', $orphan->store_id)
                ->where('brand', $orphan->brand)
                ->where('category', $orphan->category)
                ->where('total_skus', $orphan->total_skus)
                ->whereBetween('created_at', [
                    $orphan->created_at->copy()->subMinutes(self::WINDOW_MINUTES),
                    $orphan->created_at->copy()->addMinutes(self::WINDOW_MINUTES),
                ])
                ->first();

            if (!$twin) {
                continue;   // not a race leftover — could be a request raised by hand
            }

            if ($worked = $this->workDoneOn($orphan)) {
                $kept[] = "{$orphan->reference} ({$orphan->brand}) — kept, someone has worked on it: {$worked}";
                continue;
            }

            $this->line(($commit ? 'Deleted' : 'Would delete')
                . " {$orphan->reference} — {$orphan->brand} / {$orphan->category} on "
                . ($orphan->store?->name ?? 'no website')
                . ", {$orphan->total_skus} SKUs (duplicate of {$twin->reference})");

            if ($commit) {
                DB::transaction(fn () => $orphan->delete());
            }

            $removed++;
        }

        foreach ($kept as $line) {
            $this->warn($line);
        }

        $this->newLine();
        $this->info("{$removed} duplicate(s) " . ($commit ? 'deleted.' : 'would be deleted.'));

        return self::SUCCESS;
    }

    /** Any sign a person has engaged with this request, in words for the report. */
    private function workDoneOn(ProductRequest $request): ?string
    {
        $signs = [];

        if ($count = $request->attachments()->count()) {
            $signs[] = "{$count} attachment(s)";
        }

        if ($count = $request->activities()->where('action', 'comment')->count()) {
            $signs[] = "{$count} comment(s)";
        }

        // Who the actor is says nothing useful here: the SKU check logs its own
        // status move against whoever ran the import, so every synced request
        // looks "touched by a person". When it happened is the honest signal —
        // the import writes its history within seconds, people come along later.
        $settled = $request->created_at->copy()->addMinutes(self::SETTLE_MINUTES);

        if ($count = $request->activities()->where('created_at', '>', $settled)->count()) {
            $signs[] = "{$count} action(s) after the import finished";
        }

        return $signs ? implode(', ', $signs) : null;
    }
}
