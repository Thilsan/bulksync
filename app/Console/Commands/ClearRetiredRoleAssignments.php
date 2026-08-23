<?php

namespace App\Console\Commands;

use App\Models\ProductRequest;
use App\Services\ProductRequestWorkflow;
use Illuminate\Console\Command;

/**
 * Ends assignments to roles that no longer exist.
 *
 * A retired role stays on screen while somebody holds it, so the slot can be
 * cleared rather than becoming invisible-but-active — an assignment nobody can
 * see still puts the request on that person's desk. That is right for one or two
 * requests and useless across a hundred and fifty, which is why this exists.
 *
 * Supply Chain is the reason: there is no such team, but every imported request
 * was staffed with one before that was known.
 */
class ClearRetiredRoleAssignments extends Command
{
    protected $signature = 'product-requests:clear-retired-roles
                            {--role= : Just this role, e.g. supply_chain_id}
                            {--commit : Actually clear them, instead of only reporting what would go}';

    protected $description = 'End assignments to roles that have been retired, so their slots disappear';

    public function handle(ProductRequestWorkflow $workflow): int
    {
        $commit = (bool) $this->option('commit');
        $only   = $this->option('role');

        if (!$commit) {
            $this->warn('Dry run — nothing will change. Pass --commit to apply.');
        }

        $roles = $only ? [$only] : ProductRequest::RETIRED_ROLES;

        foreach ($roles as $role) {
            if (!array_key_exists($role, ProductRequest::ASSIGNMENT_ROLES)) {
                $this->error("\"{$role}\" is not a role. Known: " . implode(', ', array_keys(ProductRequest::ASSIGNMENT_ROLES)));
                return self::FAILURE;
            }
        }

        $cleared = 0;

        foreach ($roles as $role) {
            $label = ProductRequest::ASSIGNMENT_ROLES[$role];

            $requests = ProductRequest::query()
                ->whereNotIn('status', ProductRequest::CLOSED_STATUSES)
                ->whereHas('currentAssignments', fn ($q) => $q->where('role', $role))
                ->with(['currentAssignments.user'])
                ->orderBy('id')
                ->get();

            if ($requests->isEmpty()) {
                continue;
            }

            $this->line("{$label}: {$requests->count()} request(s) still hold it.");

            foreach ($requests as $request) {
                $holder = $request->ownerFor($role)?->name ?? 'somebody';

                if ($commit) {
                    // Not notified: nobody is being asked to do anything, and a
                    // hundred and fifty "you have been unassigned" emails about a
                    // role that no longer exists helps nobody.
                    $workflow->assignRole(
                        request: $request,
                        field:   $role,
                        userId:  null,
                        notify:  false,
                    );
                }

                $cleared++;
            }

            $this->line("  e.g. {$requests->first()->reference} — held by {$holder}");
        }

        $this->newLine();

        $this->info($cleared === 0
            ? 'Nothing to clear — no request holds a retired role.'
            : $cleared . ' assignment(s) ' . ($commit ? 'cleared.' : 'would be cleared.'));

        return self::SUCCESS;
    }
}
