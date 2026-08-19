<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductRequestDraftProduct extends Model
{
    public const PENDING = 'pending';
    public const PUSHED  = 'pushed';
    public const FAILED  = 'failed';

    protected $fillable = [
        'product_request_id', 'handle', 'style_code', 'title', 'body_html', 'vendor',
        'product_type', 'tags', 'option1_name', 'option2_name', 'option3_name',
        'image_src', 'push_status', 'shopify_product_id', 'push_error', 'pushed_at',
        'pushed_to_store_id', 'edited_at',
    ];

    protected function casts(): array
    {
        return ['pushed_at' => 'datetime', 'edited_at' => 'datetime'];
    }

    /** Corrected by hand, so a rebuild leaves it alone. */
    public function wasEdited(): bool
    {
        return $this->edited_at !== null;
    }

    public function productRequest(): BelongsTo
    {
        return $this->belongsTo(ProductRequest::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductRequestDraftVariant::class, 'draft_product_id')->orderBy('id');
    }

    public function pushedToStore(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'pushed_to_store_id');
    }

    public function isPushed(): bool
    {
        return $this->push_status === self::PUSHED;
    }

    /** The option names actually in use, in order — Shopify rejects a gap. */
    public function optionNames(): array
    {
        return array_values(array_filter([$this->option1_name, $this->option2_name, $this->option3_name]));
    }

    /**
     * What is still missing before this can go to Shopify. A draft with no title
     * or no price is not a product yet, and pushing it just makes something the
     * team has to fix in Shopify instead of here.
     *
     * @return array<int, string>
     */
    public function gaps(): array
    {
        $gaps = [];

        if (blank($this->title)) {
            $gaps[] = 'title';
        }

        if ($this->variants->contains(fn ($v) => $v->price === null)) {
            $gaps[] = 'price';
        }

        return $gaps;
    }

    public function isReadyToPush(): bool
    {
        return !$this->isPushed() && empty($this->gaps());
    }
}
