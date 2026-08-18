<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One SKU folder within a run, with its own edit settings.
 *
 * The settings live here rather than on the session because a folder of
 * dresses and a folder of watches want different treatment, and a run
 * routinely holds both.
 */
class PhotoEditGroup extends Model
{
    /** Nobody needs forty on-model variations of one product, and each is billed. */
    public const MAX_LIFESTYLE = 6;

    protected $fillable = [
        'photo_edit_session_id',
        'sku',
        'edits',
        'lifestyle_count',
        'lifestyle_source_item_id',
    ];

    protected function casts(): array
    {
        return [
            'edits'           => 'array',
            'lifestyle_count' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PhotoEditSession::class, 'photo_edit_session_id');
    }

    /** Every photo in this SKU folder, generated ones included. */
    public function items(): HasMany
    {
        return $this->hasMany(PhotoEditItem::class, 'photo_edit_session_id', 'photo_edit_session_id')
            ->where('sku_detected', $this->sku);
    }

    /** The real photographs, which are what a lifestyle shot can be built from. */
    public function sourceItems()
    {
        return PhotoEditItem::where('photo_edit_session_id', $this->photo_edit_session_id)
            ->where('sku_detected', $this->sku)
            ->where('kind', 'cutout')
            ->orderBy('filename');
    }

    /**
     * Photoroom calls this group will spend: one per photo, plus one per
     * on-model image asked for.
     */
    public function creditCost(): int
    {
        return $this->sourceItems()->count() + $this->lifestyle_count;
    }

    /**
     * On-model generation needs a photo to dress the model from, and it has to
     * be one that actually exists in this group — a stale pick from a previous
     * configuration would silently generate from the wrong garment.
     */
    public function lifestyleSourceIsValid(): bool
    {
        if ($this->lifestyle_count < 1) {
            return true;
        }

        return $this->lifestyle_source_item_id !== null
            && $this->sourceItems()->whereKey($this->lifestyle_source_item_id)->exists();
    }
}
