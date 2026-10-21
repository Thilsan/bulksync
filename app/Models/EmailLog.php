<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One email the app sent, written by EmailLogRecorder. See the migration for why.
 */
class EmailLog extends Model
{
    public const SENDING = 'sending';
    public const SENT    = 'sent';
    public const FAILED  = 'failed';

    /**
     * Handing a message to the mail server takes seconds. One still "sending"
     * after this was refused, or its worker died mid-send — either way it did
     * not arrive, and saying so beats a row that looks as if it might yet.
     */
    public const LOST_AFTER_MINUTES = 15;

    /** Bodies are what fill a disk; the who-and-when is worth keeping longer. */
    public const KEEP_BODY_DAYS = 14;
    public const KEEP_DAYS      = 90;
    public const KEEP_ROWS      = 100000;

    protected $fillable = [
        'user_id', 'to_address', 'subject', 'notification', 'product_request_id',
        'status', 'error', 'body', 'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function productRequest(): BelongsTo
    {
        return $this->belongsTo(ProductRequest::class);
    }

    /** "ProductRequestStatusChanged" reads as "Status changed". */
    public function kind(): string
    {
        if (!$this->notification) {
            return 'Email';
        }

        return ucfirst(strtolower(trim(preg_replace(
            '/(?<!^)[A-Z]/', ' $0',
            preg_replace('/^ProductRequest/', '', $this->notification),
        ))));
    }

    /**
     * Keep the table bounded. Hourly, because the disk has filled twice before
     * and a bulk action can send hundreds of emails in a minute.
     */
    public static function prune(): void
    {
        self::where('status', self::SENDING)
            ->where('created_at', '<', now()->subMinutes(self::LOST_AFTER_MINUTES))
            ->update([
                'status'     => self::FAILED,
                'error'      => 'Never confirmed as sent — the mail server refused it, or the worker stopped while sending.',
                'updated_at' => now(),
            ]);

        self::whereNotNull('body')
            ->where('created_at', '<', now()->subDays(self::KEEP_BODY_DAYS))
            ->update(['body' => null]);

        self::where('created_at', '<', now()->subDays(self::KEEP_DAYS))->delete();

        // The newest KEEP_ROWS stay; one indexed comparison rather than a count.
        $cutoff = DB::table('email_logs')->orderByDesc('id')->offset(self::KEEP_ROWS)->limit(1)->value('id');

        if ($cutoff) {
            self::where('id', '<=', $cutoff)->delete();
        }
    }
}
