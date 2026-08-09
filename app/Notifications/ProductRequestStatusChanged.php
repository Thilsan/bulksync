<?php

namespace App\Notifications;

use App\Models\ProductRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProductRequestStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

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
        $label = ProductRequest::STATUS_LABELS[$this->toStatus] ?? $this->toStatus;

        $mail = (new MailMessage)
            ->subject("[{$this->reference}] {$this->brand} — {$label}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Product creation request **{$this->reference}** ({$this->brand}) is now **{$label}**.")
            ->line("Updated by: {$this->actorName}");

        if ($this->remarks) {
            $mail->line("Remarks: {$this->remarks}");
        }

        return $mail
            ->action('Open request', route('product-requests.show', $this->requestId))
            ->line('You are receiving this because your team is involved in this stage.');
    }

    public function toArray(object $notifiable): array
    {
        return [
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
