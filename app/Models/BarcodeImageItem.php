<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BarcodeImageItem extends Model
{
    protected $fillable = [
        'barcode_image_session_id',
        'barcode',
        'status',
        'product_url',
        'source_site',
        'product_title',
        'image_count',
        'message',
        'push_status',
        'shopify_product_id',
        'shopify_product_title',
        'pushed_images',
        'push_message',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(BarcodeImageSession::class, 'barcode_image_session_id');
    }
}
