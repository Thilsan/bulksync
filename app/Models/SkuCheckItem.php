<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SkuCheckItem extends Model
{
    protected $fillable = [
        'sku_check_session_id',
        'sku',
        'available',
        'product_title',
        'product_id',
    ];

    protected $casts = [
        'available' => 'boolean',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(SkuCheckSession::class, 'sku_check_session_id');
    }
}
