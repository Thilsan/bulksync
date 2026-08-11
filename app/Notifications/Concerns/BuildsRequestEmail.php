<?php

namespace App\Notifications\Concerns;

use App\Models\ProductRequest;

/**
 * Shared email data for Product Creation Request notifications.
 *
 * Notifications are queued, so the payload is deliberately scalar. The email
 * body wants far more than that, so it reloads the request at send time — which
 * also means the message reflects the request as it is when it goes out, not as
 * it was when the job was queued.
 */
trait BuildsRequestEmail
{
    protected function requestForEmail(int $id): ?ProductRequest
    {
        return ProductRequest::with(['store', 'user', 'assignments'])->find($id);
    }

    /** The facts table shown in every email. */
    protected function summaryRows(?ProductRequest $request): array
    {
        if (!$request) {
            return [];
        }

        $launch = $request->online_launch_date;
        $days   = $request->daysToOnlineLaunch();

        $launchText = $launch
            ? $launch->format('d M Y, H:i') . match (true) {
                $days === null => '',
                $days < 0      => '  (' . abs($days) . ' days overdue)',
                $days === 0    => '  (today)',
                default        => "  (in {$days} days)",
            }
            : 'Not set';

        return [
            'Request'       => $request->displayName(),
            'Reference'     => $request->reference,
            'Website'       => $request->store?->name,
            'Brand'         => trim($request->brand . ' / ' . $request->category, ' /'),
            'SKUs'          => number_format($request->total_skus),
            'Current stage' => $request->statusLabel(),
            'Priority'      => $request->priorityLabel(),
            'Launch'        => $launchText,
            'Requested by'  => $request->user?->name,
        ];
    }

    protected function requestUrl(int $id): string
    {
        return route('product-requests.show', $id);
    }

    /**
     * Is this message about the reader's own work?
     *
     * Everyone on a stage's team is told when a request moves, and the watching
     * accounts hear about all of them — useful, but it buried the handful of
     * messages that actually name you. The flag lands on each database row (this
     * is computed per recipient), and the bell only rings for the ones that are
     * yours: a role you currently hold, or a request you raised.
     */
    protected function concernsRecipient(?ProductRequest $request, object $notifiable): bool
    {
        if (!$request || empty($notifiable->id)) {
            return false;
        }

        if ((int) $request->user_id === (int) $notifiable->id) {
            return true;
        }

        return $request->assignments
            ->whereNull('ended_at')
            ->where('user_id', $notifiable->id)
            ->isNotEmpty();
    }

    /** Plain-English instruction for a stage, from the model's single source. */
    protected function stageGuide(?ProductRequest $request, ?string $stage = null): string
    {
        if (!$request) {
            return 'Open the request to see what is needed.';
        }

        return $request->guideFor($stage ?? $request->status)['what']
            ?? 'Open the request to see what is needed.';
    }
}
