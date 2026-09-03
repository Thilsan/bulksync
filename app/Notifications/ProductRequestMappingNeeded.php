<?php

namespace App\Notifications;

use App\Models\ProductRequest;
use App\Notifications\Concerns\BuildsRequestEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Asks the brand manager to map the SKUs a website is still missing.
 *
 * On a Cegid website the mapping is the brand manager's own job, not a queue
 * somebody else works through — so telling them "this request is waiting" is not
 * enough. This carries the count that is already live and the list that is not,
 * as a CSV they can work straight from in Cegid.
 *
 * Only ever sent for Cegid websites: everywhere else there is no mapping step
 * and an unmatched SKU simply means the product has not been created yet.
 */
class ProductRequestMappingNeeded extends Notification implements ShouldQueue
{
    use BuildsRequestEmail, Queueable;

    /**
     * @param  array<int, array{sku: string, status: string, note: string|null}>  $pending
     */
    public function __construct(
        public readonly int    $requestId,
        public readonly string $reference,
        public readonly string $brand,
        public readonly string $website,
        public readonly int    $mapped,
        public readonly int    $total,
        public readonly array  $pending,
    ) {
        $this->onQueue('bulkupload');
    }

    public static function forRequest(ProductRequest $request): self
    {
        $pending = $request->skus()
            ->whereIn('mapping_status', [ProductRequest::MAP_PENDING, ProductRequest::MAP_NOT_MAPPED])
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => [
                'sku'    => $row->sku,
                'status' => $row->label(),
                'note'   => $row->mapping_note,
            ])
            ->all();

        return new self(
            requestId: $request->id,
            reference: $request->reference,
            brand:     $request->brand,
            website:   $request->store?->name ?? 'this website',
            mapped:    (int) $request->mapped_skus,
            total:     (int) $request->total_skus,
            pending:   $pending,
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
        $waiting = count($this->pending);
        $subject = "[{$this->reference}] {$waiting} " . ($waiting === 1 ? 'SKU needs' : 'SKUs need')
                 . " mapping in Cegid for {$this->website}";

        $mail = (new MailMessage)
            ->subject($subject)
            ->view('emails.product-request.mapping-needed', [
                'recipientName' => $notifiable->name,
                'brand'         => $this->brand,
                'website'       => $this->website,
                'mapped'        => $this->mapped,
                'total'         => $this->total,
                'waiting'       => $waiting,
                'percent'       => $this->total > 0 ? (int) round($this->mapped / $this->total * 100) : 0,
                'rows'          => $this->summaryRows($request),
                'url'           => $this->requestUrl($this->requestId),
                'subject'       => $subject,
                'preheader'     => "{$this->mapped} of {$this->total} mapped — {$waiting} still need mapping in Cegid.",
            ]);

        // The list is attached rather than printed: at two SKUs an inline list is
        // friendlier, at two hundred it makes the mail unreadable, and the same
        // file works either way — and can be opened next to Cegid.
        return $mail->attachData(
            $this->csv(),
            "{$this->reference}-needs-mapping.csv",
            ['mime' => 'text/csv'],
        );
    }

    public function toArray(object $notifiable): array
    {
        $waiting = count($this->pending);

        return [
            'kind'         => 'mapping_needed',
            // Sent to one person because the mapping is theirs to do, so it always
            // rings — the recipient can be a brand manager found by category who
            // holds no assignment on the request and did not raise it.
            'for_me'       => true,
            'request_id'   => $this->requestId,
            'reference'    => $this->reference,
            'brand'        => $this->brand,
            'status_label' => "{$waiting} " . ($waiting === 1 ? 'SKU needs' : 'SKUs need') . ' mapping in Cegid',
            'remarks'      => "{$this->mapped} of {$this->total} mapped for {$this->website}.",
            'actor'        => 'SKU check',
        ];
    }

    private function csv(): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['SKU', 'Status', 'Note']);

        foreach ($this->pending as $row) {
            fputcsv($handle, [$row['sku'], $row['status'], $row['note']]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
