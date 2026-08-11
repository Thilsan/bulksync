<?php

namespace App\Notifications;

use App\Models\ProductRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use App\Notifications\Concerns\BuildsRequestEmail;
use Illuminate\Notifications\Notification;

/**
 * Tells someone a request has landed on their desk. Status-change notices go to
 * whole teams; this one is personal — it is the "you specifically have work"
 * signal the top-bar bell is built around.
 */
class ProductRequestAssigned extends Notification implements ShouldQueue
{
    use BuildsRequestEmail, Queueable;

    public function __construct(
        public readonly int    $requestId,
        public readonly string $reference,
        public readonly string $brand,
        public readonly string $roleLabel,
        public readonly string $roleField,
        public readonly string $statusLabel,
        public readonly string $actorName,
        public readonly string $requesterName = 'Unknown',
        public readonly ?string $handedOverFrom = null,
        /** Set when this copy goes to someone kept informed, not the assignee. */
        public readonly ?string $assigneeName = null,
    ) {
        $this->onQueue('bulkupload');
    }

    public static function forRequest(
        ProductRequest $request,
        string $roleLabel,
        string $actorName,
        ?string $handedOverFrom = null,
        ?string $assigneeName = null,
    ): self {
        return new self(
            requestId:   $request->id,
            reference:   $request->reference,
            brand:       $request->brand,
            roleLabel:   $roleLabel,
            roleField:   (string) array_search($roleLabel, ProductRequest::ASSIGNMENT_ROLES, true),
            statusLabel:   $request->statusLabel(),
            actorName:     $actorName,
            requesterName: $request->user?->name ?? 'Unknown',
            handedOverFrom: $handedOverFrom,
            assigneeName:   $assigneeName,
        );
    }

    /**
     * The same news, for someone who is only being kept informed.
     *
     * Telling a watching account "You are the Photo Editor" would be a plain
     * lie, so the copy names whoever actually got the job.
     */
    public static function asCopy(
        ProductRequest $request,
        string $roleLabel,
        string $actorName,
        string $assigneeName,
        ?string $handedOverFrom = null,
    ): self {
        return self::forRequest($request, $roleLabel, $actorName, $handedOverFrom, $assigneeName);
    }

    /** True when this is an information copy rather than "you have work". */
    public function isCopy(): bool
    {
        return $this->assigneeName !== null;
    }

    public function via(object $notifiable): array
    {
        return config('mail.default') === 'log'
            ? ['database']
            : ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->requestForEmail($this->requestId);
        $brief    = $request?->assignmentFor($this->roleField);

        $subject = match (true) {
            $this->isCopy() && $this->handedOverFrom !== null
                => "[{$this->reference}] {$this->roleLabel} handed to {$this->assigneeName} — {$this->brand}",
            $this->isCopy()      => "[{$this->reference}] {$this->assigneeName} is the {$this->roleLabel} — {$this->brand}",
            $this->handedOverFrom !== null => "[{$this->reference}] {$this->roleLabel} handed over to you",
            default                        => "[{$this->reference}] You are the {$this->roleLabel} — {$this->brand}",
        };

        // The task box: their own brief and deadline if the requester set one,
        // otherwise the role itself so the box is never empty.
        $task = '<div style="font-weight:700; font-size:15px; margin-bottom:2px;">'
              . e($brief?->taskTitle() ?? $this->roleLabel) . '</div>';

        $tone = 'brand';

        if ($brief?->due_date) {
            $days = $brief->daysLeft();
            $when = match (true) {
                $days < 0   => 'was due ' . abs($days) . ' ' . (abs($days) === 1 ? 'day' : 'days') . ' ago',
                $days === 0 => 'due today',
                $days === 1 => 'due tomorrow',
                default     => "due in {$days} days",
            };

            $tone = $days < 0 ? 'red' : ($days <= 2 ? 'amber' : 'brand');
            $task .= '<div style="font-size:13px;">Finish by <strong>'
                   . $brief->due_date->format('D d M Y') . '</strong> — ' . $when . '</div>';
        } else {
            $task .= '<div style="font-size:13px;">No personal deadline set — the online launch date applies.</div>';
        }

        return (new MailMessage)
            ->subject($subject)
            ->view('emails.product-request.assigned', [
                'recipientName'   => $notifiable->name,
                'actorName'       => $this->actorName,
                'roleLabel'       => $this->roleLabel,
                'taskHtml'        => $task,
                'dueTone'         => $tone,
                'stageGuide'      => $this->stageGuide($request),
                'rows'            => $this->summaryRows($request),
                'url'             => $this->requestUrl($this->requestId),
                'handedOverFrom'  => $this->handedOverFrom,
                'assigneeName'    => $this->assigneeName,
                'mentionSubject'  => $subject,
                'preheader'       => match (true) {
                    $this->isCopy()       => "{$this->actorName} made {$this->assigneeName} the {$this->roleLabel} on {$this->reference}.",
                    (bool) $this->handedOverFrom => "{$this->actorName} handed this over to you from {$this->handedOverFrom}.",
                    default               => "{$this->actorName} assigned you as {$this->roleLabel} on {$this->reference}.",
                },
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind'         => 'assigned',
            // A copy is news about somebody else's task, not a task.
            'for_me'       => !$this->isCopy(),
            'request_id'   => $this->requestId,
            'reference'    => $this->reference,
            'brand'        => $this->brand,
            'role'         => $this->roleLabel,
            'status_label' => $this->statusLabel,
            'actor'        => $this->actorName,
            'requester'    => $this->requesterName,
            'handed_over_from' => $this->handedOverFrom,
            'assignee'     => $this->assigneeName,
        ];
    }
}
