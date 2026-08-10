<?php

namespace App\Services;

use App\Models\ProductRequest;
use App\Models\ProductRequestActivity;
use App\Models\User;
use App\Notifications\ProductRequestAssigned;
use App\Notifications\ProductRequestHandedOff;
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
        ProductRequest::IMAGE_EDITING        => ['image_editor', 'ecommerce'],
        ProductRequest::AI_CONTENT           => ['content'],
        // The content person reviews and publishes their own work.
        ProductRequest::QA_REVIEW            => ['content', 'qa'],
        ProductRequest::READY_FOR_UPLOAD     => ['content'],           // retired stage
        // Publishing closes the request, so the brand side hears about it.
        ProductRequest::PUBLISHED            => ['brand_manager', 'ecommerce', 'content'],
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
        // Publishing is the end of the road, so it stamps completion too —
        // otherwise every closed request would show as never completed.
        match ($to) {
            ProductRequest::PUBLISHED => [$request->published_at = now(), $request->completed_at = now()],
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
            $remarks = $request->requiresMapping()
                ? "All {$request->total_skus} SKUs mapped — ready for the E-Commerce team."
                : "{$request->store?->name} does not use Cegid mapping — no mapping step required.";

            $this->transition($request, ProductRequest::SKU_VERIFIED, $actor, $remarks);
        }
    }

    /**
     * Give a role to someone, with an optional brief and deadline.
     *
     * Single entry point for every assign path — submission, the assignments
     * panel, bulk actions, claiming and handover all came through here so the
     * owner column, the brief row, the audit trail and the notification can
     * never disagree with each other.
     *
     * @return bool  false when nothing changed
     */
    public function assignRole(
        ProductRequest $request,
        string $field,
        ?int $userId,
        ?User $actor = null,
        ?string $title = null,
        ?string $dueDate = null,
        bool $notify = true,
        ?string $description = null,
    ): bool {
        if (!array_key_exists($field, ProductRequest::ASSIGNMENT_ROLES)) {
            return false;
        }

        $roleLabel = ProductRequest::ASSIGNMENT_ROLES[$field];

        // The task comes from the workflow, not from whoever filled in the form —
        // same wording on every request, and nothing to mistype.
        $title ??= ProductRequest::taskForRole($field);

        $existing = $request->assignments()->current()->where('role', $field)->first();
        $previous = $existing?->user_id;
        $assignee = $userId ? User::find($userId) : null;

        if ($userId && !$assignee?->is_active) {
            return false;   // never hand work to a disabled account
        }

        // Same person: the only thing that can change is their brief.
        $detailOnly = $previous === $assignee?->id && $existing !== null;

        if ($detailOnly) {
            $changed = $existing->title !== $title
                || (string) $existing->due_date?->toDateString() !== (string) $dueDate;

            if (!$changed) {
                return false;
            }

            $existing->update(['title' => $title, 'due_date' => $dueDate]);
        } else {
            if ($previous === null && $assignee === null) {
                return false;   // nothing there, nothing asked for
            }

            // Close the outgoing assignment rather than overwriting it — that
            // closed row is the record of who held this role and for how long.
            $existing?->update(['ended_at' => now()]);

            if ($assignee) {
                $request->assignments()->create([
                    'role'        => $field,
                    'user_id'     => $assignee->id,
                    'assigned_by' => $actor?->id,
                    'title'       => $title,
                    'due_date'    => $dueDate,
                ]);
            }
        }

        $request->load(['assignments', 'currentAssignments.user']);

        // Callers may phrase it better — a self-claim reads as "took this task",
        // which says more in the audit trail than "assigned as".
        $description ??= match (true) {
            !$assignee  => "{$roleLabel} unassigned",
            $detailOnly => "{$roleLabel} brief updated for {$assignee->name}",
            default     => "{$assignee->name} assigned as {$roleLabel}",
        };

        $this->log(
            request:     $request,
            action:      'assigned',
            description: $description,
            actor:       $actor,
            remarks:     $this->briefRemark($title, $dueDate),
        );

        // Anyone losing the task needs telling as much as the person gaining it —
        // otherwise the previous owner carries on thinking it is still theirs.
        $previousUser = $previous && $previous !== $assignee?->id ? User::find($previous) : null;

        if ($notify && !$detailOnly) {
            try {
                if ($assignee && $assignee->id !== $actor?->id) {
                    $assignee->notify(ProductRequestAssigned::forRequest(
                        $request,
                        $roleLabel,
                        $actor?->name ?? 'System',
                        handedOverFrom: $previousUser?->name,
                    ));
                }

                if ($previousUser?->is_active && $previousUser->id !== $actor?->id) {
                    $previousUser->notify(ProductRequestHandedOff::forRequest(
                        $request,
                        $roleLabel,
                        $assignee?->name ?? 'nobody',
                        $actor?->name ?? 'System',
                    ));
                }
            } catch (\Throwable $e) {
                Log::error("ProductRequestWorkflow: assign notification failed for request {$request->id}: " . $e->getMessage());
            }
        }

        return true;
    }

    private function briefRemark(?string $title, ?string $dueDate): ?string
    {
        $parts = array_filter([
            $title   ? "Task: {$title}" : null,
            $dueDate ? 'Due ' . \Illuminate\Support\Carbon::parse($dueDate)->format('d M Y') : null,
        ]);

        return $parts ? implode(' · ', $parts) : null;
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

        // Everyone currently holding a role, plus whoever raised it.
        $named = collect([$request->user])
            ->merge($request->currentAssignments()->with('user')->get()->pluck('user'))
            ->filter();

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
