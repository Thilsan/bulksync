<?php

namespace App\Services;

use App\Models\Setting;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ShopifyService
{
    private Client $http;
    private string $shop;
    private string $token;
    private string $apiVersion = '2024-01';

    // Leaky-bucket tracking: Shopify allows 40-call burst, 2/s refill
    private static float $lastCallTime = 0.0;
    private static float $callBucket   = 40.0;
    private const BUCKET_MAX  = 40.0;
    private const BUCKET_RATE = 2.0;   // calls per second restored

    public function __construct()
    {
        $this->shop  = rtrim(Setting::get('shopify_domain', config('services.shopify.domain', '')), '/');
        $this->token = Setting::get('shopify_access_token', config('services.shopify.access_token', ''));

        $this->http = new Client([
            'timeout'  => 30,
            'base_uri' => "https://{$this->shop}/",
            'headers'  => [
                'X-Shopify-Access-Token' => $this->token,
                'Content-Type'           => 'application/json',
                'Accept'                 => 'application/json',
            ],
        ]);
    }

    // ── SKU Cache ─────────────────────────────────────────────────────────

    /**
     * Look up a variant by SKU using a process-local in-memory cache.
     * On first call, pre-warms from Laravel Cache (populated by warmSkuCache()).
     * Falls back to live API if cache is empty.
     */
    public function findVariantBySkuCached(string $sku): ?array
    {
        if (!$sku) {
            return null;
        }

        $cacheKey = $this->skuCacheKey();
        $map      = Cache::get($cacheKey); // ['SKU' => ['product_id' => ..., ...]]

        if ($map !== null) {
            return $map[$sku] ?? null;
        }

        // Cache not warmed — fall back to live lookup
        return $this->findVariantBySku($sku);
    }

    /**
     * Fetch every product variant from Shopify and build a SKU → product map.
     * Stores in Laravel Cache for 4 hours.
     * Call this once before dispatching ProcessUploadItemJob.
     *
     * A store with 10,000 products × 3 variants = 30,000 variants.
     * At 250/page → 120 API calls → ~60 s at 2 calls/s.
     */
    public function warmSkuCache(): int
    {
        Log::info('ShopifyService: warming SKU cache…');

        $map    = [];
        $cursor = null;
        $count  = 0;

        do {
            $this->throttle();

            $query  = ['limit' => 250, 'fields' => 'id,product_id,sku,title'];
            if ($cursor) {
                $query['page_info'] = $cursor;
            }

            try {
                $response = $this->http->get("admin/api/{$this->apiVersion}/variants.json", [
                    'query' => $query,
                ]);
            } catch (ClientException $e) {
                $this->handleClientException($e, 'warmSkuCache');
                break;
            }

            $data     = json_decode((string) $response->getBody(), true);
            $variants = $data['variants'] ?? [];

            foreach ($variants as $v) {
                if (!empty($v['sku'])) {
                    $map[$v['sku']] = [
                        'product_id'    => (string) $v['product_id'],
                        'product_title' => '',   // filled in the next step if needed
                        'variant_id'    => (string) $v['id'],
                        'variant_sku'   => $v['sku'],
                    ];
                    $count++;
                }
            }

            // Parse Link header for cursor-based pagination
            $cursor = $this->parseLinkCursor($response->getHeader('Link')[0] ?? '');

        } while ($cursor);

        // Fetch product titles in a separate pass (optional — batch request)
        $map = $this->backfillProductTitles($map);

        Cache::put($this->skuCacheKey(), $map, now()->addHours(4));

        Log::info("ShopifyService: SKU cache warmed — {$count} SKUs cached.");

        return $count;
    }

    public function clearSkuCache(): void
    {
        Cache::forget($this->skuCacheKey());
    }

    // ── Standard SKU lookup (used when cache is not warmed) ────────────────

    public function findVariantBySku(string $sku): ?array
    {
        if (!$sku) {
            return null;
        }

        try {
            $this->throttle();

            $response = $this->http->get("admin/api/{$this->apiVersion}/variants.json", [
                'query' => ['sku' => $sku, 'fields' => 'id,product_id,sku', 'limit' => 5],
            ]);

            $variants = json_decode((string) $response->getBody(), true)['variants'] ?? [];

            if (empty($variants)) {
                return null;
            }

            $v     = $variants[0];
            $title = $this->getProductTitle($v['product_id']);

            return [
                'product_id'    => (string) $v['product_id'],
                'product_title' => $title,
                'variant_id'    => (string) $v['id'],
                'variant_sku'   => $v['sku'],
            ];

        } catch (ClientException $e) {
            return $this->handleClientException($e, "findVariantBySku({$sku})");
        }
    }

    // ── Image upload ───────────────────────────────────────────────────────

    public function uploadImageToProduct(
        string $productId,
        string $imageContent,
        string $filename,
        string $altText = '',
    ): ?string {
        $this->throttle();

        try {
            $response = $this->http->post(
                "admin/api/{$this->apiVersion}/products/{$productId}/images.json",
                [
                    'json' => [
                        'image' => [
                            'attachment' => base64_encode($imageContent),
                            'filename'   => $filename,
                            'alt'        => $altText ?: pathinfo($filename, PATHINFO_FILENAME),
                        ],
                    ],
                ]
            );

            $data = json_decode((string) $response->getBody(), true);

            return isset($data['image']['id']) ? (string) $data['image']['id'] : null;

        } catch (ClientException $e) {
            $this->handleClientException($e, "uploadImageToProduct({$productId})");
            throw new \RuntimeException('Shopify image upload failed: ' . $e->getMessage());
        }
    }

    // ── Connection test ────────────────────────────────────────────────────

    public function testConnection(): bool
    {
        if (!$this->shop || !$this->token) {
            return false;
        }

        try {
            $response = $this->http->get("admin/api/{$this->apiVersion}/shop.json");
            return $response->getStatusCode() === 200;
        } catch (\Throwable $e) {
            Log::error('Shopify connection test failed: ' . $e->getMessage());
            return false;
        }
    }

    // ── Internals ──────────────────────────────────────────────────────────

    /**
     * Leaky-bucket rate limiter.
     * Shopify: 40-call burst, 2 calls/s refill.
     * Sleeps only when the bucket is empty.
     */
    private function throttle(): void
    {
        $now     = microtime(true);
        $elapsed = $now - self::$lastCallTime;

        // Restore tokens based on elapsed time
        self::$callBucket = min(
            self::BUCKET_MAX,
            self::$callBucket + $elapsed * self::BUCKET_RATE
        );
        self::$lastCallTime = $now;

        if (self::$callBucket >= 1.0) {
            self::$callBucket -= 1.0;
        } else {
            // Wait for 1 token to be available
            $waitMs = (int) ceil((1.0 - self::$callBucket) / self::BUCKET_RATE * 1000);
            usleep($waitMs * 1000);
            self::$callBucket = 0.0;
            self::$lastCallTime = microtime(true);
        }
    }

    /**
     * Handle 429 / 401 / other client errors.
     * Returns null (for lookup methods) so callers can decide to rethrow.
     */
    private function handleClientException(ClientException $e, string $context): mixed
    {
        $code = $e->getResponse()->getStatusCode();

        if ($code === 429) {
            $retryAfter = (int) ($e->getResponse()->getHeader('Retry-After')[0] ?? 2);
            Log::warning("Shopify 429 in {$context} — waiting {$retryAfter}s");
            sleep($retryAfter + 1);
            // Drain the bucket
            self::$callBucket = 0.0;
            return null;
        }

        Log::error("Shopify {$code} in {$context}: " . $e->getMessage());

        return null;
    }

    private function getProductTitle(int|string $productId): string
    {
        try {
            $this->throttle();
            $response = $this->http->get(
                "admin/api/{$this->apiVersion}/products/{$productId}.json",
                ['query' => ['fields' => 'id,title']]
            );
            return json_decode((string) $response->getBody(), true)['product']['title'] ?? 'Unknown';
        } catch (\Throwable) {
            return 'Unknown';
        }
    }

    private function backfillProductTitles(array $map): array
    {
        // Collect unique product IDs and fetch their titles in batches of 250
        $productIds = array_unique(array_column($map, 'product_id'));
        $titles     = [];

        foreach (array_chunk($productIds, 250) as $chunk) {
            try {
                $this->throttle();
                $response = $this->http->get("admin/api/{$this->apiVersion}/products.json", [
                    'query' => ['ids' => implode(',', $chunk), 'fields' => 'id,title', 'limit' => 250],
                ]);
                $products = json_decode((string) $response->getBody(), true)['products'] ?? [];
                foreach ($products as $p) {
                    $titles[(string) $p['id']] = $p['title'];
                }
            } catch (\Throwable $e) {
                Log::warning('ShopifyService: could not backfill titles: ' . $e->getMessage());
            }
        }

        foreach ($map as $sku => &$entry) {
            $entry['product_title'] = $titles[$entry['product_id']] ?? 'Unknown';
        }

        return $map;
    }

    /**
     * Parse Shopify's Link header for cursor-based pagination.
     * Returns the next page_info cursor, or null if no next page.
     */
    private function parseLinkCursor(string $linkHeader): ?string
    {
        if (!$linkHeader) {
            return null;
        }

        // Link: <https://...?page_info=abc&limit=250>; rel="next"
        if (preg_match('/<[^>]*[?&]page_info=([^&>]+)[^>]*>;\s*rel="next"/', $linkHeader, $m)) {
            return urldecode($m[1]);
        }

        return null;
    }

    private function skuCacheKey(): string
    {
        return 'shopify_sku_map_' . md5($this->shop);
    }
}
