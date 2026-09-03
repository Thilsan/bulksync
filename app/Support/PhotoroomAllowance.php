<?php

namespace App\Support;

use App\Models\PhotoEditItem;
use App\Services\PhotoroomService;
use Illuminate\Support\Carbon;

/**
 * How much of Photoroom's allowance has been spent, and how much is left.
 *
 * Nothing records a Photoroom request as it happens — the service logs only
 * failures — so the count is rebuilt from the item rows afterwards. That makes
 * it a floor rather than a bill: it sees every request that left a row behind,
 * and cannot see one whose session has since been deleted.
 *
 * This was the `photoroom:usage` command's private working. It moved here when
 * the same three numbers were wanted on screen, because two implementations of
 * "how many requests did that cost" would eventually disagree — and the subtle
 * half is which failures were charged for, not the arithmetic.
 */
class PhotoroomAllowance
{
    /** A sandbox key edits 100 images a day; a live key is billed monthly. */
    public const SANDBOX_DAILY_CAP = 100;

    /** Statuses that can only be reached by Photoroom having answered. */
    private const SUCCEEDED = ['edited', 'pushing', 'pushed'];

    public function __construct(private readonly PhotoroomService $photoroom) {}

    /**
     * @return array{
     *     window_hours: int, since: Carbon, is_sandbox: bool,
     *     succeeded: int, extra_calls: int, charged_failures: int, free_refusals: int,
     *     spent: int, quota: int, left: int, percent_used: int, resets_on: ?Carbon,
     *     succeeded_items: \Illuminate\Support\Collection,
     * }
     */
    public function report(?int $hours = null): array
    {
        // The window that matters differs by key. A sandbox key ages each
        // request out 24 hours after it was made; a live key is billed against
        // an allowance that resets on the plan's anniversary day.
        $sandbox = $this->photoroom->isSandbox();
        $hours   = $hours !== null
            ? max(1, $hours)
            : ($sandbox ? 24 : $this->hoursSinceReset());

        $since = now()->subHours($hours);

        $items = PhotoEditItem::where('updated_at', '>=', $since)
            ->get(['status', 'error_message', 'apparel_mode_applied', 'updated_at']);

        $succeeded = $items->whereIn('status', self::SUCCEEDED);

        // A throttled request is refused before Photoroom counts it, so those
        // are free. Every other failure still had to be uploaded to earn its
        // refusal, and was counted.
        $chargedFailures = $items->where('status', 'failed')
            ->reject(fn ($i) => $this->wasRefusedUncounted((string) $i->error_message));

        // Mannequin removal is its own request, spent before the edit itself.
        $extraCalls = $succeeded->where('apparel_mode_applied', 'mannequin_removed')->count();

        $spent = $succeeded->count() + $chargedFailures->count() + $extraCalls;
        $quota = $sandbox
            ? self::SANDBOX_DAILY_CAP
            : max(1, (int) config('services.photoroom.monthly_quota', 1000));

        return [
            'window_hours'     => $hours,
            'since'            => $since,
            'is_sandbox'       => $sandbox,
            'succeeded'        => $succeeded->count(),
            'extra_calls'      => $extraCalls,
            'charged_failures' => $chargedFailures->count(),
            'free_refusals'    => $items->count() - $succeeded->count() - $chargedFailures->count(),
            'spent'            => $spent,
            'quota'            => $quota,
            'left'             => max(0, $quota - $spent),
            'percent_used'     => (int) min(100, round($spent / $quota * 100)),
            'resets_on'        => $sandbox ? null : $this->nextReset(),
            'succeeded_items'  => $succeeded,
        ];
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

    /** Hours since the allowance last reset, for a monthly-billed key. */
    private function hoursSinceReset(): int
    {
        $day  = $this->resetDay();
        $last = now()->day >= $day
            ? now()->startOfDay()->setDay($day)
            : now()->startOfDay()->subMonthNoOverflow()->setDay($day);

        return max(1, (int) ceil($last->diffInMinutes(now()) / 60));
    }

    private function nextReset(): Carbon
    {
        $day = $this->resetDay();

        return now()->day >= $day
            ? now()->startOfDay()->addMonthNoOverflow()->setDay($day)
            : now()->startOfDay()->setDay($day);
    }

    private function resetDay(): int
    {
        return min(28, max(1, (int) config('services.photoroom.quota_resets_on', 1)));
    }
}
