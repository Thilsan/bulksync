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
}
