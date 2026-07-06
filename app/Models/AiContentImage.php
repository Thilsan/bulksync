<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiContentImage extends Model
{
    protected $fillable = [
        'item_id', 'shopify_image_id', 'image_url', 'position',
        'ai_alt_text', 'status', 'error_message',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(AiContentItem::class, 'item_id');
    }
}
