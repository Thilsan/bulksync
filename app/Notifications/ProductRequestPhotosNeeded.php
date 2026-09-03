<?php

namespace App\Notifications;

use App\Models\ProductRequest;
use App\Notifications\Concerns\BuildsRequestEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Asks the brand manager for the products, because a shoot has been agreed.
 *
 * The studio cannot start until the samples are in the building, and the brand
 * manager is the one who can get them there. Sent when somebody answers yes to
 * "does this need a photoshoot?" — so the ask goes out at the moment the decision
 * is made rather than whenever the coordinator next opens the room.
 */
class ProductRequestPhotosNeeded extends Notification implements ShouldQueue
{
    use BuildsRequestEmail, Queueable;

    public function __construct(
        public readonly int    $requestId,
        public readonly string $reference,
        public readonly string $brand,
        public readonly string $website,
        public readonly int    $skus,
        public readonly string $askedBy,
    ) {
        $this->onQueue('bulkupload');
    }

    public static function forRequest(ProductRequest $request, string $askedBy): self
    {
        return new self(
            requestId: $request->id,
            reference: $request->reference,
            brand:     $request->brand,
            website:   $request->store?->name ?? 'this website',
            skus:      (int) $request->total_skus,
            askedBy:   $askedBy,
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
        $subject = "[{$this->reference}] Photos needed for {$this->brand}";

        return (new MailMessage)
            ->subject($subject)
            ->view('emails.product-request.photos-needed', [
                'recipientName' => $notifiable->name,
                'brand'         => $this->brand,
                'website'       => $this->website,
                'skus'          => $this->skus,
                'askedBy'       => $this->askedBy,
                'rows'          => $this->summaryRows($request),
                'url'           => $this->requestUrl($this->requestId),
                'subject'       => $subject,
                'preheader'     => "{$this->brand} is going to a photoshoot — samples for {$this->skus} SKU(s) are needed.",
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind'         => 'photos_needed',
            // Sent to one person because the samples are theirs to arrange, so it
            // always rings — the recipient can be a brand manager found by category
            // who holds no assignment on the request and did not raise it.
            'for_me'       => true,
            'request_id'   => $this->requestId,
            'reference'    => $this->reference,
            'brand'        => $this->brand,
            'status_label' => "needs photos for {$this->skus} " . ($this->skus === 1 ? 'SKU' : 'SKUs'),
            'remarks'      => "Samples needed for the photoshoot on {$this->website}.",
            'actor'        => $this->askedBy,
        ];
    }
}
