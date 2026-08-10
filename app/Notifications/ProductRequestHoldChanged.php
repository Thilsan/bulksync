<?php

namespace App\Notifications;

use App\Models\ProductRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use App\Notifications\Concerns\BuildsRequestEmail;
use Illuminate\Notifications\Notification;

/**
 * A request stalling matters more than most status changes — the launch date
 * doesn't move just because the samples never arrived, so everyone involved
 * needs telling straight away.
 */
class ProductRequestHoldChanged extends Notification implements ShouldQueue
{
    use BuildsRequestEmail, Queueable;

    public function __construct(
        public readonly int     $requestId,
        public readonly string  $reference,
        public readonly string  $brand,
        public readonly bool    $onHold,
        public readonly ?string $reason,
        public readonly string  $stageLabel,
        public readonly string  $actorName,
    ) {
        $this->onQueue('bulkupload');
    }

    public static function forRequest(ProductRequest $request, string $actorName): self
    {
        return new self(
            requestId:  $request->id,
            reference:  $request->reference,
            brand:      $request->brand,
            onHold:     $request->isOnHold(),
            reason:     $request->hold_reason,
            stageLabel: $request->statusLabel(),
            actorName:  $actorName,
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
        $name    = $request?->displayName() ?? $this->brand;

        $subject = $this->onHold
            ? "[{$this->reference}] On hold — {$this->reason}"
            : "[{$this->reference}] Back in progress";

        return (new MailMessage)
            ->subject($subject)
            ->view('emails.product-request.hold', [
                'recipientName' => $notifiable->name,
                'requestName'   => $name,
                'onHold'        => $this->onHold,
                'reason'        => $this->reason,
                'statusLabel'   => $this->stageLabel,
                'stageGuide'    => $this->stageGuide($request),
                'actorName'     => $this->actorName,
                'rows'          => $this->summaryRows($request),
                'url'           => $this->requestUrl($this->requestId),
                'subject'       => $subject,
                'preheader'     => $this->onHold
                    ? "Blocked: {$this->reason}"
                    : "{$name} is moving again.",
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind'         => $this->onHold ? 'on_hold' : 'resumed',
            'request_id'   => $this->requestId,
            'reference'    => $this->reference,
            'brand'        => $this->brand,
            'reason'       => $this->reason,
            'status_label' => $this->onHold ? 'On Hold — ' . $this->reason : 'Back in progress',
            'actor'        => $this->actorName,
        ];
    }
}
