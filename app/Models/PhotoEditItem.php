<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhotoEditItem extends Model
{
    protected $fillable = [
        'photo_edit_session_id',
        'filename',
        'sku_detected',
        'onedrive_drive_id',
        'onedrive_item_id',
        'onedrive_download_url',
        'original_thumb_path',
        'edited_thumb_path',
        'edited_path',
        'original_size_kb',
        'edited_size_kb',
        'status',
        'view_type',
        'mannequin_visible',
        'apparel_mode_applied',
        'selected',
        'product_id',
        'product_title',
        'variant_id',
        'variant_sku',
        'shopify_image_id',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'selected'          => 'boolean',
            'mannequin_visible' => 'boolean',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PhotoEditSession::class, 'photo_edit_session_id');
    }

    /** Only an item that edited cleanly and still has its file can be pushed. */
    public function isPushable(): bool
    {
        return $this->status === 'edited' && $this->edited_path !== null;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'editing' => 'Editing',
            'edited'  => 'Ready',
            'pushing' => 'Pushing',
            'pushed'  => 'On Shopify',
            'failed'  => 'Failed',
            'skipped' => 'No Match',
            default   => 'Waiting',
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            'edited'  => 'emerald',
            'pushed'  => 'brand',
            'editing', 'pushing' => 'indigo',
            'failed'  => 'red',
            'skipped' => 'amber',
            default   => 'gray',
        };
    }

    /**
     * Drop the full-size edit but keep the thumbnails.
     *
     * Called once Shopify has the image: at that point our copy is a duplicate
     * of bytes that already live somewhere permanent, and it is by far the
     * largest thing this feature writes. The thumbnails stay so the session
     * still shows what was done.
     */
    public function discardFullSize(): void
    {
        if (!$this->edited_path) {
            return;
        }

        $path = storage_path('app/' . $this->edited_path);

        if (is_file($path)) {
            @unlink($path);
        }

        $this->update(['edited_path' => null]);
    }
}
