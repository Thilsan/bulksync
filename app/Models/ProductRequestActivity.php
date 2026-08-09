<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductRequestActivity extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'product_request_id',
        'user_id',
        'action',
        'from_status',
        'to_status',
        'description',
        'remarks',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function productRequest(): BelongsTo
    {
        return $this->belongsTo(ProductRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actorName(): string
    {
        return $this->user?->name ?? 'System';
    }

    public function actorRole(): ?string
    {
        return $this->user?->pcrRoleLabel();
    }

    public function statusLabel(): ?string
    {
        return $this->to_status ? (ProductRequest::STATUS_LABELS[$this->to_status] ?? $this->to_status) : null;
    }

    public function statusColor(): string
    {
        return ProductRequest::STATUS_COLORS[$this->to_status] ?? 'bg-gray-100 text-gray-700 border-gray-200';
    }
}
