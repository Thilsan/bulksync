<?php

namespace App\Support;

use App\Models\EmailLog;
use App\Models\ProductRequest;
use App\Models\User;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;

/**
 * Writes a row for every email, at the message level like BccEveryMessage.
 *
 * Hooked here rather than in each notification: there are nine of them and more
 * will come, and an email that never reached the log is exactly the one somebody
 * will ask about. The row is written as "sending" and turned to "sent" when the
 * mail server accepts it, so a refusal leaves a trace instead of nothing.
 *
 * Nothing here may throw — the bookkeeping must never stop the email itself.
 */
class EmailLogRecorder
{
    /** Carries the row id from MessageSending to MessageSent on the same message. */
    public const HEADER = 'X-BulkSync-Log';

    public static function sending(MessageSending $event): void
    {
        try {
            $message = $event->message;
            $to      = array_map(fn (Address $a) => strtolower($a->getAddress()), $message->getTo());

            if (!$to) {
                return;
            }

            $subject  = (string) $message->getSubject();
            $body     = $message->getHtmlBody() ?? $message->getTextBody();
            $class    = $event->data['__laravel_notification'] ?? null;

            // Every product request email names its reference in the subject.
            $requestId = preg_match('/\[(PCR-\d{4}-\d+)\]/', $subject, $m)
                ? ProductRequest::where('reference', $m[1])->value('id')
                : null;

            $log = EmailLog::create([
                'user_id'            => User::whereIn('email', $to)->value('id'),
                'to_address'         => mb_substr(implode(', ', $to), 0, 512),
                'subject'            => mb_substr($subject, 0, 512),
                'notification'       => $class ? class_basename($class) : null,
                'product_request_id' => $requestId,
                'status'             => EmailLog::SENDING,
                'body'               => is_resource($body) ? stream_get_contents($body) : $body,
            ]);

            $message->getHeaders()->addTextHeader(self::HEADER, (string) $log->id);
        } catch (\Throwable $e) {
            Log::warning('EmailLogRecorder could not record an email', ['error' => $e->getMessage()]);
        }
    }

    public static function sent(MessageSent $event): void
    {
        try {
            $id = $event->sent->getOriginalMessage()->getHeaders()->get(self::HEADER)?->getBodyAsString();

            if ($id) {
                EmailLog::whereKey((int) $id)->update(['status' => EmailLog::SENT, 'sent_at' => now()]);
            }
        } catch (\Throwable $e) {
            Log::warning('EmailLogRecorder could not mark an email sent', ['error' => $e->getMessage()]);
        }
    }
}
