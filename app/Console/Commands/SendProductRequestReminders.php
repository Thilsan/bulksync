<?php

namespace App\Console\Commands;

use App\Models\ProductRequest;
use App\Models\User;
use App\Notifications\ProductRequestReminder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Chases requests that have gone quiet, so nobody has to.
 *
 * Status changes already notify. What was missing is the opposite signal — a
 * request where *nothing* has happened. Three things earn a nudge:
 *
 *   • personal — someone's own task deadline has passed or is imminent
 *   • stalled  — sitting at the same stage longer than --stale-days
 *   • at risk  — online launch inside --due-days and not Ready for Upload
 *   • blocked  — on hold longer than --blocked-days
 *
 * Everything a person is holding up arrives as one message, not one per
 * request, so the digest stays readable and never becomes noise to ignore.
 */
class SendProductRequestReminders extends Command
{
    protected $signature = 'product-requests:remind
        {--stale-days=3   : Nudge after this many days at the same stage}
        {--due-days=3     : Nudge when the online launch is this close}
        {--blocked-days=2 : Nudge when a request has been on hold this long}
        {--dry-run        : Show what would be sent without sending it}';

    protected $description = 'Remind owners about stalled, at-risk and blocked product creation requests';

    public function handle(): int
    {
        $staleDays   = max(1, (int) $this->option('stale-days'));
        $dueDays     = max(0, (int) $this->option('due-days'));
        $blockedDays = max(1, (int) $this->option('blocked-days'));
        $dryRun      = (bool) $this->option('dry-run');

        $open = ProductRequest::query()
            ->whereNotIn('status', ProductRequest::CLOSED_STATUSES)
            ->with(['assignee', 'supplyChainOwner', 'photographer', 'imageEditor', 'contentOwner', 'qaOwner',
                    'user', 'assignments.user'])
            ->get();

        // Grouped by recipient so one person gets one digest, not five emails.
        $perUser = [];

        $add = function (User $user, ProductRequest $request, string $reason) use (&$perUser) {
            $perUser[$user->id] ??= ['user' => $user, 'items' => []];
            $perUser[$user->id]['items'][] = [
                'request_id' => $request->id,
                'reference'  => $request->reference,
                'name'       => $request->displayName(),
                'reason'     => $reason,
            ];
        };

        foreach ($open as $request) {
            // A personal deadline is the sharpest signal there is — it names one
            // person and one commitment, so chase it first and on its own.
            foreach ($request->assignments as $brief) {
                if (!$brief->user?->is_active) {
                    continue;
                }

                if ($brief->isOverdue()) {
                    $late = abs($brief->daysLeft());
                    $add($brief->user, $request,
                        "your {$brief->roleLabel()} task \"{$brief->taskTitle()}\" was due "
                        . $late . ' ' . ($late === 1 ? 'day' : 'days') . ' ago');
                } elseif ($brief->isDueSoon($dueDays)) {
                    $days = $brief->daysLeft();
                    $add($brief->user, $request,
                        "your {$brief->roleLabel()} task \"{$brief->taskTitle()}\" is due "
                        . ($days === 0 ? 'today' : "in {$days} " . ($days === 1 ? 'day' : 'days')));
                }
            }

            $reason = $this->reasonFor($request, $staleDays, $dueDays, $blockedDays);

            if (!$reason) {
                continue;
            }

            foreach ($this->chaseTargets($request) as $user) {
                $add($user, $request, $reason);
            }
        }

        if (empty($perUser)) {
            $this->info('Nothing needs chasing.');
            return self::SUCCESS;
        }

        foreach ($perUser as $entry) {
            $user  = $entry['user'];
            $items = $entry['items'];

            $this->line(sprintf('%-28s %d %s', $user->name, count($items), count($items) === 1 ? 'request' : 'requests'));

            foreach ($items as $item) {
                $this->line("    {$item['reference']}  {$item['reason']}");
            }

            if (!$dryRun) {
                try {
                    $user->notify(new ProductRequestReminder($items));
                } catch (\Throwable $e) {
                    Log::error("product-requests:remind failed for user {$user->id}: " . $e->getMessage());
                }
            }
        }

        $people = count($perUser);
        $this->info($dryRun
            ? "Dry run — {$people} " . ($people === 1 ? 'person' : 'people') . ' would be reminded.'
            : "Reminded {$people} " . ($people === 1 ? 'person' : 'people') . '.');

        return self::SUCCESS;
    }

    /** Why this request deserves a nudge today, or null if it doesn't. */
    private function reasonFor(ProductRequest $request, int $staleDays, int $dueDays, int $blockedDays): ?string
    {
        $days = $request->daysToOnlineLaunch();

        // Blocked longest-standing first: it explains the other two.
        if ($request->isOnHold()) {
            $held = $request->heldForDays() ?? 0;

            if ($held >= $blockedDays) {
                return "blocked {$held} " . ($held === 1 ? 'day' : 'days') . " — {$request->hold_reason}";
            }

            return null; // recently blocked; someone already knows
        }

        if ($days !== null && $days < 0) {
            return 'online launch was ' . abs($days) . ' ' . (abs($days) === 1 ? 'day' : 'days') . ' ago and it is not published';
        }

        if ($days !== null && $days <= $dueDays && $request->status !== ProductRequest::READY_FOR_UPLOAD) {
            return $days === 0
                ? 'launches online today and is still at ' . $request->statusLabel()
                : "launches in {$days} " . ($days === 1 ? 'day' : 'days') . ' and is still at ' . $request->statusLabel();
        }

        // updated_at moves on any change, so "unchanged since" is a fair proxy
        // for a stage nobody has touched.
        $idle = (int) $request->updated_at->startOfDay()->diffInDays(now()->startOfDay());

        if ($idle >= $staleDays) {
            return "no movement for {$idle} days at " . $request->statusLabel();
        }

        return null;
    }

    /**
     * Who to chase: whoever owns the current stage. Falls back to everyone
     * holding that stage's role, then to the requester — a nudge with no
     * recipient would leave the request exactly as stuck as it already is.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function chaseTargets(ProductRequest $request)
    {
        $guide = $request->currentGuide();

        if ($guide['owner'] && $guide['owner']->is_active) {
            return collect([$guide['owner']]);
        }

        if ($guide['role_key']) {
            $team = User::where('is_active', true)->where('pcr_role', $guide['role_key'])->get();

            if ($team->isNotEmpty()) {
                return $team;
            }
        }

        return collect([$request->user])->filter(fn ($u) => $u?->is_active);
    }
}
