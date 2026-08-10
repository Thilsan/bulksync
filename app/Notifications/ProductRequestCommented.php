<?php

namespace App\Notifications;

use App\Models\ProductRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use App\Notifications\Concerns\BuildsRequestEmail;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A comment nobody is told about is a diary entry, not a conversation — and the
 * moment someone needs an actual answer they go back to email, which is exactly
 * what this module exists to replace.
 */
class ProductRequestCommented extends Notification implements ShouldQueue
{
    use BuildsRequestEmail, Queueable;

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
        $request = $this->requestForEmail($this->requestId);

        $subject = $this->mentioned
            ? "[{$this->reference}] {$this->actorName} mentioned you"
            : "[{$this->reference}] New comment from {$this->actorName}";

        return (new MailMessage)
            ->subject($subject)
            ->view('emails.product-request.comment', [
                'recipientName' => $notifiable->name,
                'requestName'   => $this->requestName,
                'actorName'     => $this->actorName,
                'mentioned'     => $this->mentioned,
                'body'          => $this->body,
                'rows'          => $this->summaryRows($request),
                'url'           => $this->requestUrl($this->requestId),
                'subject'       => $subject,
                'preheader'     => \Illuminate\Support\Str::limit($this->body, 90),
            ]);
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
