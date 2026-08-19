<?php

namespace App\Console\Commands;

use App\Models\ProductRequest;
use App\Services\ProductRequestDraftBuilder;
use App\Services\SkuMappingService;
use Illuminate\Console\Command;

/**
 * Fills in, for every open request, which SKUs have copy — on the sheet and in
 * Shopify.
 *
 * Both are null until something has looked, and null is not "blank": the request
 * will not offer to write copy on an unknown, because that risks writing over a
 * description the brand team did supply. So requests from before these columns
 * existed stay silent, and waiting for them to be opened one at a time is slow.
 *
 * The sheet is what decides whether copy is coming at all. Shopify only says
 * whether writing would overwrite something. Both reads are read-only.
 */
class BackfillSkuDescriptions extends Command
{
    protected $signature = 'product-requests:backfill-descriptions
                            {--limit=0 : Stop after this many requests, 0 for all}
                            {--commit : Actually re-check, instead of only reporting what would be}';

    protected $description = 'Fill in which SKUs have copy on the sheet and in Shopify, for requests checked before it was recorded';

    public function handle(SkuMappingService $mapping, ProductRequestDraftBuilder $builder): int
    {
        $commit = (bool) $this->option('commit');
        $limit  = (int) $this->option('limit');

        if (!$commit) {
            $this->warn('Dry run — nothing will be re-checked. Pass --commit to apply.');
        }

        // Only requests with live SKUs nobody has read a description for. A closed
        // request is not going to be worked on, so it is not worth the API calls.
        $query = ProductRequest::query()
            ->whereNotIn('status', ProductRequest::CLOSED_STATUSES)
            ->whereHas('skus', fn ($q) => $q->where('in_shopify', true)
                ->where(fn ($w) => $w->whereNull('has_description')->orWhereNull('sheet_has_description')))
            ->with('store')
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $requests = $query->get();

        if ($requests->isEmpty()) {
            $this->info('Nothing to do — the sheet and Shopify have been read for every live SKU.');
            return self::SUCCESS;
        }

        $this->line("{$requests->count()} request(s) have SKUs whose copy has never been checked.");
        $this->newLine();

        $checked = 0;
        $blank   = 0;

        foreach ($requests as $request) {
            $unchecked = max($request->descriptionsUncheckedCount(), $request->sheetUncheckedCount());

            if (!$commit) {
                $this->line("Would re-check {$request->reference} — {$request->brand} on "
                    . ($request->store?->name ?? 'no website') . ", {$unchecked} SKU(s)");
                continue;
            }

            try {
                $mapping->validate($request);
            } catch (\Throwable $e) {
                $this->error("{$request->reference}: Shopify check failed — " . $e->getMessage());
                continue;
            }

            // The sheet read can fail on its own — a tab with no description column,
            // or a category with no tab. That is worth reporting per request rather
            // than abandoning the whole run.
            try {
                $sheet = $builder->syncSheetDescriptions($request);
                $note  = "sheet: {$sheet['with']} supplied, {$sheet['without']} not";
            } catch (\Throwable $e) {
                $note = 'sheet not read — ' . $e->getMessage();
            }

            $needs   = $request->refresh()->needsContentCount();
            $blank  += $needs;
            $checked++;

            $this->line("{$request->reference} — {$request->brand}: {$note}; {$needs} need copy");
        }

        $this->newLine();

        $this->info($commit
            ? "{$checked} request(s) re-checked. {$blank} SKU(s) are live with no description and will now be offered."
            : $requests->count() . ' request(s) would be re-checked.');

        return self::SUCCESS;
    }
}
