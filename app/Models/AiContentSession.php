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

    /**
     * What the session is actually doing, in words a merchandiser can act on —
     * the raw status column is for code, not for the screen.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending'     => 'Queued',
            'processing'  => 'Generating content',
            'ready'       => 'Ready to push to Shopify',
            'translating' => 'Translating',
            'pushing'     => 'Pushing to Shopify',
            'done'        => 'Pushed to Shopify',
            'failed'      => 'Failed',
            default       => ucfirst($this->status),
        };
    }

    /** Statuses where work is still moving — used to decide whether to keep polling. */
    public function isWorking(): bool
    {
        return in_array($this->status, ['pending', 'processing', 'translating', 'pushing'], true);
    }
}
