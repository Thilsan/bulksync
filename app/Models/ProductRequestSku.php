<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductRequestSku extends Model
{
    protected $fillable = [
        'product_request_id',
        'sku',
        'mapping_status',
        'mapping_set_by',
        'mapping_set_at',
        'mapping_note',
        'in_shopify',
        'shopify_product_id',
        'shopify_product_title',
        'shopify_published',
        'has_description',
        'sheet_has_description',
        'sheet_checked_at',
        'content_started_at',
        'content_skipped_at',
        'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'in_shopify'        => 'boolean',
            'shopify_published' => 'boolean',
            'has_description'       => 'boolean',
            'sheet_has_description' => 'boolean',
            'sheet_checked_at'      => 'datetime',
            'content_started_at' => 'datetime',
            'content_skipped_at' => 'datetime',
            'mapping_set_at'    => 'datetime',
            'last_checked_at'   => 'datetime',
        ];
    }

    public const LABELS = [
        ProductRequest::MAP_MAPPED     => 'Mapped',
        ProductRequest::MAP_PENDING    => 'Pending Mapping',
        ProductRequest::MAP_NOT_MAPPED => 'Not Mapped',
    ];

    public const COLORS = [
        ProductRequest::MAP_MAPPED     => 'bg-green-50 text-green-700 border-green-200',
        ProductRequest::MAP_PENDING    => 'bg-amber-50 text-amber-700 border-amber-200',
        ProductRequest::MAP_NOT_MAPPED => 'bg-red-50 text-red-700 border-red-200',
    ];

    /** 🟢 / 🟡 / 🔴 — the legend the brand team asked for. */
    public const DOTS = [
        ProductRequest::MAP_MAPPED     => 'bg-green-500',
        ProductRequest::MAP_PENDING    => 'bg-amber-500',
        ProductRequest::MAP_NOT_MAPPED => 'bg-red-500',
    ];

    public function productRequest(): BelongsTo
    {
        return $this->belongsTo(ProductRequest::class);
    }

    /** Whoever recorded a mapping outcome by hand before the check took it over. */
    public function mappedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mapping_set_by');
    }

    public function label(): string
    {
        return self::LABELS[$this->mapping_status] ?? $this->mapping_status;
    }

    public function color(): string
    {
        return self::COLORS[$this->mapping_status] ?? 'bg-gray-100 text-gray-600 border-gray-200';
    }

    public function dot(): string
    {
        return self::DOTS[$this->mapping_status] ?? 'bg-gray-400';
    }
}
