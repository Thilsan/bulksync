<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiContentItem extends Model
{
    protected $fillable = [
        'session_id', 'sku', 'shopify_product_id', 'product_title',
        'image_url', 'shopify_image_id', 'ai_description', 'ai_meta_title', 'ai_meta_description',
        'ai_alt_text', 'status', 'is_confirmed', 'error_message',
    ];

    protected function casts(): array
    {
        return ['is_confirmed' => 'boolean'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiContentSession::class, 'session_id');
    }
}
