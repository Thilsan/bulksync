<?php

namespace App\Console\Commands;

use App\Models\ProductRequest;
use App\Models\User;
use App\Services\ProductRequestWorkflow;
use Illuminate\Console\Command;

/**
 * Brand managers being emailed about requests outside their categories.
 *
 * A request is staffed from the settings as they stood when it was raised, and
 * whoever holds a slot is copied on everything it sends. Narrowing a brand
 * manager's categories or websites afterwards left them holding slots on
 * requests that are no longer theirs — the "wrong category" emails.
 */
class AuditBrandManagerRecipients extends Command
{
    protected $signature = 'product-requests:audit-brand-recipients
                            {--user= : Just this person, by id or email}
                            {--commit : Re-staff the slots the app filled in, instead of only reporting}';

    protected $description = 'List brand managers emailed about open requests outside their categories and websites';

    public function handle(ProductRequestWorkflow $workflow): int
    {
        $commit = (bool) $this->option('commit');
        $only   = $this->option('user');

        if (!$commit) {
            $this->warn('Dry run — nothing will change. Pass --commit to re-staff.');
        }

        $requests = ProductRequest::query()
            ->whereNotIn('status', ProductRequest::CLOSED_STATUSES)
            ->with(['store', 'user', 'currentAssignments.user'])
            ->orderBy('id')
            ->get();

        $found = 0;
        $fixed = 0;

        foreach ($requests as $request) {
            $brandSide = User::brandManagersForCategory($request->category, $request->brand, $request->store_id);

            $strays = $workflow->recipients($request)->filter(
                fn (User $u) => $u->isBrandManagerOnly()
                    && !$brandSide->contains('id', $u->id)
                    && (!$only || (string) $u->id === $only || $u->email === $only),
            );

            if ($strays->isEmpty()) {
                continue;
            }

            foreach ($strays as $stray) {
                $found++;
                $this->line(sprintf(
                    '%-12s %-22s %-14s %-24s %s',
                    $request->reference,
                    $request->store?->name ?? '—',
                    $request->category ?? '—',
                    $stray->name,
                    $this->why($request, $stray),
                ));
            }

            if ($commit && $workflow->restaffFromCategory($request, notify: false)) {
                $fixed++;
            }
        }

        $this->info("{$found} out-of-scope recipient(s) across open requests.");

        if ($commit) {
            $this->info("{$fixed} request(s) re-staffed. Slots picked by hand are left alone — run again to see what remains.");
        }

        return self::SUCCESS;
    }

    private function why(ProductRequest $request, User $user): string
    {
        $slots = $request->currentAssignments->where('user_id', $user->id);

        if ($slots->isNotEmpty()) {
            return $slots->map(fn ($a) => ProductRequest::ASSIGNMENT_ROLES[$a->role] ?? $a->role)->implode(', ')
                . ($slots->every(fn ($a) => $a->auto) ? ' slot (auto — --commit fixes)' : ' slot (picked by hand)');
        }

        return $request->user_id === $user->id ? 'raised the request' : 'unknown';
    }
}
