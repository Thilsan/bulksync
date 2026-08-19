<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductRequestDraftVariant extends Model
{
    protected $fillable = [
        'draft_product_id', 'sku', 'option1_value', 'option2_value', 'option3_value',
        'price', 'compare_at_price', 'barcode', 'weight', 'weight_unit', 'inventory_qty', 'image_src',
    ];

    protected function casts(): array
    {
        return [
            'price'            => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'weight'           => 'decimal:3',
        ];
    }

    public function draftProduct(): BelongsTo
    {
        return $this->belongsTo(ProductRequestDraftProduct::class, 'draft_product_id');
    }
}
