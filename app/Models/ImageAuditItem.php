<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImageAuditItem extends Model
{
    protected $fillable = [
        'image_audit_session_id', 'sku', 'product_id',
        'product_title', 'variant_id', 'image_count', 'has_image',
    ];

    protected $casts = ['has_image' => 'boolean'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ImageAuditSession::class, 'image_audit_session_id');
    }
}
