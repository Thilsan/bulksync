<?php

namespace App\Services;

use App\Models\ProductRequest;
use App\Models\ProductRequestActivity;
use App\Models\User;
use App\Notifications\ProductRequestAssigned;
use App\Notifications\ProductRequestBalanceMapped;
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
        ProductRequest::PHOTOSHOOT_COMPLETED => ['ecommerce'],
        ProductRequest::IMAGE_EDITING        => ['ecommerce'],          // retired stage
        // Content is the E-Commerce owner's job now — one person per category
        // writes the copy, reviews it and publishes it.
        ProductRequest::AI_CONTENT           => ['ecommerce'],
        ProductRequest::QA_REVIEW            => ['ecommerce'],
        ProductRequest::READY_FOR_UPLOAD     => ['ecommerce'],         // retired stage
        // Publishing closes the request, so the brand side hears about it.
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
        bool $notify = true,
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

        // A bulk correction moves hundreds of requests at once and nothing has
        // actually happened to any one of them — the activity log still records
        // it, but nobody's inbox needs 200 copies of the same admin change.
        if ($notify) {
            $this->notify($request, $from, $actor?->name ?? 'System', $remarks);
        }

        return true;
    }

    /**
     * Called after SKU validation finishes. Parks the request in Waiting for
     * Mapping when anything is unresolved, and — crucially — releases it again
     * on its own the moment Supply Chain finishes, with no re-submission.
     */
    public function reconcileMapping(ProductRequest $request, ?User $actor = null, bool $notify = true): void
    {
        if ($request->isClosed()) {
            return;
        }

        $fullyMapped = $request->isFullyMapped();

        // SKU Verified is included so that turning the Cegid tick on for a
        // website reaches requests that were already waved past the mapping
        // stage while it was off. Nothing further along is touched — once a
        // shoot or the content work has started, mapping is no longer the gate.
        $beforeMapping = [ProductRequest::SUBMITTED, ProductRequest::SKU_VERIFIED];

        if (!$fullyMapped && in_array($request->status, $beforeMapping, true)) {
            // A request that has already been to Supply Chain is left where it
            // is: either they finished, or the team chose to carry on with the
            // mapped half and hold the balance (continueWithMapped). Both are
            // their decision to make, and neither is the tick's to undo.
            if ($request->status === ProductRequest::SKU_VERIFIED && $request->hasBeenToMapping()) {
                return;
            }

            $unresolved = $request->pending_skus + $request->not_mapped_skus;

            $this->transition(
                $request,
                ProductRequest::WAITING_MAPPING,
                $actor,
                "{$unresolved} of {$request->total_skus} SKUs not yet mapped — sent to Supply Chain.",
                // Going back a stage is not a move the pipeline offers.
                force:  $request->status === ProductRequest::SKU_VERIFIED,
                notify: $notify,
            );

            return;
        }

        if ($fullyMapped && in_array($request->status, [ProductRequest::SUBMITTED, ProductRequest::WAITING_MAPPING], true)) {
            $remarks = $request->requiresMapping()
                ? "All {$request->total_skus} SKUs mapped — ready for the E-Commerce team."
                : "{$request->store?->name} does not use Cegid mapping — no mapping step required.";

            $this->transition($request, ProductRequest::SKU_VERIFIED, $actor, $remarks, notify: $notify);
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

                // Assignments are personal, so they never pass through
                // recipients() — the people who only follow are copied here,
                // told who got the job rather than that it is theirs.
                if ($assignee) {
                    $told = array_filter([$assignee->id, $previousUser?->id, $actor?->id]);

                    $followers = User::brandManagersForCategory($request->category)
                        ->merge(User::requestWatchers())
                        ->unique('id')
                        ->reject(fn (User $u) => in_array($u->id, $told, true));

                    foreach ($followers as $follower) {
                        $follower->notify(ProductRequestAssigned::asCopy(
                            $request,
                            $roleLabel,
                            $actor?->name ?? 'System',
                            $assignee->name,
                            handedOverFrom: $previousUser?->name,
                        ));
                    }
                }
            } catch (\Throwable $e) {
                Log::error("ProductRequestWorkflow: assign notification failed for request {$request->id}: " . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * Staff a fresh request from its category.
     *
     * One person handles a category end to end, so they take every role the
     * request needs — except the shoot, which goes to the photoshoot
     * coordinator, and the brand-side task, which goes to the category's brand
     * manager. Either may well be the same person. Roles the
     * requester filled in themselves are left alone: an explicit choice beats the
     * default. The person is notified once, not once per role, because five
     * "you have been assigned" messages about the same request is noise.
     *
     * @return array<string, User>  field => person, for the roles this filled
     */
    public function staffFromCategory(ProductRequest $request, ?User $actor = null, bool $notify = true): array
    {
        $owner        = $request->categoryOwner();
        $coordinator  = $request->needsPhotoshoot() ? User::photoshootCoordinator() : null;
        // The category's brand manager holds the brand-side task — supplying the
        // information and approving the copy — rather than only being copied on
        // the emails. Where a category has none, the owner keeps it.
        $brandManager = User::brandManagersForCategory($request->category)->first() ?? $owner;

        $staffed  = [];
        $notified = [];

        foreach (array_keys($request->visibleAssignmentRoles()) as $field) {
            if ($request->ownerFor($field)) {
                continue;   // the requester already named someone for this role
            }

            $person = match ($field) {
                'photographer_id'  => $coordinator,
                'brand_manager_id' => $brandManager,
                default            => $owner,
            };

            if (!$person) {
                continue;
            }

            // One person picking up several roles on the same request hears once.
            // A bulk import silences it entirely — the work lands on My Tasks
            // either way, and a category owner does not need two hundred emails
            // telling them the same thing.
            $firstForPerson = $notify && !in_array($person->id, $notified, true);

            $assigned = $this->assignRole(
                request: $request,
                field:   $field,
                userId:  $person->id,
                actor:   $actor,
                notify:  $firstForPerson,
            );

            if ($assigned) {
                $staffed[$field] = $person;

                if ($firstForPerson) {
                    $notified[] = $person->id;
                }
            }
        }

        return $staffed;
    }

    /**
     * Tell the request's people that more of the balance has been mapped.
     *
     * Goes to whoever holds the request as well as the requester: the SKUs are
     * theirs to finish, and until this existed the only way to notice was to
     * re-open the request and count.
     */
    public function announceBalance(ProductRequest $request, int $justMapped): void
    {
        if ($justMapped < 1) {
            return;
        }

        $this->log(
            request:     $request,
            action:      'sku_mapping',
            description: $request->hasSkuBalance()
                ? "{$justMapped} more SKU(s) mapped — {$request->mapped_skus} of {$request->total_skus}"
                : "The balance is mapped — all {$request->total_skus} SKUs are ready",
            remarks:     $request->hasSkuBalance()
                ? "{$request->balanceSkus()} still with Supply Chain"
                : 'Nothing outstanding — the request can be finished',
        );

        try {
            $recipients = $this->recipients($request);

            if ($recipients->isNotEmpty()) {
                NotificationFacade::send($recipients, ProductRequestBalanceMapped::forRequest($request, $justMapped));
            }
        } catch (\Throwable $e) {
            Log::error("ProductRequestWorkflow: balance notification failed for request {$request->id}: " . $e->getMessage());
        }
    }

    /**
     * Move the request to match where its shoot has got to.
     *
     * Only inside the photoshoot band, and only forwards: a request already at QA
     * has moved past all this, and a tidy-up on the calendar must not drag it
     * back. Anything the workflow itself would refuse is left alone.
     *
     * @return bool  true when the request actually moved
     */
    public function syncStageWithShoot(ProductRequest $request, ?User $actor = null): bool
    {
        $target = match ($request->photoshoot_status) {
            ProductRequest::SHOOT_SCHEDULED,
            ProductRequest::SHOOT_IN_PROGRESS => ProductRequest::PHOTOSHOOT_SCHEDULED,
            ProductRequest::SHOOT_COMPLETED   => ProductRequest::PHOTOSHOOT_COMPLETED,
            default                           => null,
        };

        if ($target === null || $request->status === $target || !$request->canTransitionTo($target)) {
            return false;
        }

        $this->transition($request, $target, $actor, 'Photoshoot ' . strtolower(ProductRequest::SHOOT_STATUSES[$request->photoshoot_status]));

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

        // Everyone currently holding a role, plus whoever raised it — and the
        // people who follow without holding one: the category's brand managers
        // and the accounts copied on everything.
        $named = collect([$request->user])
            ->merge($request->currentAssignments()->with('user')->get()->pluck('user'))
            ->merge(User::brandManagersForCategory($request->category))
            ->merge(User::requestWatchers())
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
