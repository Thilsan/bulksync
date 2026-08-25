<?php

namespace App\Notifications;

use App\Models\ProductRequest;
use App\Notifications\Concerns\BuildsRequestEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * More of the balance has been mapped.
 *
 * A request that carried on with part of its SKUs has work waiting the moment
 * the rest resolve — and nobody was watching for that. Without this the balance
 * is only ever noticed by somebody re-opening the request on a hunch.
 */
class ProductRequestBalanceMapped extends Notification implements ShouldQueue
{
    use BuildsRequestEmail, Queueable;

    public function __construct(
        public readonly int    $requestId,
        public readonly string $reference,
        public readonly string $brand,
        public readonly int    $justMapped,
        public readonly int    $mapped,
        public readonly int    $total,
        public readonly int    $remaining,
        public readonly string $stageLabel,
    ) {
        $this->onQueue('bulkupload');
    }

    public static function forRequest(ProductRequest $request, int $justMapped): self
    {
        return new self(
            requestId:  $request->id,
            reference:  $request->reference,
            brand:      $request->brand,
            justMapped: $justMapped,
            mapped:     (int) $request->mapped_skus,
            total:      (int) $request->total_skus,
            remaining:  $request->unmappedSkus(),
            stageLabel: $request->statusLabel(),
        );
    }

    public function via(object $notifiable): array
    {
        return config('mail.default') === 'log'
            ? ['database']
            : ['database', 'mail'];
    }

    /** True when nothing is outstanding any more — the whole request can finish. */
    public function isComplete(): bool
    {
        return $this->remaining === 0;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->requestForEmail($this->requestId);
        $name    = $request?->displayName() ?? $this->brand;

        $subject = $this->isComplete()
            ? "[{$this->reference}] All {$this->total} SKUs are mapped — ready to finish"
            : "[{$this->reference}] {$this->justMapped} more SKUs mapped — {$this->mapped} of {$this->total}";

        return (new MailMessage)
            ->subject($subject)
            ->view('emails.product-request.balance', [
                'recipientName' => $notifiable->name,
                'requestName'   => $name,
                'justMapped'    => $this->justMapped,
                'mapped'        => $this->mapped,
                'total'         => $this->total,
                'remaining'     => $this->remaining,
                'percent'       => $this->total > 0 ? (int) round($this->mapped / $this->total * 100) : 0,
                'complete'      => $this->isComplete(),
                'statusLabel'   => $this->stageLabel,
                'rows'          => $this->summaryRows($request),
                'url'           => $this->requestUrl($this->requestId),
                'subject'       => $subject,
                'preheader'     => $this->isComplete()
                    ? "Every SKU on {$name} is mapped — finish the remaining products and mark it complete."
                    : "{$this->justMapped} more mapped, {$this->remaining} still to map.",
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'kind'         => 'balance_mapped',
            // The balance is the holder's to finish, so it rings for them.
            'for_me'       => $this->concernsRecipient($this->requestForEmail($this->requestId), $notifiable),
            'request_id'   => $this->requestId,
            'reference'    => $this->reference,
            'brand'        => $this->brand,
            'status_label' => $this->isComplete()
                ? "All {$this->total} SKUs mapped"
                : "{$this->mapped} of {$this->total} SKUs mapped",
            'actor'        => 'SKU check',
        ];
    }
}
