<?php

namespace App\Notifications;

use App\Models\ProductRequest;
use App\Models\User;
use App\Notifications\Concerns\BuildsRequestEmail;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells IT support which SKUs a Cegid website still cannot see.
 *
 * Sent by hand from the request page, after a check leaves SKUs unmapped: the
 * person checking writes the note, and IT gets it with the list attached. IT
 * is an address rather than a user, so this goes by mail only, and a reply
 * goes back to whoever sent it.
 */
class ProductRequestItSupportMapping extends Notification implements ShouldQueue
{
    use BuildsRequestEmail, Queueable;

    /** The Settings key holding IT support's address(es), set by a super admin. */
    public const SETTING = 'pcr_it_support_email';

    /**
     * The addresses in a list typed as "a@x.com, b@x.com" (commas, semicolons
     * or spaces), or null when any of them is not an email address.
     *
     * @return list<string>|null
     */
    public static function addresses(?string $list): ?array
    {
        $addresses = array_values(array_unique(array_filter(
            preg_split('/[\s,;]+/', (string) $list),
            fn ($a) => $a !== '',
        )));

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                return null;
            }
        }

        return $addresses ?: null;
    }

    /**
     * @param  array<int, array{sku: string, status: string, note: string|null}>  $unmapped
     */
    public function __construct(
        public readonly int    $requestId,
        public readonly string $reference,
        public readonly string $website,
        public readonly string $senderName,
        public readonly string $senderEmail,
        public readonly string $note,
        public readonly array  $unmapped,
    ) {
        $this->onQueue(Queues::PRODUCT_REQUESTS);
    }

    public static function forRequest(ProductRequest $request, User $sender, string $note): self
    {
        return new self(
            requestId:   $request->id,
            reference:   $request->reference,
            website:     $request->store?->name ?? 'this website',
            senderName:  $sender->name,
            senderEmail: $sender->email,
            note:        $note,
            unmapped:    self::unmappedOf($request),
        );
    }

    /** The SKUs still waiting on Cegid, in the order they were added. */
    public static function unmappedOf(ProductRequest $request): array
    {
        return $request->skus()
            ->whereIn('mapping_status', [ProductRequest::MAP_PENDING, ProductRequest::MAP_NOT_MAPPED])
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => [
                'sku'    => $row->sku,
                'status' => $row->label(),
                'note'   => $row->mapping_note,
            ])
            ->all();
    }

    public static function subjectFor(ProductRequest $request, int $count): string
    {
        return "[{$request->reference}] {$count} " . ($count === 1 ? 'SKU is' : 'SKUs are')
             . ' not mapped in Cegid for ' . ($request->store?->name ?? 'this website');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->requestForEmail($this->requestId);
        $count   = count($this->unmapped);
        $subject = $request
            ? self::subjectFor($request, $count)
            : "[{$this->reference}] {$count} SKU(s) not mapped in Cegid for {$this->website}";

        return (new MailMessage)
            ->subject($subject)
            ->replyTo($this->senderEmail, $this->senderName)
            ->view('emails.product-request.it-support', [
                'note'       => $this->note,
                'senderName' => $this->senderName,
                'website'    => $this->website,
                'count'      => $count,
                'skus'       => array_column($this->unmapped, 'sku'),
                'rows'       => $this->summaryRows($request),
                'url'        => $this->requestUrl($this->requestId),
                'subject'    => $subject,
                'preheader'  => "{$count} " . ($count === 1 ? 'SKU' : 'SKUs') . " not showing on {$this->website} — list attached.",
            ])
            ->attachData($this->csv(), "{$this->reference}-not-mapped.csv", ['mime' => 'text/csv']);
    }

    private function csv(): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['SKU', 'Status', 'Note']);

        foreach ($this->unmapped as $row) {
            fputcsv($handle, [$row['sku'], $row['status'], $row['note']]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
