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
        'product_title',
        'image_count',
        'message',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(BarcodeImageSession::class, 'barcode_image_session_id');
    }
}
