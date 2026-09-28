<?php

namespace App\Jobs;

use App\Models\SeoAuditItem;
use App\Models\SeoAuditSession;
use App\Models\Store;
use App\Services\ShopifyService;
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
            'status'         => 'running',
            'total_products' => $shopify->getProductCount(),
        ]);

        Log::info("RunSeoAuditJob: starting audit for session {$session->id}");

        try {
            $buffer  = [];
            $scanned = 0;
            $counts  = [];

            $shopify->streamProductsForSeoAudit(function (array $products) use (
                $session, &$buffer, &$scanned, &$counts
            ) {
                foreach ($products as $product) {
                    $row = $this->evaluate($product);

                    foreach ($row['issues'] as $code) {
                        $counts[$code] = ($counts[$code] ?? 0) + 1;
                    }

                    // Assigned rather than merged: `+` keeps the left-hand
                    // key, so an overriding 'issues' in a second array would be
                    // silently dropped and the raw array handed to insert().
                    $row['seo_audit_session_id'] = $session->id;
                    $row['issues']               = json_encode($row['issues']);
                    $row['created_at']           = now();
                    $row['updated_at']           = now();

                    $buffer[] = $row;

                    $scanned++;
                }

                if (count($buffer) >= self::FLUSH_AT) {
                    SeoAuditItem::insert($buffer);
                    $buffer = [];
                }

                $session->update(['scanned_products' => $scanned]);
            });

            if (!empty($buffer)) {
                SeoAuditItem::insert($buffer);
            }

            // Duplicates are the one check a single product cannot answer about
            // itself, so they are resolved once the whole catalogue is in.
            $counts = $this->flagDuplicates($session, $counts);

            $this->summarise($session, $scanned, $counts);

            Log::info("RunSeoAuditJob: completed — {$scanned} products, " . array_sum($counts) . ' issues.');

        } catch (\Throwable $e) {
            Log::error('RunSeoAuditJob failed: ' . $e->getMessage());
            $session->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        }
    }

    /**
     * Grade one product. Returns a row ready for insert, with 'issues' still an
     * array — the caller encodes it after counting.
     */
    private function evaluate(array $product): array
    {
        $metaTitle = trim((string) ($product['meta_title'] ?? ''));
        $metaDesc  = trim((string) ($product['meta_description'] ?? ''));

        // Shopify's `description` is already the plain-text rendering of
        // body_html, so no tag stripping is needed — only the entities a
        // merchant's copy-paste leaves behind.
        $description = trim(html_entity_decode((string) ($product['description'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $images     = $product['images'] ?? [];
        $imageCount = count($images);
        $missingAlt = count(array_filter($images, fn ($i) => trim((string) ($i['alt'] ?? '')) === ''));
        $tagCount   = count(array_filter((array) ($product['tags'] ?? []), fn ($t) => trim((string) $t) !== ''));

        // mb_strlen, not strlen: an Arabic meta title is well within 60
        // characters while being far past 60 bytes, and byte lengths would
        // report every bilingual product as too long.
        $titleLength = mb_strlen($metaTitle);
        $descLength  = mb_strlen($metaDesc);

        $issues = [];

        if ($metaTitle === '') {
            $issues[] = 'missing_meta_title';
        } elseif ($titleLength > SeoAuditItem::META_TITLE_MAX) {
            $issues[] = 'meta_title_too_long';
        } elseif ($titleLength < SeoAuditItem::META_TITLE_MIN) {
            $issues[] = 'meta_title_too_short';
        }

        if ($metaDesc === '') {
            $issues[] = 'missing_meta_description';
        } elseif ($descLength > SeoAuditItem::META_DESC_MAX) {
            $issues[] = 'meta_description_too_long';
        } elseif ($descLength < SeoAuditItem::META_DESC_MIN) {
            $issues[] = 'meta_description_too_short';
        }

        if ($imageCount === 0) {
            $issues[] = 'no_images';
        } elseif ($missingAlt > 0) {
            $issues[] = 'missing_alt_text';
        }

        if (mb_strlen($description) < SeoAuditItem::THIN_DESCRIPTION) {
            $issues[] = 'thin_description';
        }

        if ($tagCount === 0) {
            $issues[] = 'no_tags';
        }

        return [
            'product_id'              => (string) ($product['id'] ?? ''),
            'product_title'           => mb_substr((string) ($product['title'] ?? ''), 0, 255),
            'handle'                  => mb_substr((string) ($product['handle'] ?? ''), 0, 255),
            'sku'                     => $product['sku'] ? mb_substr((string) $product['sku'], 0, 255) : null,
            'meta_title'              => mb_substr($metaTitle, 0, 512),
            'meta_description'        => mb_substr($metaDesc, 0, 1024),
            'meta_title_length'       => $titleLength,
            'meta_description_length' => $descLength,
            'description_length'      => mb_strlen($description),
            'image_count'             => min($imageCount, 65535),
            'images_missing_alt'      => min($missingAlt, 65535),
            'tag_count'               => min($tagCount, 65535),
            'issues'                  => $issues,
            'issue_count'             => count($issues),
            'score'                   => SeoAuditItem::scoreFor($issues),
        ];
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
            $duplicated = SeoAuditItem::where('seo_audit_session_id', $session->id)
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

    private function summarise(SeoAuditSession $session, int $scanned, array $counts): void
    {
        $base = SeoAuditItem::where('seo_audit_session_id', $session->id);

        $withIssues = (clone $base)->where('issue_count', '>', 0)->count();
        $average    = (clone $base)->avg('score');

        $session->update([
            'status'               => 'completed',
            'scanned_products'     => $scanned,
            'clean_products'       => $scanned - $withIssues,
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
