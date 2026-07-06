<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiContentSession extends Model
{
    protected $fillable = [
        'user_id', 'store_id', 'input_type', 'onedrive_link',
        'sku_raw', 'skus_json', 'status', 'total_items', 'processed_items', 'error_message',
    ];

    public function user(): BelongsTo  { return $this->belongsTo(User::class); }
    public function store(): BelongsTo { return $this->belongsTo(Store::class); }
    public function items(): HasMany   { return $this->hasMany(AiContentItem::class, 'session_id'); }

    public function progressPercent(): int
    {
        if ($this->total_items === 0) return 0;
        return (int) min(100, round($this->processed_items / $this->total_items * 100));
    }
}
