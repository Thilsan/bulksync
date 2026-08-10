<?php

namespace App\Notifications;

use App\Models\ProductRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use App\Notifications\Concerns\BuildsRequestEmail;
use Illuminate\Notifications\Notification;

class ProductRequestStatusChanged extends Notification implements ShouldQueue
{
    use BuildsRequestEmail, Queueable;

    public function __construct(
        public readonly int     $requestId,
        public readonly string  $reference,
        public readonly string  $brand,
        public readonly ?string $fromStatus,
        public readonly string  $toStatus,
        public readonly string  $actorName,
        public readonly ?string $remarks = null,
    ) {
        $this->onQueue('bulkupload');
    }

    public static function forRequest(ProductRequest $request, ?string $fromStatus, string $actorName, ?string $remarks = null): self
    {
        return new self(
            requestId:  $request->id,
            reference:  $request->reference,
            brand:      $request->brand,
            fromStatus: $fromStatus,
            toStatus:   $request->status,
            actorName:  $actorName,
            remarks:    $remarks,
        );
    }

    public function via(object $notifiable): array
    {
        // In-app always; email only when a mailer is actually configured, so a
        // "log" mailer in dev doesn't pretend the team was told.
        return config('mail.default') === 'log'
            ? ['database']
            : ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->requestForEmail($this->requestId);
        $label   = ProductRequest::STATUS_LABELS[$this->toStatus] ?? $this->toStatus;
        $name    = $request?->displayName() ?? $this->brand;

        $guide   = $request?->currentGuide();
        $owner   = $guide['owner'] ?? null;
        $isMine  = $owner && (int) $owner->id === (int) $notifiable->id;

        // Only spell out a deadline for the person who actually owns the stage.
        $brief   = $isMine && $guide['field'] ? $request?->assignmentFor($guide['field']) : null;
        $dueText = $brief?->due_date
            ? 'Finish by ' . $brief->due_date->format('D d M Y') . ' — ' . strtolower($brief->dueLabel())
            : null;

        return (new MailMessage)
            ->subject("[{$this->reference}] {$name} — {$label}")
            ->view('emails.product-request.status', [
                'recipientName' => $notifiable->name,
                'requestName'   => $name,
                'statusLabel'   => $label,
                'stageGuide'    => $this->stageGuide($request, $this->toStatus),
                'isMine'        => $isMine,
                // A finished request has no next step and nobody to wait on.
                'isClosed'      => $request?->isClosed() ?? in_array($this->toStatus, ProductRequest::CLOSED_STATUSES, true),
                'isCancelled'   => $this->toStatus === ProductRequest::CANCELLED,
                'ownerText'     => $owner?->name ?? (($guide['role'] ?? null) ?: 'the team'),
                'dueText'       => $dueText,
                'remarks'       => $this->remarks,
                'actorName'     => $this->actorName,
                'rows'          => $this->summaryRows($request),
                'url'           => $this->requestUrl($this->requestId),
                'subject'       => "{$name} — {$label}",
                'preheader'     => "{$name} is now {$label}.",
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind'        => 'status',
            'request_id'  => $this->requestId,
            'reference'   => $this->reference,
            'brand'       => $this->brand,
            'from_status' => $this->fromStatus,
            'to_status'   => $this->toStatus,
            'status_label'=> ProductRequest::STATUS_LABELS[$this->toStatus] ?? $this->toStatus,
            'actor'       => $this->actorName,
            'remarks'     => $this->remarks,
        ];
    }
}
