<?php

namespace App\Console\Commands;

use App\Models\ProductRequest;
use App\Models\ProductRequestSheetSync;
use App\Services\ProductRequestSheetSyncService;
use App\Services\ProductRequestWorkflow;
use App\Services\SkuMappingService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Folds together the requests the sheet sync made more than once for one product.
 *
 * Two ways it happened. Several master rows for one brand on one date each became
 * a request, and each matched all of the brand's SKUs for that date — TRIUMPH on
 * 13 Oct was three requests of 4,651 SKUs. And a renumbered row looked new, so
 * the same product came in again under its new number: those three, twice.
 *
 * A product is its website, brand, sheet date and category — the same identity
 * the sync now matches on. Of each set of copies one stays:
 *   - the one a person has worked on, if exactly one has been
 *   - otherwise the oldest
 * The others' SKUs and sheet numbers move to it and they are deleted. A set where
 * more than one copy has been worked on, or the copies stand at different stages,
 * is reported and left for a person.
 */
class MergeDuplicateSheetRequests extends Command
{
    protected $signature = 'product-requests:merge-duplicates
                            {--commit : Actually merge, instead of only reporting what would change}';

    protected $description = 'Merge requests the sheet sync created more than once for the same brand, date and website';

    public function handle(SkuMappingService $mapping, ProductRequestWorkflow $workflow): int
    {
        $commit = (bool) $this->option('commit');

        if (!$commit) {
            $this->warn('Dry run — nothing will change. Pass --commit to merge.');
        }

        $sets = ProductRequest::whereNotNull('sheet_request_no')
            ->whereNotNull('sheet_request_date')
            ->with(['store', 'activities'])
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($request) => ProductRequestSheetSyncService::fingerprintOf($request) ?? "none-{$request->id}")
            ->filter(fn (Collection $set) => $set->count() > 1);

        $merged = 0;
        $backup = [];
        $review = [];

        foreach ($sets as $set) {
            $worked = $set->mapWithKeys(fn ($r) => [$r->id => $this->workDoneOn($r)])->filter();
            $label  = "{$set->first()->brand} / {$set->first()->category}, {$set->first()->sheet_request_date->toDateString()} on "
                . ($set->first()->store?->name ?? 'no website');

            // A request the old number-only matching renamed after another row's
            // brand looks like a copy of that row's request, and is not one.
            // repair-reused-numbers puts it back first; until then it stays.
            $renamed = $set->filter(fn ($r) => $r->activities->contains(function ($a) use ($r) {
                return $a->action === 'sheet_updated'
                    && preg_match('/brand: "[^"]*" → "([^"]*)"/u', (string) $a->remarks, $m)
                    // Still wearing the name the sheet gave it; once repaired, it is itself again.
                    && ProductRequestSheetSyncService::brandKey($m[1]) === ProductRequestSheetSyncService::brandKey((string) $r->brand);
            }));

            if ($renamed->isNotEmpty()) {
                $review[] = "{$label}: " . $renamed->pluck('reference')->implode(', ')
                    . ' renamed from another brand by the sheet — run product-requests:repair-reused-numbers first; left for a person';
                continue;
            }

            if ($worked->count() > 1) {
                $review[] = "{$label}: " . $set->pluck('reference')->implode(', ')
                    . ' — more than one has been worked on, left for a person';
                continue;
            }

            if ($set->pluck('status')->unique()->count() > 1) {
                $review[] = "{$label}: " . $set->map(fn ($r) => "{$r->reference} ({$r->status})")->implode(', ')
                    . ' — at different stages, left for a person';
                continue;
            }

            $keep   = $worked->isNotEmpty() ? $set->firstWhere('id', $worked->keys()->first()) : $set->first();
            $copies = $set->reject(fn ($r) => $r->id === $keep->id);

            $this->line(($commit ? 'Merged' : 'Would merge') . " {$copies->pluck('reference')->implode(', ')} into {$keep->reference} — {$label}");

            if ($commit) {
                DB::transaction(function () use ($keep, $copies, $mapping, $workflow, &$backup) {
                    $have  = $keep->skus()->pluck('sku')->map(fn ($sku) => strtoupper(trim($sku)))->all();
                    $added = 0;

                    foreach ($copies as $copy) {
                        $backup[$copy->reference] = $copy->toArray() + ['skus' => $copy->skus()->get()->toArray()];

                        foreach ($copy->skus()->get() as $sku) {
                            if (in_array(strtoupper(trim($sku->sku)), $have, true)) {
                                continue;
                            }

                            $sku->replicate()->fill(['product_request_id' => $keep->id])->save();
                            $have[] = strtoupper(trim($sku->sku));
                            $added++;
                        }

                        // The copy's numbers now name the request that stays, so
                        // the sync reads those rows as already imported.
                        ProductRequestSheetSync::where('product_request_id', $copy->id)
                            ->update(['product_request_id' => $keep->id]);

                        $copy->delete();
                    }

                    $mapping->rollUp($keep);

                    $workflow->log(
                        request:     $keep,
                        action:      'duplicates_merged',
                        description: 'Merged duplicate imports of the same sheet rows: ' . $copies->pluck('reference')->implode(', '),
                        remarks:     $added ? "{$added} SKU(s) only the copies had were moved here" : null,
                    );
                });
            }

            $merged += $copies->count();
        }

        foreach ($review as $line) {
            $this->warn($line);
        }

        $this->newLine();
        $this->info($merged . ' duplicate request(s) ' . ($commit ? 'merged and deleted.' : 'would be merged.'));

        if ($commit && $backup) {
            $path = 'merge-duplicates-' . now()->format('Ymd-His') . '.json';
            Storage::disk('local')->put($path, json_encode($backup));
            $this->info('Every deleted request and its SKUs are saved in ' . Storage::disk('local')->path($path) . ' — delete it once you are happy.');
        }

        return self::SUCCESS;
    }

    /** How long after an import its own staffing can still be landing. */
    private const IMPORT_MINUTES = 5;

    /**
     * Activity the system writes by itself. The copies all carry the same of it,
     * so none of it is anything deleting a copy would lose.
     */
    private const AUTOMATIC_ACTIONS = [
        'created', 'sku_mapping', 'mapping_requested', 'skus_added', 'sheet_updated', 'duplicates_merged',
    ];

    /**
     * What a person did on this request that deleting it would throw away, in
     * words for the report — or null.
     *
     * Stricter about what counts than CleanDuplicateSyncedRequests::workDoneOn():
     * the sync now staffs every request it imports and asks for its mapping, so
     * an assignment or a mapping request made at import is not anyone's work.
     */
    private function workDoneOn(ProductRequest $request): ?string
    {
        $importedBy = $request->created_at->copy()->addMinutes(self::IMPORT_MINUTES);
        $signs      = [];

        if ($count = $request->attachments()->count()) {
            $signs[] = "{$count} attachment(s)";
        }

        if ($count = $request->skus()->whereNotNull('mapping_set_by')->count()) {
            $signs[] = "{$count} SKU(s) mapped by hand";
        }

        if ($count = $request->currentAssignments()->where('created_at', '>', $importedBy)->count()) {
            $signs[] = "{$count} assignment(s) changed after import";
        }

        $byHand = $request->activities->reject(function ($activity) use ($importedBy) {
            if (in_array($activity->action, self::AUTOMATIC_ACTIONS, true)) {
                return true;
            }

            if ($activity->action === 'assigned') {
                return $activity->created_at <= $importedBy;
            }

            if ($activity->action !== 'status_changed') {
                return false;   // a comment, a hold, a decision
            }

            // Published or reopened because of the sheet's Completed column.
            if (str_starts_with((string) $activity->remarks, 'The tracking sheet')) {
                return true;
            }

            return !str_starts_with((string) $activity->remarks, 'Continuing with')
                && in_array("{$activity->from_status}>{$activity->to_status}", CleanDuplicateSyncedRequests::AUTOMATIC_MOVES, true);
        });

        if ($byHand->isNotEmpty()) {
            $signs[] = $byHand->count() . ' action(s) by a person ['
                . $byHand->take(3)->map(fn ($a) => trim($a->action . ' ' . ($a->from_status ?? '') . '>' . ($a->to_status ?? '')))->implode('; ')
                . ']';
        }

        return $signs ? implode(', ', $signs) : null;
    }
}
