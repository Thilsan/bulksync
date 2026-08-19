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

        $mail = (new MailMessage)
            ->subject("{$this->reference} — {$waiting} SKU(s) need mapping in Cegid for {$this->website}")
            ->greeting("Hello {$notifiable->name},")
            ->line("**{$this->brand}** on **{$this->website}**: {$this->mapped} of {$this->total} SKUs are mapped and live.")
            ->line("**{$waiting}** still need mapping in Cegid before they can go on the website.");

        foreach ($this->summaryRows($request) as $label => $value) {
            if (filled($value)) {
                $mail->line("**{$label}:** {$value}");
            }
        }

        // The list is attached rather than printed: at two SKUs an inline list is
        // friendlier, at two hundred it makes the mail unreadable, and the same
        // file works either way — and can be opened next to Cegid.
        $mail->attachData(
            $this->csv(),
            "{$this->reference}-needs-mapping.csv",
            ['mime' => 'text/csv'],
        );

        return $mail
            ->line('The attached CSV lists them. Once they are mapped they appear on the request on their own — the check runs hourly and there is nothing to re-submit.')
            ->action('Open the request', $this->requestUrl($this->requestId))
            ->line('The SKUs that are already mapped can be taken forward without waiting for these.');
    }

    public function toArray(object $notifiable): array
    {
        $request = $this->requestForEmail($this->requestId);

        return [
            'type'       => 'mapping_needed',
            'request_id' => $this->requestId,
            'reference'  => $this->reference,
            'title'      => "{$this->reference} — " . count($this->pending) . ' SKU(s) need mapping in Cegid',
            'body'       => "{$this->mapped} of {$this->total} mapped for {$this->website}.",
            'url'        => $this->requestUrl($this->requestId),
            'mine'       => $this->concernsRecipient($request, $notifiable),
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
