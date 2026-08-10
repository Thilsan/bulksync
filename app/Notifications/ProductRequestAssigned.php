<?php

namespace App\Notifications;

use App\Models\ProductRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells someone a request has landed on their desk. Status-change notices go to
 * whole teams; this one is personal — it is the "you specifically have work"
 * signal the top-bar bell is built around.
 */
class ProductRequestAssigned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int    $requestId,
        public readonly string $reference,
        public readonly string $brand,
        public readonly string $roleLabel,
        public readonly string $statusLabel,
        public readonly string $actorName,
        public readonly string $requesterName = 'Unknown',
    ) {
        $this->onQueue('bulkupload');
    }

    public static function forRequest(ProductRequest $request, string $roleLabel, string $actorName): self
    {
        return new self(
            requestId:   $request->id,
            reference:   $request->reference,
            brand:       $request->brand,
            roleLabel:   $roleLabel,
            statusLabel:   $request->statusLabel(),
            actorName:     $actorName,
            requesterName: $request->user?->name ?? 'Unknown',
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
        return (new MailMessage)
            ->subject("[{$this->reference}] Assigned to you — {$this->roleLabel}")
            ->greeting("Hello {$notifiable->name},")
            ->line("**{$this->actorName}** assigned you to product creation request **{$this->reference}** ({$this->brand}) as **{$this->roleLabel}**.")
            ->line("Current stage: {$this->statusLabel}")
            ->line("Raised by: {$this->requesterName}")
            ->action('Open request', route('product-requests.show', $this->requestId))
            ->line('You can see everything assigned to you under Assigned to Me.');
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
        ];
    }
}
