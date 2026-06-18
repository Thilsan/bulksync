<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UploadSession extends Model
{
    protected $fillable = [
        'user_id',
        'store_id',
        'name',
        'onedrive_link',
        'image_size',
        'image_width',
        'image_height',
        'duplicate_handling',
        'status',
        'scan_status',
        'total_files',
        'scanned_files',
        'matched_files',
        'uploaded_files',
        'failed_files',
        'skipped_files',
        'error_message',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(UploadItem::class);
    }

    public function pendingItems(): HasMany
    {
        return $this->hasMany(UploadItem::class)->where('status', 'pending');
    }

    public function progressPercent(): int
    {
        if ($this->total_files === 0) {
            return 0;
        }

        $done = $this->uploaded_files + $this->failed_files + $this->skipped_files;

        return (int) min(100, round(($done / $this->total_files) * 100));
    }

    public function isFinished(): bool
    {
        return in_array($this->status, ['completed', 'failed']);
    }

    public function dimensionLabel(): string
    {
        return ($this->image_width ?? 1200) . ' × ' . ($this->image_height ?? 1200) . ' px';
    }
}
