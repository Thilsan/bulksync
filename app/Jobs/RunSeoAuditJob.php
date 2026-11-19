<?php

namespace App\Jobs;

use App\Models\SeoAuditItem;
use App\Models\SeoAuditSession;
use App\Models\Store;
use App\Services\ShopifyService;
use App\Support\SeoGrader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Walks the whole catalogue and records, per product, what is missing or wrong
 * about its search-engine fields. Nothing is written back to Shopify — the
 * audit only reports, and the AI Content Generator is where fixes are made.
 */
class RunSeoAuditJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries   = 1;

    /** Rows buffered before an insert, matching the image audit's flush size. */
    private const FLUSH_AT = 500;

    public function __construct(public readonly int $seoAuditSessionId) {}

    public function handle(): void
    {
        $session = SeoAuditSession::findOrFail($this->seoAuditSessionId);
        $store   = $session->store_id
            ? Store::find($session->store_id)
            : Store::getActive($session->user_id);

        $shopify = $this->shopifyFor($store);

        // A re-run replaces this session's result rather than stacking a second
        // full set of rows on top of the first. Chunked so a large delete never
        // becomes one huge transaction.
        do {
            $deleted = SeoAuditItem::where('seo_audit_session_id', $session->id)
                ->limit(5000)
                ->delete();
        } while ($deleted > 0);

        $session->update([
            'status'            => 'running',
            'total_products'    => $shopify->getProductCount(),
            'total_collections' => $shopify->getCollectionCount(),
        ]);

        Log::info("RunSeoAuditJob: starting audit for session {$session->id}");

        try {
            $buffer      = [];
            $products    = 0;
            $collections = 0;
            $counts      = [];

            // Shared by both passes: grade, count the issues, buffer the row.
            $collect = function (array $row) use ($session, &$buffer, &$counts) {
                // Draft and archived pages are kept but not counted, so the
                // issue tiles agree with the table beneath them — which shows
                // live pages unless asked otherwise.
                $live = $row['status'] === null || $row['status'] === SeoAuditItem::STATUS_ACTIVE;

                if ($live) {
                    foreach ($row['issues'] as $code) {
                        $counts[$code] = ($counts[$code] ?? 0) + 1;
                    }
                }

                // Assigned rather than merged: `+` keeps the left-hand key, so
                // an overriding 'issues' in a second array would be silently
                // dropped and the raw array handed to insert().
                $row['seo_audit_session_id'] = $session->id;
                $row['issues']               = json_encode($row['issues']);
                $row['created_at']           = now();
                $row['updated_at']           = now();

                $buffer[] = $row;

                if (count($buffer) >= self::FLUSH_AT) {
                    SeoAuditItem::insert($buffer);
                    $buffer = [];
                }
            };

            $shopify->streamProductsForSeoAudit(function (array $page) use ($session, $collect, &$products) {
                foreach ($page as $product) {
                    $collect(SeoGrader::product($product));
                    $products++;
                }

                $session->update(['scanned_products' => $products]);
            });

            // Collections after products, and in their own pass: there are only
            // ever a few dozen, and a failure here should not cost the far
            // longer product scan that has already finished.
            $shopify->streamCollectionsForSeoAudit(function (array $page) use ($session, $collect, &$collections) {
                foreach ($page as $collection) {
                    $collect(SeoGrader::collection($collection));
                    $collections++;
                }

                $session->update(['scanned_collections' => $collections]);
            });

            if (!empty($buffer)) {
                SeoAuditItem::insert($buffer);
            }

            $scanned = $products + $collections;

            // Duplicates are the one check a single product cannot answer about
            // itself, so they are resolved once the whole catalogue is in.
            $counts = $this->flagDuplicates($session, $counts);

            $this->summarise($session, $products, $collections, $counts);

            Log::info("RunSeoAuditJob: completed — {$products} products, {$collections} collections, " . array_sum($counts) . ' issues.');

        } catch (\Throwable $e) {
            Log::error('RunSeoAuditJob failed: ' . $e->getMessage());
            $session->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        }
    }

    /**
     * Two products sharing a meta title compete with each other in the same
     * search result, so both are flagged — not just the later one.
     *
     * Returns the running issue counts with the duplicate codes folded in.
     */
    private function flagDuplicates(SeoAuditSession $session, array $counts): array
    {
        foreach ([
            'meta_title'       => 'duplicate_meta_title',
            'meta_description' => 'duplicate_meta_description',
        ] as $column => $code) {
            // Live pages only, on both sides: a draft product is not in the
            // search results, so it cannot be competing with anything — and
            // pairing a live page with a draft would report a clash that does
            // not exist.
            $duplicated = SeoAuditItem::where('seo_audit_session_id', $session->id)
                ->live()
                ->where($column, '<>', '')
                ->whereNotNull($column)
                ->select(DB::raw("LOWER(TRIM({$column})) as normalised"))
                ->groupBy('normalised')
                ->havingRaw('COUNT(*) > 1')
                // get()->pluck(), not the builder's pluck(): the builder's
                // swaps the select list for the named column, which would drop
                // the raw alias this query is built on.
                ->get()
                ->pluck('normalised');

            if ($duplicated->isEmpty()) {
                continue;
            }

            // Chunked by value rather than by row: the id list for a popular
            // duplicate can run to thousands, and a single WHERE IN of every
            // affected id is what pushes a large store's query past the packet
            // limit.
            foreach ($duplicated->chunk(200) as $chunk) {
                SeoAuditItem::where('seo_audit_session_id', $session->id)
                    ->live()
                    ->whereIn(DB::raw("LOWER(TRIM({$column}))"), $chunk->all())
                    ->chunkById(500, function ($items) use ($code, &$counts) {
                        foreach ($items as $item) {
                            $issues = $item->issues ?? [];
                            if (in_array($code, $issues, true)) {
                                continue;
                            }

                            $issues[] = $code;
                            $item->update([
                                'issues'      => $issues,
                                'issue_count' => count($issues),
                                'score'       => SeoAuditItem::scoreFor($issues),
                            ]);

                            $counts[$code] = ($counts[$code] ?? 0) + 1;
                        }
                    });
            }
        }

        return $counts;
    }

    private function summarise(SeoAuditSession $session, int $products, int $collections, array $counts): void
    {
        $base = SeoAuditItem::where('seo_audit_session_id', $session->id);

        // Live pages only. A draft product's missing meta title is not a
        // problem a search engine can see, and counting it would report a
        // catalogue as worse than the one anybody can actually visit.
        $live       = (clone $base)->live();
        $liveCount  = (clone $live)->count();
        $withIssues = (clone $live)->where('issue_count', '>', 0)->count();
        $average    = (clone $live)->avg('score');

        $session->update([
            'status'               => 'completed',
            'scanned_products'     => $products,
            'scanned_collections'  => $collections,
            'not_live_pages'       => (clone $base)->notLive()->count(),
            'clean_products'       => $liveCount - $withIssues,
            'products_with_issues' => $withIssues,
            'total_issues'         => array_sum($counts),
            'average_score'        => (int) round($average ?? 0),
            'issue_breakdown'      => $counts,
        ]);
    }

    /** Seam: overridden in tests so the audit can run against a fake store. */
    protected function shopifyFor(?Store $store): ShopifyService
    {
        return new ShopifyService($store);
    }

    public function failed(\Throwable $e): void
    {
        SeoAuditSession::where('id', $this->seoAuditSessionId)->update([
            'status'        => 'failed',
            'error_message' => $e->getMessage(),
        ]);
    }
}
