<?php

namespace App\Console\Commands;

use App\Services\PhotoroomService;
use App\Support\PhotoroomAllowance;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Reconstruct how much of Photoroom's rolling 24-hour allowance is spent.
 *
 * Nothing records a Photoroom request as it happens — the service logs only
 * failures — so the count has to be rebuilt from the item rows afterwards.
 * That makes this a floor rather than a bill: it can see every request that
 * left a row behind, and cannot see one whose row was since deleted.
 *
 * The window is rolling, not a calendar day. Photoroom's throttle ages each
 * request out 24 hours after it was made, so the allowance returns in the
 * same rhythm it was spent, and "when does the next slot free" is a more
 * useful answer than "how many are left".
 */
class PhotoroomUsage extends Command
{
    protected $signature = 'photoroom:usage
                            {--hours= : Report on this many trailing hours instead of the natural window}';

    protected $description = 'Report Photoroom API requests against the plan allowance';

    public function handle(PhotoroomService $photoroom, PhotoroomAllowance $allowance): int
    {
        $hours = $this->option('hours') !== null ? max(1, (int) $this->option('hours')) : null;

        $r     = $allowance->report($hours);
        $hours = $r['window_hours'];

        $this->newLine();
        $this->line("Photoroom usage, last {$hours}h (since {$r['since']->format('D d M H:i')})");
        $this->line(str_repeat('─', 56));
        $this->line(sprintf('  %-34s %d', 'Edits that succeeded', $r['succeeded']));
        $this->line(sprintf('  %-34s %d', 'Extra mannequin-removal requests', $r['extra_calls']));
        $this->line(sprintf('  %-34s %d', 'Failures that still cost a request', $r['charged_failures']));
        $this->line(sprintf('  %-34s %d', 'Throttled (free, not counted)', $r['free_refusals']));
        $this->line(str_repeat('─', 56));
        $this->line(sprintf('  %-34s %d', 'Requests spent', $r['spent']));

        if ($r['is_sandbox']) {
            $this->line(sprintf('  %-34s %d of %d', 'Sandbox allowance', $r['left'], $r['quota']));

            if ($r['left'] <= 0) {
                $this->newLine();
                $this->warn('  Allowance is spent. Capacity returns as each request ages out below.');
            }

            $this->reportRefill($r['succeeded_items'], $hours);
        } else {
            $this->line(sprintf('  %-34s %d of %d', 'Monthly allowance', $r['left'], $r['quota']));
            $this->line(sprintf('  %-34s %s', 'Resets', $r['resets_on']->format('D d M Y')));

            if ($r['left'] <= 0) {
                $this->newLine();
                $this->warn('  Monthly allowance is spent. It does not trickle back — it resets on the date above.');
            }
        }

        $this->newLine();
        $this->comment('  Counted from item rows, not from Photoroom. Deleted sessions are invisible to it,');
        $this->comment('  so treat the total as a floor.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * When the spent requests age back out, hour by hour. More actionable than
     * a single number: it says when a batch of a given size can start, rather
     * than only whether one can start right now.
     */
    private function reportRefill($succeeded, int $hours): void
    {
        if ($succeeded->isEmpty()) {
            return;
        }

        $byHour = $succeeded
            ->groupBy(fn ($i) => Carbon::parse($i->updated_at)->format('Y-m-d H'))
            ->map->count()
            ->sortKeys();

        $this->newLine();
        $this->line('  Frees up at        Requests');

        $running = 0;

        foreach ($byHour as $hour => $count) {
            $freesAt = Carbon::createFromFormat('Y-m-d H', $hour)->addHours($hours);
            $running += $count;

            $this->line(sprintf(
                '  %-18s %-4d (%d back by then)',
                $freesAt->format('D H:i'),
                $count,
                $running,
            ));
        }
    }
}
