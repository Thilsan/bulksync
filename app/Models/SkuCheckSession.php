<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SkuCheckSession extends Model
{
    protected $fillable = [
        'user_id',
        'store_id',
        'name',
        'status',
        'total_skus',
        'scanned_skus',
        'available_count',
        'not_available_count',
        'raw_skus',
        'error_message',
        'variant_export_status',
        'variant_export_total',
        'variant_export_scanned',
        'variant_export_failed',
        'variant_export_error',
    ];

    public function progressPercent(): int
    {
        if ($this->total_skus === 0) return 0;
        return (int) min(100, round($this->scanned_skus / $this->total_skus * 100));
    }

    public function variantExportProgressPercent(): int
    {
        if (($this->variant_export_total ?? 0) === 0) return 0;
        return (int) min(100, round($this->variant_export_scanned / $this->variant_export_total * 100));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SkuCheckItem::class);
    }
}
