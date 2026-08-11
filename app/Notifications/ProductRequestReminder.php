<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use App\Notifications\Concerns\BuildsRequestEmail;
use Illuminate\Notifications\Notification;

/**
 * One daily nudge per person, listing only what they are actually holding up.
 *
 * Status notifications fire on change; nothing chased a request that simply sat
 * there. This is the part that removes the manual follow-up — a request going
 * quiet three days before its launch date now speaks up on its own.
 */
class ProductRequestReminder extends Notification implements ShouldQueue
{
    use BuildsRequestEmail, Queueable;

    /**
     * @param  array<int, array{reference: string, name: string, reason: string, request_id: int}>  $items
     */
    public function __construct(public readonly array $items)
    {
        $this->onQueue('bulkupload');
    }

    public function via(object $notifiable): array
    {
        return config('mail.default') === 'log'
            ? ['database']
            : ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count   = count($this->items);
        $subject = $count === 1
            ? '1 product request needs your attention'
            : "{$count} product requests need your attention";

        return (new MailMessage)
            ->subject($subject)
            ->view('emails.product-request.reminder', [
                'recipientName' => $notifiable->name,
                'count'         => $count,
                'items'         => collect($this->items)
                    ->map(fn ($i) => $i + ['url' => route('product-requests.show', $i['request_id'])])
                    ->all(),
                'url'           => route('product-requests.my-tasks'),
                'subject'       => $subject,
                'preheader'     => collect($this->items)->pluck('reason')->first() ?? 'Work is waiting on you.',
            ]);
    }

    public function toArray(object $notifiable): array
    {
        $count = count($this->items);

        return [
            'kind'         => 'reminder',
            // A digest of nothing but this person's own outstanding work.
            'for_me'       => true,
            'reference'    => $count . ' ' . str('request')->plural($count),
            'brand'        => 'Needs your attention',
            'status_label' => 'Reminder',
            'remarks'      => collect($this->items)->map(fn ($i) => "{$i['reference']}: {$i['reason']}")->implode(' · '),
            'actor'        => 'System',
            'request_id'   => $count === 1 ? $this->items[0]['request_id'] : null,
            'items'        => $this->items,
        ];
    }
}
