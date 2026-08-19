<?php

namespace App\Console\Commands;

use App\Models\ProductRequest;
use App\Models\Store;
use App\Services\ProductRequestWorkflow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-runs the mapping decision for open requests against each website's current
 * Cegid tick.
 *
 * Saving a store already does this when the tick changes — this command is for
 * requests created while the tick was wrong and corrected afterwards, where
 * there is no change left to trigger it.
 *
 * Defaults to a dry run, which does the real work inside a transaction and rolls
 * it back, so what it reports is exactly what --commit would do. Notifications
 * are never sent either way: this is an administrative correction, and the
 * activity log on each request still records the move.
 */
class ReconcileProductRequestMapping extends Command
{
    protected $signature = 'product-requests:reconcile-mapping
                            {--store= : Limit to one website, by shopify domain}
                            {--commit : Actually move the requests, instead of only reporting what would happen}';

    protected $description = 'Re-check open product requests against their website\'s Cegid mapping setting';

    public function handle(ProductRequestWorkflow $workflow): int
    {
        $commit = (bool) $this->option('commit');

        if (!$commit) {
            $this->warn('Dry run — nothing will be moved. Pass --commit to apply.');
        }

        $storeId = null;

        if ($domain = $this->option('store')) {
            $store = Store::where('shopify_domain', $domain)->first();

            if (!$store) {
                $this->error("No store with domain \"{$domain}\".");
                return self::FAILURE;
            }

            $storeId = $store->id;
        }

        $moved = 0;
        $seen  = 0;

        $run = function () use ($workflow, $commit, $storeId, &$moved, &$seen) {
            ProductRequest::whereIn('status', [
                ProductRequest::SUBMITTED,
                ProductRequest::WAITING_MAPPING,
                ProductRequest::SKU_VERIFIED,
            ])
                ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                ->with('store')
                ->chunkById(200, function ($requests) use ($workflow, $commit, &$moved, &$seen) {
                    foreach ($requests as $request) {
                        $seen++;
                        $before = $request->status;

                        $workflow->reconcileMapping($request, null, notify: false);

                        if ($request->status !== $before) {
                            $moved++;
                            $this->line(($commit ? 'Moved' : 'Would move') . " {$request->reference} ({$request->store?->name}): "
                                . ProductRequest::STATUS_LABELS[$before] . ' → ' . ProductRequest::STATUS_LABELS[$request->status]);
                        }
                    }
                });
        };

        if ($commit) {
            $run();
        } else {
            // The dry run is the real thing, undone — no second copy of the
            // decision to keep in step with the workflow.
            DB::beginTransaction();

            try {
                $run();
            } finally {
                DB::rollBack();
            }
        }

        $this->newLine();
        $this->info("{$seen} open request(s) checked, {$moved} " . ($commit ? 'moved.' : 'would move.'));

        return self::SUCCESS;
    }
}
