<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductRequestDraftVariant extends Model
{
    protected $fillable = [
        'draft_product_id', 'sku', 'option1_value', 'option2_value', 'option3_value',
        'price', 'compare_at_price', 'barcode', 'weight', 'weight_unit', 'inventory_qty', 'image_src',
        'sheet_row',
    ];

    protected function casts(): array
    {
        return [
            'price'            => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'weight'           => 'decimal:3',
            'sheet_row'        => 'array',
        ];
    }

    /**
     * Sheet columns that did not become a Shopify field — the ones the mapping
     * has no home for. Shown on the review screen so a column nobody told us
     * about is still in front of whoever is checking the product.
     *
     * @return array<string, string|null>
     */
    public function unmappedSheetColumns(): array
    {
        $placed = array_filter([
            $this->sku, $this->option1_value, $this->option2_value, $this->option3_value,
            $this->barcode, $this->image_src,
        ], fn ($v) => filled($v));

        return collect($this->sheet_row ?? [])
            ->reject(fn ($value, $name) => blank($value) || in_array(ltrim((string) $value, "'"), $placed, true))
            ->all();
    }

    public function draftProduct(): BelongsTo
    {
        return $this->belongsTo(ProductRequestDraftProduct::class, 'draft_product_id');
    }
}
