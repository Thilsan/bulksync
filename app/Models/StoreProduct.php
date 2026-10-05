<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One product in one website's catalogue, as the nightly sales sync last read
 * it. A copy for ranking against, never edited here — Shopify stays the truth.
 */
class StoreProduct extends Model
{
    protected $fillable = [
        'store_id', 'product_id', 'title', 'handle', 'vendor', 'product_type',
        'status', 'total_inventory', 'sku', 'image_url', 'shopify_created_at',
    ];

    protected $casts = [
        'total_inventory'    => 'integer',
        'shopify_created_at' => 'datetime',
    ];
}
