<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoAuditSession extends Model
{
    protected $fillable = [
        'user_id', 'store_id', 'status',
        'total_products', 'scanned_products', 'clean_products',
        'products_with_issues', 'total_issues', 'average_score',
        'issue_breakdown', 'error_message',
    ];

    protected $casts = ['issue_breakdown' => 'array'];

    public function user(): BelongsTo  { return $this->belongsTo(User::class); }
    public function store(): BelongsTo { return $this->belongsTo(Store::class); }
    public function items(): HasMany   { return $this->hasMany(SeoAuditItem::class); }

    public function progressPercent(): int
    {
        if ($this->total_products === 0) return 0;
        return (int) min(100, round($this->scanned_products / $this->total_products * 100));
    }

    /**
     * The issue tiles, heaviest first — the order the catalogue declares them,
     * not the order they happened to be counted in.
     */
    public function issueSummary(): array
    {
        $counts = $this->issue_breakdown ?? [];

        $rows = [];
        foreach (SeoAuditItem::ISSUES as $code => $meta) {
            if (($counts[$code] ?? 0) > 0) {
                $rows[] = $meta + ['code' => $code, 'count' => $counts[$code]];
            }
        }

        return $rows;
    }
}
