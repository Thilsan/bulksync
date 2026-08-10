<?php

namespace App\Notifications;

use App\Models\ProductRequest;
use App\Notifications\Concerns\BuildsRequestEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the person a task has been taken off.
 *
 * Only the new owner used to hear about a handover, so whoever had the task
 * carried on believing it was still theirs — two people working the same stage,
 * or nobody, depending on who noticed.
 */
class ProductRequestHandedOff extends Notification implements ShouldQueue
{
    use BuildsRequestEmail, Queueable;

    public function __construct(
        public readonly int    $requestId,
        public readonly string $reference,
        public readonly string $requestName,
        public readonly string $roleLabel,
        public readonly string $newOwnerName,
        public readonly string $actorName,
    ) {
        $this->onQueue('bulkupload');
    }

    public static function forRequest(ProductRequest $request, string $roleLabel, string $newOwnerName, string $actorName): self
    {
        return new self(
            requestId:    $request->id,
            reference:    $request->reference,
            requestName:  $request->displayName(),
            roleLabel:    $roleLabel,
            newOwnerName: $newOwnerName,
            actorName:    $actorName,
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
        $subject = "[{$this->reference}] {$this->roleLabel} moved to {$this->newOwnerName}";

        return (new MailMessage)
            ->subject($subject)
            ->view('emails.product-request.handed-off', [
                'recipientName' => $notifiable->name,
                'requestName'   => $this->requestName,
                'roleLabel'     => $this->roleLabel,
                'newOwnerName'  => $this->newOwnerName,
                'actorName'     => $this->actorName,
                'rows'          => $this->summaryRows($request),
                'url'           => $this->requestUrl($this->requestId),
                'subject'       => $subject,
                'preheader'     => "{$this->newOwnerName} has taken over as {$this->roleLabel}.",
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind'         => 'handed_off',
            'request_id'   => $this->requestId,
            'reference'    => $this->reference,
            'brand'        => $this->requestName,
            'role'         => $this->roleLabel,
            'status_label' => "handed over to {$this->newOwnerName}",
            'actor'        => $this->actorName,
        ];
    }
}
