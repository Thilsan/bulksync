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
                    $collect($this->evaluateProduct($product));
                    $products++;
                }

                $session->update(['scanned_products' => $products]);
            });

            // Collections after products, and in their own pass: there are only
            // ever a few dozen, and a failure here should not cost the far
            // longer product scan that has already finished.
            $shopify->streamCollectionsForSeoAudit(function (array $page) use ($session, $collect, &$collections) {
                foreach ($page as $collection) {
                    $collect($this->evaluateCollection($collection));
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
     * Grade one product. Returns a row ready for insert, with 'issues' still an
     * array — the caller encodes it after counting.
     */
    private function evaluateProduct(array $product): array
    {
        $images     = $product['images'] ?? [];
        $imageCount = count($images);
        $missingAlt = count(array_filter($images, fn ($i) => trim((string) ($i['alt'] ?? '')) === ''));
        $tagCount   = count(array_filter((array) ($product['tags'] ?? []), fn ($t) => trim((string) $t) !== ''));

        $row = $this->gradeMeta($product, SeoAuditItem::TYPE_PRODUCT);

        if ($imageCount === 0) {
            $row['issues'][] = 'no_images';
        } elseif ($missingAlt > 0) {
            $row['issues'][] = 'missing_alt_text';
        }

        if ($tagCount === 0) {
            $row['issues'][] = 'no_tags';
        }

        // A draft or archived product is not on the storefront, so it is still
        // graded and kept — it will be published one day — but left out of the
        // headline figures, which are a statement about pages a search engine
        // can actually see.
        $row['status']             = $product['status'] ?: SeoAuditItem::STATUS_ACTIVE;
        $row['sku']                = $product['sku'] ? mb_substr((string) $product['sku'], 0, 255) : null;
        $row['image_count']        = min($imageCount, 65535);
        $row['images_missing_alt'] = min($missingAlt, 65535);
        $row['tag_count']          = min($tagCount, 65535);

        return $this->finalise($row);
    }

    /**
     * Grade one collection.
     *
     * The image, alt-text and tag checks are left off on purpose rather than
     * passing trivially: a collection has no gallery and no tags, and reporting
     * every one of them as "no images" would bury the products that genuinely
     * have none.
     */
    private function evaluateCollection(array $collection): array
    {
        return $this->finalise($this->gradeMeta($collection, SeoAuditItem::TYPE_COLLECTION));
    }

    /**
     * The checks both kinds of page share: the meta fields, the body copy, and
     * whether the visible title says what the thing actually is.
     */
    private function gradeMeta(array $resource, string $type): array
    {
        $metaTitle = trim((string) ($resource['meta_title'] ?? ''));
        $metaDesc  = trim((string) ($resource['meta_description'] ?? ''));
        $title     = trim((string) ($resource['title'] ?? ''));

        // Shopify's `description` is already the plain-text rendering of
        // body_html, so no tag stripping is needed — only the entities a
        // merchant's copy-paste leaves behind.
        $description = trim(html_entity_decode((string) ($resource['description'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        // mb_strlen, not strlen: an Arabic meta title is well within 60
        // characters while being far past 60 bytes, and byte lengths would
        // report every bilingual page as too long.
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

        if (mb_strlen($description) < SeoAuditItem::THIN_DESCRIPTION) {
            $issues[] = 'thin_description';
        }

        // A bare model name — "NOBLETON", "Altra", "BFF" — tells a search engine
        // nothing about what the thing is, and a search engine is the one reader
        // who has not already seen the photograph.
        if ($title !== '' && !preg_match('/\s/u', $title)) {
            $issues[] = 'title_single_word';
        }

        return [
            'resource_type'           => $type,
            // Collections have no status in Shopify and are always reachable.
            'status'                  => null,
            'product_id'              => (string) ($resource['id'] ?? ''),
            'product_title'           => mb_substr($title, 0, 255),
            'handle'                  => mb_substr((string) ($resource['handle'] ?? ''), 0, 255),
            'sku'                     => null,
            'meta_title'              => mb_substr($metaTitle, 0, 512),
            'meta_description'        => mb_substr($metaDesc, 0, 1024),
            'meta_title_length'       => $titleLength,
            'meta_description_length' => $descLength,
            'description_length'      => mb_strlen($description),
            // Kept so the screen can show what Shopify falls back to when no
            // meta description was ever written.
            'description_excerpt'     => mb_substr($description, 0, 200),
            'image_count'             => 0,
            'images_missing_alt'      => 0,
            'tag_count'               => 0,
            'issues'                  => $issues,
        ];
    }

    /** Scores the row once every check has had its say. */
    private function finalise(array $row): array
    {
        $row['issue_count'] = count($row['issues']);
        $row['score']       = SeoAuditItem::scoreFor($row['issues']);

        return $row;
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
