<?php

namespace App\Jobs;

use App\Models\ProductRequest;
use App\Services\ProductRequestWorkflow;
use App\Services\SkuMappingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Re-resolves every request that is still waiting on Supply Chain and releases
 * the ones that have since been mapped.
 *
 * This is what makes "no re-submission needed" true: the brand team files once,
 * and the request moves to SKU Verified by itself as soon as Cegid catches up.
 */
class RecheckProductRequestMappingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries   = 1;

    /** Cap per run so a backlog can never hold the queue worker indefinitely. */
    public const MAX_PER_RUN = 50;

    public function handle(SkuMappingService $mapping, ProductRequestWorkflow $workflow): void
    {
        $requests = ProductRequest::whereIn('status', [
                ProductRequest::SUBMITTED,
                ProductRequest::WAITING_MAPPING,
            ])
            ->where('total_skus', '>', 0)
            ->orderBy('validated_at')   // oldest check first; nulls lead
            ->limit(self::MAX_PER_RUN)
            ->get();

        if ($requests->isEmpty()) {
            return;
        }

        $released = 0;

        foreach ($requests as $request) {
            try {
                $mapping->validate($request);
                $request->refresh();

                if ($request->validation_status !== 'completed') {
                    continue;
                }

                $before = $request->status;
                $workflow->reconcileMapping($request);

                if ($request->fresh()->status !== $before) {
                    $released++;
                }
            } catch (\Throwable $e) {
                Log::error("RecheckProductRequestMappingsJob: {$request->reference} failed: " . $e->getMessage());
            }
        }

        Log::info("RecheckProductRequestMappingsJob: rechecked {$requests->count()} request(s), {$released} advanced.");
    }
}
