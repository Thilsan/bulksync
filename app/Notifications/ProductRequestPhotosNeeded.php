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

        $mail = (new MailMessage)
            ->subject("{$this->reference} — photos needed for {$this->brand}")
            ->greeting("Hello {$notifiable->name},")
            ->line("**{$this->brand}** on **{$this->website}** is going to a photoshoot, so the products are needed.")
            ->line("Please arrange the samples for the {$this->skus} SKU(s) on this request, or send the images if the brand has already supplied them.");

        foreach ($this->summaryRows($request) as $label => $value) {
            if (filled($value)) {
                $mail->line("**{$label}:** {$value}");
            }
        }

        return $mail
            ->line("Asked by {$this->askedBy}.")
            ->action('Open the request', $this->requestUrl($this->requestId))
            ->line('The shoot is booked from the Photoshoot Schedule once the samples arrive.');
    }

    public function toArray(object $notifiable): array
    {
        $request = $this->requestForEmail($this->requestId);

        return [
            'type'       => 'photos_needed',
            'request_id' => $this->requestId,
            'reference'  => $this->reference,
            'title'      => "{$this->reference} — photos needed for {$this->brand}",
            'body'       => "{$this->skus} SKU(s) on {$this->website}, asked by {$this->askedBy}.",
            'url'        => $this->requestUrl($this->requestId),
            'mine'       => $this->concernsRecipient($request, $notifiable),
        ];
    }
}
