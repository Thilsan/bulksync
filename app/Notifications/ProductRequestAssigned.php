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
    ) {
        $this->onQueue('bulkupload');
    }

    public static function forRequest(
        ProductRequest $request,
        string $roleLabel,
        string $actorName,
        ?string $handedOverFrom = null,
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
        );
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

        $subject = $this->handedOverFrom
            ? "[{$this->reference}] {$this->roleLabel} handed over to you"
            : "[{$this->reference}] You are the {$this->roleLabel} — {$this->brand}";

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
                'mentionSubject'  => $subject,
                'preheader'       => $this->handedOverFrom
                    ? "{$this->actorName} handed this over to you from {$this->handedOverFrom}."
                    : "{$this->actorName} assigned you as {$this->roleLabel} on {$this->reference}.",
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind'         => 'assigned',
            'request_id'   => $this->requestId,
            'reference'    => $this->reference,
            'brand'        => $this->brand,
            'role'         => $this->roleLabel,
            'status_label' => $this->statusLabel,
            'actor'        => $this->actorName,
            'requester'    => $this->requesterName,
            'handed_over_from' => $this->handedOverFrom,
        ];
    }
}
