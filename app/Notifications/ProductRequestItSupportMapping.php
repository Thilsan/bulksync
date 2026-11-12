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
 * goes back to whoever sent it. The request's brand manager and e-commerce
 * owner are copied, so the people waiting on the mapping see that IT has it.
 */
class ProductRequestItSupportMapping extends Notification implements ShouldQueue
{
    use BuildsRequestEmail, Queueable;

    /** The Settings key holding IT support's address(es), set by a super admin. */
    public const SETTING = 'pcr_it_support_email';

    /** The roles copied on the email, in the order they are listed. */
    public const COPIED_ROLES = ['brand_manager_id', 'assigned_to'];

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
     * @param  array<int, array{name: string, email: string, role: string}>  $cc
     */
    public function __construct(
        public readonly int    $requestId,
        public readonly string $reference,
        public readonly string $website,
        public readonly string $senderName,
        public readonly string $senderEmail,
        public readonly string $note,
        public readonly array  $unmapped,
        public readonly array  $cc = [],
    ) {
        $this->onQueue(Queues::PRODUCT_REQUESTS);
    }

    /** @param  list<string>  $to  IT's addresses, so nobody is both sent and copied */
    public static function forRequest(ProductRequest $request, User $sender, string $note, array $to = []): self
    {
        $to = array_map('strtolower', $to);

        return new self(
            requestId:   $request->id,
            reference:   $request->reference,
            website:     $request->store?->name ?? 'this website',
            senderName:  $sender->name,
            senderEmail: $sender->email,
            note:        $note,
            unmapped:    self::unmappedOf($request),
            cc:          array_values(array_filter(self::copiedOn($request), fn ($p) => !in_array($p['email'], $to, true))),
        );
    }

    /**
     * Whoever holds the brand manager and e-commerce roles on the request now,
     * once each. Someone deactivated, or with no address, is left out.
     *
     * @return list<array{name: string, email: string, role: string}>
     */
    public static function copiedOn(ProductRequest $request): array
    {
        return collect(self::COPIED_ROLES)
            ->map(fn ($role) => ['user' => $request->ownerFor($role), 'role' => ProductRequest::ASSIGNMENT_ROLES[$role]])
            ->filter(fn ($held) => $held['user']?->is_active && filled($held['user']->email))
            ->map(fn ($held) => ['name' => $held['user']->name, 'email' => strtolower($held['user']->email), 'role' => $held['role']])
            ->unique('email')
            ->values()
            ->all();
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

        $mail = (new MailMessage)
            ->subject($subject)
            ->replyTo($this->senderEmail, $this->senderName);

        foreach ($this->cc as $person) {
            $mail->cc($person['email'], $person['name']);
        }

        return $mail
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
