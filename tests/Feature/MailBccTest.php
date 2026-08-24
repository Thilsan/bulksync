<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Illuminate\Mail\Message;
use Tests\TestCase;

/**
 * Every outgoing message is blind-copied to the developer addresses.
 *
 * Hooked at the message level rather than on each Mailable: there are a dozen of
 * those and more will be written, and one forgotten ->bcc() would be invisible,
 * because nobody notices a copy that never arrives.
 */
class MailBccTest extends TestCase
{
    /** @return array<int, string> */
    private function bccOf(string $to = 'someone@abuissa.com'): array
    {
        $captured = [];

        // The real mailer, with the array transport, so the MessageSending event
        // actually fires — Mail::fake() would swallow it.
        config(['mail.default' => 'array']);

        Mail::raw('Body', function (Message $message) use ($to) {
            $message->to($to)->subject('Test');
        });

        $transport = Mail::mailer('array')->getSymfonyTransport();

        foreach ($transport->messages() as $sent) {
            foreach ($sent->getOriginalMessage()->getBcc() as $address) {
                $captured[] = $address->getAddress();
            }
        }

        $transport->flush();

        return $captured;
    }

    public function test_both_developer_addresses_are_copied_on_every_message(): void
    {
        config(['mail.bcc' => ['abuissadeveloper321@gmail.com', 'aihcoder@gmail.com']]);

        $bcc = $this->bccOf();

        $this->assertContains('abuissadeveloper321@gmail.com', $bcc);
        $this->assertContains('aihcoder@gmail.com', $bcc);
    }

    /** An empty list turns it off, so an environment can opt out. */
    public function test_no_copies_are_added_when_the_list_is_empty(): void
    {
        config(['mail.bcc' => []]);

        $this->assertSame([], $this->bccOf());
    }

    /** Sending twice to the same address would be worse than not sending at all. */
    public function test_an_address_already_on_the_message_is_not_added_again(): void
    {
        config(['mail.bcc' => ['aihcoder@gmail.com']]);

        $bcc = $this->bccOf(to: 'aihcoder@gmail.com');

        $this->assertNotContains('aihcoder@gmail.com', $bcc);
    }

    public function test_a_malformed_address_is_ignored_rather_than_breaking_the_send(): void
    {
        config(['mail.bcc' => ['not-an-email', 'aihcoder@gmail.com']]);

        $bcc = $this->bccOf();

        $this->assertSame(['aihcoder@gmail.com'], $bcc);
    }

    /** The default is the two addresses, without anything set in the environment. */
    public function test_the_two_addresses_are_the_default(): void
    {
        $this->assertSame(
            ['abuissadeveloper321@gmail.com', 'aihcoder@gmail.com'],
            config('mail.bcc'),
        );
    }
}
