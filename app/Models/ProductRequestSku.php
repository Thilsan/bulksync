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
        'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'in_shopify'        => 'boolean',
            'shopify_published' => 'boolean',
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

    /** The Supply Chain user who recorded the mapping outcome, if anyone has. */
    public function mappedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mapping_set_by');
    }

    /** True once a human has set the status — the automatic check leaves it alone. */
    public function isManuallySet(): bool
    {
        return $this->mapping_set_by !== null;
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

    public function sourceLabel(): string
    {
        if ($this->isManuallySet()) {
            return $this->mappedBy?->name ?? 'Supply Chain';
        }

        return $this->in_shopify ? 'Auto (found in Shopify)' : 'Awaiting Supply Chain';
    }
}
