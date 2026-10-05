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
        'status', 'total_inventory', 'sku', 'division', 'image_url', 'shopify_created_at',
    ];

    protected $casts = [
        'total_inventory'    => 'integer',
        'shopify_created_at' => 'datetime',
    ];

    /**
     * The division a SKU belongs to: its first three letters and the three
     * digits after them, so GAT207LUG00325 is division GAT207. A SKU that does
     * not start that way has no division.
     */
    public static function divisionFromSku(?string $sku): ?string
    {
        return preg_match('/^([A-Za-z]{3}\d{3})/', trim((string) $sku), $m) ? strtoupper($m[1]) : null;
    }
}
