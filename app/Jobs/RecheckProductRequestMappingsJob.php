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
 * Re-checks every request still waiting on mapping and releases the ones
 * that have since been mapped.
 *
 * This is what makes "no re-submission needed" true: the brand team files once,
 * and the request moves to SKU Verified by itself as soon as the mapping is
 * recorded — whether that came from a hand-written entry or the read-only
 * Shopify check picking the product up.
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
        $requests = ProductRequest::query()
            ->whereNotIn('status', ProductRequest::CLOSED_STATUSES)
            ->where('total_skus', '>', 0)
            ->where(function ($q) {
                // Requests still parked before the mapping gate…
                $q->whereIn('status', [ProductRequest::SUBMITTED, ProductRequest::WAITING_MAPPING])
                  // …and ones that carried on with part of their SKUs. Those have
                  // a balance nobody is watching otherwise: the request has left
                  // the mapping queue but is not finishable until Cegid
                  // catches up with the rest.
                  ->orWhereRaw('mapped_skus < total_skus');
            })
            ->orderBy('validated_at')   // oldest check first; nulls lead
            ->limit(self::MAX_PER_RUN)
            ->get();

        if ($requests->isEmpty()) {
            return;
        }

        $released = 0;
        $caught   = 0;

        foreach ($requests as $request) {
            try {
                $beforeMapped = (int) $request->mapped_skus;
                $beforeStatus = $request->status;

                $mapping->validate($request);
                $request->refresh();

                if ($request->validation_status !== 'completed') {
                    continue;
                }

                $workflow->reconcileMapping($request);
                $request->refresh();

                if ($request->status !== $beforeStatus) {
                    $released++;
                }

                // Progress on the balance is news in its own right — the person
                // holding the request has work to do the moment it lands.
                if ($request->mapped_skus > $beforeMapped
                    && !in_array($beforeStatus, [ProductRequest::SUBMITTED, ProductRequest::WAITING_MAPPING], true)) {
                    $workflow->announceBalance($request, $request->mapped_skus - $beforeMapped);
                    $caught++;
                }
            } catch (\Throwable $e) {
                Log::error("RecheckProductRequestMappingsJob: {$request->reference} failed: " . $e->getMessage());
            }
        }

        Log::info("RecheckProductRequestMappingsJob: rechecked {$requests->count()} request(s), "
            . "{$released} advanced, {$caught} balance update(s) announced.");
    }
}
