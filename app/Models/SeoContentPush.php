<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product's SEO content being written to Shopify, and what organic traffic
 * to that URL did either side of it.
 *
 * The point of the row is the date: GA4 keeps its history, so the "before"
 * window can be asked for after the fact rather than snapshotted at push time.
 */
class SeoContentPush extends Model
{
    /**
     * Days either side of the push that are compared, and how long to wait
     * before asking. Twenty-eight covers four of every weekday, so a push that
     * happened to land on a quiet Sunday is not measured against a busy one.
     *
     * The settle period is longer than the window because Google has to
     * recrawl the page before a rewritten title can change anything; measuring
     * at day 28 would mostly measure the crawl delay.
     */
    public const WINDOW_DAYS = 28;
    public const SETTLE_DAYS = 35;

    protected $fillable = [
        'user_id', 'store_id', 'product_id', 'handle', 'product_title',
        'meta_title', 'meta_description', 'pushed_at',
        'sessions_before', 'sessions_after', 'measured_at',
        'measurement_status', 'measurement_note',
    ];

    protected function casts(): array
    {
        return [
            'pushed_at'   => 'datetime',
            'measured_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo  { return $this->belongsTo(User::class); }
    public function store(): BelongsTo { return $this->belongsTo(Store::class); }

    /**
     * Percentage change in organic sessions, or null when there is no honest
     * answer — an unmeasured push, or a baseline of zero, which would make
     * every gain read as an infinite one.
     */
    public function changePercent(): ?float
    {
        if ($this->measurement_status !== 'measured' || !$this->sessions_before) {
            return null;
        }

        return round((($this->sessions_after - $this->sessions_before) / $this->sessions_before) * 100, 1);
    }

    /** Pushes old enough that the after-window has fully closed. */
    public function scopeReadyToMeasure($query)
    {
        return $query->where('measurement_status', 'pending')
            ->whereNotNull('store_id')
            ->where('pushed_at', '<=', now()->subDays(self::SETTLE_DAYS));
    }
}
