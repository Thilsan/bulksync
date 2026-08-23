<?php

namespace App\Jobs;

use App\Models\ProductRequest;
use App\Models\User;
use App\Services\ProductRequestWorkflow;
use App\Services\SkuMappingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes the read-only Shopify check for a request's SKUs, then lets the
 * workflow decide whether it is blocked on mapping or ready to move on.
 *
 * This is what the Validate SKUs button runs, and since the check is the only
 * thing that sets a mapping status, it is also where somebody first learns the
 * balance has come through.
 */
class ValidateProductRequestSkusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries   = 2;

    public function __construct(
        public readonly int  $requestId,
        public readonly ?int $actorId = null,
        public readonly bool $reconcile = true,
    ) {}

    public function handle(SkuMappingService $mapping, ProductRequestWorkflow $workflow): void
    {
        $request = ProductRequest::find($this->requestId);

        if (!$request) {
            Log::warning("ValidateProductRequestSkusJob: request {$this->requestId} no longer exists.");
            return;
        }

        $beforeMapped = (int) $request->mapped_skus;
        $beforeStatus = $request->status;

        $mapping->validate($request);

        $request->refresh();

        if ($this->reconcile && $request->validation_status === 'completed') {
            $workflow->reconcileMapping($request, $this->actorId ? User::find($this->actorId) : null);
            $request->refresh();

            // A request already carrying on with part of its SKUs has people
            // waiting on the rest, and they are not watching the SKUs tab.
            if ($request->mapped_skus > $beforeMapped
                && !in_array($beforeStatus, [ProductRequest::SUBMITTED, ProductRequest::WAITING_MAPPING], true)) {
                $workflow->announceBalance($request, $request->mapped_skus - $beforeMapped);
            }
        }

        Log::info("ValidateProductRequestSkusJob: {$request->reference} — {$request->mapped_skus} mapped, {$request->pending_skus} pending, {$request->not_mapped_skus} not mapped.");
    }

    public function failed(\Throwable $e): void
    {
        ProductRequest::where('id', $this->requestId)->update([
            'validation_status' => 'failed',
            'validation_error'  => $e->getMessage(),
        ]);
    }
}
