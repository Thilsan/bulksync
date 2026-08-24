<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * Blind-copies every outgoing message to the addresses in config('mail.bcc').
 *
 * Done at the message level rather than on each Mailable or Notification: there
 * are a dozen of those and more will be written, and one forgotten `->bcc()`
 * would be invisible — nobody notices a copy that never arrives.
 */
class BccEveryMessage
{
    public function handle(MessageSending $event): void
    {
        $bcc = (array) config('mail.bcc', []);

        if (!$bcc) {
            return;
        }

        $message = $event->message;

        // Already on the message — a Mailable that set one of these itself, or a
        // resend of the same message object. Adding it twice would send twice.
        $existing = array_map(
            fn (Address $address) => strtolower($address->getAddress()),
            array_merge($message->getBcc(), $message->getTo(), $message->getCc()),
        );

        $addresses = [];

        foreach ($bcc as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) && !in_array(strtolower($address), $existing, true)) {
                $addresses[] = new Address($address);
            }
        }

        if ($addresses) {
            $message->addBcc(...$addresses);
        }
    }
}
