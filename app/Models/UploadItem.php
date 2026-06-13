<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UploadItem extends Model
{
    protected $fillable = [
        'upload_session_id',
        'filename',
        'sku_detected',
        'product_id',
        'product_title',
        'variant_id',
        'variant_sku',
        'shopify_image_id',
        'onedrive_download_url',
        'onedrive_drive_id',
        'onedrive_item_id',
        'status',
        'error_message',
        'original_size_kb',
        'processed_size_kb',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(UploadSession::class, 'upload_session_id');
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'uploaded'   => 'green',
            'matched'    => 'blue',
            'failed'     => 'red',
            'skipped'    => 'yellow',
            'processing' => 'indigo',
            default      => 'gray',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'uploaded'   => 'Uploaded',
            'matched'    => 'Matched',
            'failed'     => 'Failed',
            'skipped'    => 'No Match',
            'processing' => 'Processing',
            default      => 'Pending',
        };
    }
}
