<?php

namespace App\Notifications;

use App\Models\ProductRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A request stalling matters more than most status changes — the launch date
 * doesn't move just because the samples never arrived, so everyone involved
 * needs telling straight away.
 */
class ProductRequestHoldChanged extends Notification implements ShouldQueue
{
    use Queueable;

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
        $mail = (new MailMessage)
            ->subject("[{$this->reference}] " . ($this->onHold ? 'Put on hold' : 'Back in progress'))
            ->greeting("Hello {$notifiable->name},");

        if ($this->onHold) {
            $mail->line("**{$this->reference}** ({$this->brand}) has been put on hold at **{$this->stageLabel}**.")
                 ->line("Reason: {$this->reason}");
        } else {
            $mail->line("**{$this->reference}** ({$this->brand}) is off hold and back in progress at **{$this->stageLabel}**.");
        }

        return $mail
            ->line("Updated by: {$this->actorName}")
            ->action('Open request', route('product-requests.show', $this->requestId));
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
