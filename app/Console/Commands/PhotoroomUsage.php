<?php

namespace App\Console\Commands;

use App\Models\PhotoEditItem;
use App\Services\PhotoroomService;
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
                            {--hours=24 : Size of the rolling window to report on}';

    protected $description = 'Report Photoroom API requests made in the last 24 hours against the daily cap';

    /** A sandbox key edits 100 images a day; a live key has no daily cap. */
    private const SANDBOX_DAILY_CAP = 100;

    /** Statuses that can only be reached by Photoroom having answered. */
    private const SUCCEEDED = ['edited', 'pushing', 'pushed'];

    public function handle(PhotoroomService $photoroom): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $since = now()->subHours($hours);

        $items = PhotoEditItem::where('updated_at', '>=', $since)
            ->get(['status', 'error_message', 'apparel_mode_applied', 'updated_at']);

        $succeeded = $items->whereIn('status', self::SUCCEEDED);

        // A throttled request is refused before Photoroom counts it, so today's
        // 429s are free. Every other failure still had to be uploaded to earn
        // its refusal, and was counted.
        $chargedFailures = $items->where('status', 'failed')
            ->reject(fn ($i) => $this->wasRefusedUncounted((string) $i->error_message));

        // Mannequin removal is its own request, spent before the edit itself.
        $extraCalls = $succeeded->where('apparel_mode_applied', 'mannequin_removed')->count();

        $spent = $succeeded->count() + $chargedFailures->count() + $extraCalls;

        $this->newLine();
        $this->line("Photoroom usage, last {$hours}h (since {$since->format('D d M H:i')})");
        $this->line(str_repeat('─', 56));
        $this->line(sprintf('  %-34s %d', 'Edits that succeeded', $succeeded->count()));
        $this->line(sprintf('  %-34s %d', 'Extra mannequin-removal requests', $extraCalls));
        $this->line(sprintf('  %-34s %d', 'Failures that still cost a request', $chargedFailures->count()));
        $this->line(sprintf('  %-34s %d', 'Throttled (free, not counted)', $items->count() - $succeeded->count() - $chargedFailures->count()));
        $this->line(str_repeat('─', 56));
        $this->line(sprintf('  %-34s %d', 'Requests spent', $spent));

        if ($photoroom->isSandbox()) {
            $left = self::SANDBOX_DAILY_CAP - $spent;

            $this->line(sprintf('  %-34s %d of %d', 'Sandbox allowance', max(0, $left), self::SANDBOX_DAILY_CAP));

            if ($left <= 0) {
                $this->newLine();
                $this->warn('  Allowance is spent. Capacity returns as each request ages out below.');
            }
        } else {
            $this->line(sprintf('  %-34s %s', 'Live key', 'no daily cap — 60 requests/minute applies'));
        }

        $this->reportRefill($items->whereIn('status', self::SUCCEEDED), $hours);

        $this->newLine();
        $this->comment('  Counted from item rows, not from Photoroom. Deleted sessions are invisible to it,');
        $this->comment('  so treat the total as a floor.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Did this failure cost a request?
     *
     * Two different messages mean "Photoroom turned us away without counting
     * it": the raw 429 as it arrives, and the quota marker that then fails the
     * rest of the batch locally without so much as a connection. Matching only
     * the first silently bills every item the marker saved.
     */
    private function wasRefusedUncounted(string $error): bool
    {
        foreach (['throttl', 'quota is exhausted'] as $needle) {
            if (stripos($error, $needle) !== false) {
                return true;
            }
        }

        return false;
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
