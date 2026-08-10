<?php

namespace App\Notifications;

use App\Models\ProductRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A comment nobody is told about is a diary entry, not a conversation — and the
 * moment someone needs an actual answer they go back to email, which is exactly
 * what this module exists to replace.
 */
class ProductRequestCommented extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int    $requestId,
        public readonly string $reference,
        public readonly string $requestName,
        public readonly string $body,
        public readonly string $actorName,
        public readonly bool   $mentioned = false,
    ) {
        $this->onQueue('bulkupload');
    }

    public static function forRequest(ProductRequest $request, string $body, string $actorName, bool $mentioned = false): self
    {
        return new self(
            requestId:   $request->id,
            reference:   $request->reference,
            requestName: $request->displayName(),
            body:        $body,
            actorName:   $actorName,
            mentioned:   $mentioned,
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
        $subject = $this->mentioned
            ? "[{$this->reference}] {$this->actorName} mentioned you"
            : "[{$this->reference}] New comment from {$this->actorName}";

        return (new MailMessage)
            ->subject($subject)
            ->greeting("Hello {$notifiable->name},")
            ->line($this->mentioned
                ? "**{$this->actorName}** mentioned you on **{$this->requestName}**:"
                : "**{$this->actorName}** commented on **{$this->requestName}**:")
            ->line('"' . $this->body . '"')
            ->action('Reply on the request', route('product-requests.show', $this->requestId));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind'         => 'comment',
            'request_id'   => $this->requestId,
            'reference'    => $this->reference,
            'brand'        => $this->requestName,
            'status_label' => $this->mentioned ? 'mentioned you in a comment' : 'commented',
            'remarks'      => Str::limit($this->body, 120),
            'actor'        => $this->actorName,
        ];
    }
}
