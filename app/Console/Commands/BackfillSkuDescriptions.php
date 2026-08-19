<?php

namespace App\Console\Commands;

use App\Models\ProductRequest;
use App\Services\SkuMappingService;
use Illuminate\Console\Command;

/**
 * Reads existing descriptions back for requests validated before the app started
 * keeping them.
 *
 * Until a SKU has been checked, has_description is null — which means "nobody
 * looked", not "no copy". The request will not offer to write copy on an unknown,
 * because generating over a description somebody wrote is worse than waiting. So
 * every request from before the change stays silent until it is re-checked, and
 * waiting for the hourly job to reach two hundred of them is slow.
 *
 * This re-runs the ordinary SKU validation, which is where the description is
 * read. Nothing is written to Shopify — the check is read-only.
 */
class BackfillSkuDescriptions extends Command
{
    protected $signature = 'product-requests:backfill-descriptions
                            {--limit=0 : Stop after this many requests, 0 for all}
                            {--commit : Actually re-check, instead of only reporting what would be}';

    protected $description = 'Read existing Shopify descriptions back for requests checked before they were recorded';

    public function handle(SkuMappingService $mapping): int
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
            ->whereHas('skus', fn ($q) => $q->where('in_shopify', true)->whereNull('has_description'))
            ->with('store')
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $requests = $query->get();

        if ($requests->isEmpty()) {
            $this->info('Nothing to do — every live SKU has been checked.');
            return self::SUCCESS;
        }

        $this->line("{$requests->count()} request(s) have SKUs whose description has never been read.");
        $this->newLine();

        $checked = 0;
        $blank   = 0;

        foreach ($requests as $request) {
            $unchecked = $request->descriptionsUncheckedCount();

            if (!$commit) {
                $this->line("Would re-check {$request->reference} — {$request->brand} on "
                    . ($request->store?->name ?? 'no website') . ", {$unchecked} SKU(s)");
                continue;
            }

            try {
                $mapping->validate($request);
            } catch (\Throwable $e) {
                $this->error("{$request->reference}: " . $e->getMessage());
                continue;
            }

            $needs   = $request->refresh()->needsContentCount();
            $blank  += $needs;
            $checked++;

            $this->line("{$request->reference} — {$request->brand}: {$unchecked} checked, {$needs} need copy");
        }

        $this->newLine();

        $this->info($commit
            ? "{$checked} request(s) re-checked. {$blank} SKU(s) are live with no description and will now be offered."
            : $requests->count() . ' request(s) would be re-checked.');

        return self::SUCCESS;
    }
}
