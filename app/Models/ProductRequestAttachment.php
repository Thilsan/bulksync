<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductRequestAttachment extends Model
{
    public const UPDATED_AT = null;

    /** Mood boards / reference shots supplied with the request. */
    public const KIND_REFERENCE = 'reference';

    /** The brand team's written content, when the AI generator isn't being used. */
    public const KIND_CONTENT = 'content';

    protected $fillable = [
        'product_request_id',
        'user_id',
        'kind',
        'original_name',
        'path',
        'mime',
        'size',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'size'       => 'integer',
        ];
    }

    public function productRequest(): BelongsTo
    {
        return $this->belongsTo(ProductRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function humanSize(): string
    {
        $bytes = $this->size;
        if ($bytes < 1024) return "{$bytes} B";
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';

        return round($bytes / 1048576, 1) . ' MB';
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    public function isContentSheet(): bool
    {
        return $this->kind === self::KIND_CONTENT;
    }

    /**
     * The largest upload PHP will actually accept, in kilobytes.
     *
     * A file bigger than upload_max_filesize is thrown away by PHP before
     * Laravel sees it: hasFile() returns false, a `nullable` rule passes, and
     * the user gets a saved record with no file and no error. So every limit we
     * validate against and every hint we show has to be the real one.
     */
    public static function maxUploadKb(): int
    {
        $toKb = static function (string|false $value): int {
            $value = trim((string) $value);

            if ($value === '') {
                return PHP_INT_MAX;
            }

            $number = (int) $value;

            return match (strtolower(substr($value, -1))) {
                'g'     => $number * 1024 * 1024,
                'm'     => $number * 1024,
                'k'     => $number,
                default => (int) ($number / 1024),   // plain bytes
            };
        };

        return max(1, min(
            $toKb(ini_get('upload_max_filesize')),
            $toKb(ini_get('post_max_size')),
        ));
    }

    /** Same limit, capped at what the module wants, as a human string. */
    public static function maxUploadLabel(int $preferredKb = 10240): string
    {
        $kb = min($preferredKb, self::maxUploadKb());

        return $kb >= 1024 ? round($kb / 1024, 1) . 'MB' : $kb . 'KB';
    }
}
