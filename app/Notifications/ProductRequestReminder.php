<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
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
    use Queueable;

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
        $count = count($this->items);

        $mail = (new MailMessage)
            ->subject("{$count} product " . str('request')->plural($count) . ' need your attention')
            ->greeting("Hello {$notifiable->name},")
            ->line("These are waiting on you:");

        foreach ($this->items as $item) {
            $mail->line("• **{$item['name']}** ({$item['reference']}) — {$item['reason']}");
        }

        return $mail->action('Open Assigned to Me', route('product-requests.my-tasks'));
    }

    public function toArray(object $notifiable): array
    {
        $count = count($this->items);

        return [
            'kind'         => 'reminder',
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
