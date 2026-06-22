<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImageAuditSession extends Model
{
    protected $fillable = [
        'user_id', 'store_id', 'status',
        'total_products', 'scanned_products', 'total_skus',
        'with_images', 'without_images', 'error_message',
    ];

    public function user(): BelongsTo   { return $this->belongsTo(User::class); }
    public function store(): BelongsTo  { return $this->belongsTo(Store::class); }
    public function items(): HasMany    { return $this->hasMany(ImageAuditItem::class); }

    public function progressPercent(): int
    {
        if ($this->total_products === 0) return 0;
        return (int) min(100, round($this->scanned_products / $this->total_products * 100));
    }
}
