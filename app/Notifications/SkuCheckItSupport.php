<?php

namespace App\Notifications;

use App\Models\SkuCheckSession;
use App\Models\User;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells IT support which SKUs a check found missing from a website.
 *
 * The SKU Checker's cousin of ProductRequestItSupportMapping: sent by hand
 * from a finished check, with the person's note and the not-mapped rows
 * attached as a CSV. Mail only, to an address rather than a user, and a reply
 * goes back to whoever sent it.
 */
class SkuCheckItSupport extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $skus
     */
    public function __construct(
        public readonly int    $sessionId,
        public readonly string $website,
        public readonly string $senderName,
        public readonly string $senderEmail,
        public readonly string $note,
        public readonly array  $skus,
    ) {
        $this->onQueue(Queues::PRODUCT_REQUESTS);
    }

    public static function forCheck(SkuCheckSession $check, User $sender, string $note): self
    {
        return new self(
            sessionId:   $check->id,
            website:     $check->store?->name ?? 'this website',
            senderName:  $sender->name,
            senderEmail: $sender->email,
            note:        $note,
            skus:        self::notMappedOf($check),
        );
    }

    /**
     * The SKUs the check could not find, from its result file.
     *
     * @return list<string>
     */
    public static function notMappedOf(SkuCheckSession $check): array
    {
        $path = storage_path("app/sku-checks/{$check->id}.csv");

        if (!file_exists($path)) {
            return [];
        }

        $skus   = [];
        $handle = fopen($path, 'r');
        fgetcsv($handle);   // header

        while (($row = fgetcsv($handle)) !== false) {
            if (strtolower($row[1] ?? '') === 'not available' && trim((string) ($row[0] ?? '')) !== '') {
                $skus[] = trim($row[0]);
            }
        }

        fclose($handle);

        return array_values(array_unique($skus));
    }

    public static function subjectFor(SkuCheckSession $check, int $count): string
    {
        return "[SKU check #{$check->id}] {$count} " . ($count === 1 ? 'SKU is' : 'SKUs are')
             . ' not mapped in Cegid for ' . ($check->store?->name ?? 'this website');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $check   = SkuCheckSession::with('store')->find($this->sessionId);
        $count   = count($this->skus);
        $subject = $check
            ? self::subjectFor($check, $count)
            : "[SKU check #{$this->sessionId}] {$count} SKU(s) not mapped in Cegid for {$this->website}";

        return (new MailMessage)
            ->subject($subject)
            ->replyTo($this->senderEmail, $this->senderName)
            ->view('emails.product-request.it-support', [
                'note'       => $this->note,
                'senderName' => $this->senderName,
                'website'    => $this->website,
                'count'      => $count,
                'skus'       => $this->skus,
                'rows'       => $check ? [
                    'SKU check'  => '#' . $check->id . ($check->name ? " · {$check->name}" : ''),
                    'Website'    => $this->website,
                    'SKUs'       => number_format($check->total_skus),
                    'Mapped'     => number_format($check->available_count),
                    'Not mapped' => number_format($check->not_available_count),
                    'Checked'    => $check->updated_at?->format('d M Y, H:i'),
                    'Run by'     => $check->user?->name,
                ] : [],
                'url'        => route('sku-checker.show', $this->sessionId),
                'urlLabel'   => 'Open this check',
                'source'     => 'the SKU Checker',
                'subject'    => $subject,
                'preheader'  => "{$count} " . ($count === 1 ? 'SKU' : 'SKUs') . " not showing on {$this->website} — list attached.",
            ])
            ->attachData($this->csv(), "sku-check-{$this->sessionId}-not-mapped.csv", ['mime' => 'text/csv']);
    }

    private function csv(): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['SKU', 'Status']);

        foreach ($this->skus as $sku) {
            fputcsv($handle, [$sku, 'Not mapped']);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
