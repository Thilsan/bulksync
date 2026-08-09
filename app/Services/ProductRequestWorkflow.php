<?php

namespace App\Services;

use App\Models\ProductRequest;
use App\Models\ProductRequestActivity;
use App\Models\User;
use App\Notifications\ProductRequestStatusChanged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * Single entry point for moving a Product Creation Request through its stages.
 *
 * Everything that changes a request's status goes through transition() so the
 * audit trail and the notifications can never drift apart from the data — the
 * exact failure the email-based process had.
 */
class ProductRequestWorkflow
{
    /**
     * Which workflow roles hear about each stage. Ordered by who has to act.
     * The requester and the assigned team members are always added on top.
     */
    private const NOTIFY_ROLES = [
        ProductRequest::SUBMITTED            => ['ecommerce'],
        ProductRequest::WAITING_MAPPING      => ['supply_chain', 'ecommerce'],
        ProductRequest::SKU_VERIFIED         => ['ecommerce'],
        ProductRequest::WAITING_IMAGES       => ['photographer', 'ecommerce'],
        ProductRequest::PHOTOSHOOT_SCHEDULED => ['photographer'],
        ProductRequest::PHOTOSHOOT_COMPLETED => ['ecommerce', 'content'],
        ProductRequest::IMAGE_EDITING        => ['ecommerce'],
        ProductRequest::AI_CONTENT           => ['content'],
        ProductRequest::QA_REVIEW            => ['qa'],
        ProductRequest::READY_FOR_UPLOAD     => ['ecommerce'],
        ProductRequest::PUBLISHED            => ['brand_manager', 'ecommerce'],
        ProductRequest::COMPLETED            => ['brand_manager', 'ecommerce'],
        ProductRequest::CANCELLED            => ['ecommerce', 'brand_manager'],
    ];

    /**
     * Move a request to a new status, writing the audit entry and telling the
     * teams involved. Returns false when the transition isn't allowed.
     */
    public function transition(
        ProductRequest $request,
        string $to,
        ?User $actor = null,
        ?string $remarks = null,
        bool $force = false,
    ): bool {
        if ($request->status === $to) {
            return false;
        }

        if (!$force && !$request->canTransitionTo($to)) {
            return false;
        }

        $from = $request->status;

        $request->fill(['status' => $to]);

        // Stamp the milestone timestamps the dashboard and reporting read.
        match ($to) {
            ProductRequest::PUBLISHED => $request->published_at = now(),
            ProductRequest::COMPLETED => $request->completed_at = now(),
            ProductRequest::CANCELLED => $request->cancelled_at = now(),
            default                   => null,
        };

        $request->save();

        $fromLabel = ProductRequest::STATUS_LABELS[$from] ?? $from;
        $toLabel   = ProductRequest::STATUS_LABELS[$to] ?? $to;

        $this->log(
            request:     $request,
            action:      'status_changed',
            description: "Status changed from {$fromLabel} to {$toLabel}",
            actor:       $actor,
            fromStatus:  $from,
            toStatus:    $to,
            remarks:     $remarks,
        );

        $this->notify($request, $from, $actor?->name ?? 'System', $remarks);

        return true;
    }

    /**
     * Called after SKU validation finishes. Parks the request in Waiting for
     * Mapping when anything is unresolved, and — crucially — releases it again
     * on its own the moment Supply Chain finishes, with no re-submission.
     */
    public function reconcileMapping(ProductRequest $request, ?User $actor = null): void
    {
        if ($request->isClosed()) {
            return;
        }

        $fullyMapped = $request->isFullyMapped();

        if (!$fullyMapped && $request->status === ProductRequest::SUBMITTED) {
            $unresolved = $request->pending_skus + $request->not_mapped_skus;

            $this->transition(
                $request,
                ProductRequest::WAITING_MAPPING,
                $actor,
                "{$unresolved} of {$request->total_skus} SKUs not yet mapped — sent to Supply Chain.",
            );

            return;
        }

        if ($fullyMapped && in_array($request->status, [ProductRequest::SUBMITTED, ProductRequest::WAITING_MAPPING], true)) {
            $this->transition(
                $request,
                ProductRequest::SKU_VERIFIED,
                $actor,
                "All {$request->total_skus} SKUs mapped — ready for the E-Commerce team.",
            );
        }
    }

    public function log(
        ProductRequest $request,
        string $action,
        string $description,
        ?User $actor = null,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $remarks = null,
    ): ProductRequestActivity {
        return ProductRequestActivity::create([
            'product_request_id' => $request->id,
            'user_id'            => $actor?->id,
            'action'             => $action,
            'from_status'        => $fromStatus,
            'to_status'          => $toStatus ?? $request->status,
            'description'        => $description,
            'remarks'            => $remarks,
            'created_at'         => now(),
        ]);
    }

    /** Everyone who should hear about the request in its current status. */
    public function recipients(ProductRequest $request): Collection
    {
        $roles = self::NOTIFY_ROLES[$request->status] ?? [];

        $byRole = $roles
            ? User::where('is_active', true)->whereIn('pcr_role', $roles)->get()
            : collect();

        $named = collect([
            $request->user,
            $request->assignee,
            $request->photographer,
            $request->contentOwner,
            $request->qaOwner,
        ])->filter();

        return $byRole->merge($named)->filter(fn ($u) => $u->is_active)->unique('id')->values();
    }

    private function notify(ProductRequest $request, ?string $fromStatus, string $actorName, ?string $remarks): void
    {
        try {
            $recipients = $this->recipients($request->fresh());

            if ($recipients->isEmpty()) {
                return;
            }

            NotificationFacade::send(
                $recipients,
                ProductRequestStatusChanged::forRequest($request, $fromStatus, $actorName, $remarks),
            );
        } catch (\Throwable $e) {
            // Notifying must never roll back a status change the user just made.
            Log::error("ProductRequestWorkflow: notification failed for request {$request->id}: " . $e->getMessage());
        }
    }
}
