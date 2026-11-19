<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class SeoAuditSession extends Model
{
    protected $fillable = [
        'user_id', 'store_id', 'status',
        'total_products', 'scanned_products', 'total_collections', 'scanned_collections', 'not_live_pages', 'clean_products',
        'products_with_issues', 'total_issues', 'average_score',
        'issue_breakdown', 'error_message', 'rechecked_at', 'rechecked_products',
    ];

    protected $casts = ['issue_breakdown' => 'array', 'rechecked_at' => 'datetime'];

    public function user(): BelongsTo  { return $this->belongsTo(User::class); }
    public function store(): BelongsTo { return $this->belongsTo(Store::class); }
    public function items(): HasMany   { return $this->hasMany(SeoAuditItem::class); }

    /** Everything the audit graded: product pages and collection pages alike. */
    public function scannedTotal(): int
    {
        return $this->scanned_products + $this->scanned_collections;
    }

    public function progressPercent(): int
    {
        $total = $this->total_products + $this->total_collections;

        if ($total === 0) return 0;

        return (int) min(100, round($this->scannedTotal() / $total * 100));
    }

    /**
     * Re-decide the duplicate flags for the given meta titles or descriptions.
     *
     * Used when a few rows change after the audit ran. A changed product can
     * start clashing with something under its new wording, and the page it used
     * to clash with may now be the only one left holding the old wording — so
     * both the old and the new values are passed in, and every live row holding
     * one of them is flagged or cleared on what the catalogue says now.
     *
     * @param  'meta_title'|'meta_description'  $column
     * @param  list<string|null>                $values
     */
    public function refreshDuplicateFlags(string $column, array $values): void
    {
        $code = $column === 'meta_title' ? 'duplicate_meta_title' : 'duplicate_meta_description';

        $values = collect($values)
            ->map(fn ($v) => trim((string) $v))
            ->filter()
            ->unique(fn ($v) => mb_strtolower($v));

        // A few hundred values per query rather than one each: the column has no
        // index on its lowered form, so every query is a scan of the audit.
        foreach ($values->chunk(200) as $chunk) {
            $placeholders = implode(', ', array_fill(0, $chunk->count(), 'LOWER(TRIM(?))'));

            // Lowered in SQL on both sides so the database's own idea of case
            // decides, exactly as the full audit's grouping did.
            $groups = $this->items()
                ->live()
                ->whereRaw("LOWER(TRIM({$column})) IN ({$placeholders})", $chunk->values()->all())
                ->select('*', DB::raw("LOWER(TRIM({$column})) as normalised"))
                ->get()
                ->groupBy('normalised');

            foreach ($groups as $rows) {
                $clashes = $rows->count() > 1;

                foreach ($rows as $row) {
                    $issues = array_values(array_diff($row->issues ?? [], [$code]));
                    if ($clashes) {
                        $issues[] = $code;
                    }

                    if ($issues !== ($row->issues ?? [])) {
                        $row->update([
                            'issues'      => $issues,
                            'issue_count' => count($issues),
                            'score'       => SeoAuditItem::scoreFor($issues),
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Recount the headline figures from the rows as they stand, after some of
     * them were re-graded. Same rules as the full audit's summary: live pages
     * only.
     */
    public function recount(): void
    {
        $live      = SeoAuditItem::where('seo_audit_session_id', $this->id)->live();
        $liveCount = (clone $live)->count();
        $issues    = (clone $live)->where('issue_count', '>', 0)->count();
        $average   = (clone $live)->avg('score');

        $counts = [];
        (clone $live)->where('issue_count', '>', 0)->select(['id', 'issues'])
            ->chunkById(1000, function ($rows) use (&$counts) {
                foreach ($rows as $row) {
                    foreach ($row->issues ?? [] as $code) {
                        $counts[$code] = ($counts[$code] ?? 0) + 1;
                    }
                }
            });

        $this->update([
            'clean_products'       => $liveCount - $issues,
            'products_with_issues' => $issues,
            'total_issues'         => array_sum($counts),
            'average_score'        => (int) round($average ?? 0),
            'issue_breakdown'      => $counts,
        ]);
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
