<?php

namespace App\Services;

use App\Models\Store;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
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

    public function __construct(?Store $store = null)
    {
        $target = $store ?? Store::getActive();

        $this->shop  = rtrim($target?->shopify_domain ?? config('services.shopify.domain', ''), '/');
        $this->token = $target?->shopify_access_token ?? config('services.shopify.access_token', '');

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
    /**
     * Returns ALL variants matching the SKU across all products.
     * One SKU can exist on multiple products — this returns every match.
     */
    public function findVariantsBySkuCached(string $sku): array
    {
        if (!$sku) {
            return [];
        }

        $cacheKey = $this->skuCacheKey();
        $map      = Cache::get($cacheKey); // ['SKU' => [['product_id' => ...], ...]]

        if ($map !== null && isset($map[$sku])) {
            return $map[$sku];
        }

        // SKU not in cache (or cache not warmed) — fall back to live lookup
        return $this->findVariantsBySku($sku);
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
                    $map[$v['sku']][] = [
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

        Cache::put($this->skuCacheKey(), $map, now()->addHours(4));

        Log::info("ShopifyService: SKU cache warmed — {$count} SKUs cached.");

        return $count;
    }

    public function isSkuCacheWarmed(): bool
    {
        return Cache::has($this->skuCacheKey());
    }

    public function clearSkuCache(): void
    {
        Cache::forget($this->skuCacheKey());
    }

    public function getProductCount(): int
    {
        try {
            $response = $this->http->get("admin/api/{$this->apiVersion}/products/count.json");
            $data     = json_decode((string) $response->getBody(), true);
            return (int) ($data['count'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    // ── Standard SKU lookup (used when cache is not warmed) ────────────────

    /**
     * Find a variant by exact SKU using the GraphQL Admin API.
     * The REST GET /variants.json?sku= endpoint silently ignores the sku
     * filter and returns unrelated variants, so GraphQL is the reliable path.
     */
    public function findVariantsBySku(string $sku): array
    {
        if (!$sku) {
            return [];
        }

        $this->throttle();

        try {
            $response = $this->http->post(
                "admin/api/{$this->apiVersion}/graphql.json",
                [
                    'json' => [
                        'query'     => 'query($q:String!){productVariants(first:250,query:$q){edges{node{id sku product{id title}}}}}',
                        'variables' => ['q' => "sku:'{$sku}'"],
                    ],
                ]
            );

            $data  = json_decode((string) $response->getBody(), true);
            $edges = $data['data']['productVariants']['edges'] ?? [];

            return array_map(function ($edge) {
                $node      = $edge['node'];
                $variantId = ltrim(str_replace('gid://shopify/ProductVariant/', '', $node['id']), '/');
                $productId = ltrim(str_replace('gid://shopify/Product/', '', $node['product']['id']), '/');
                return [
                    'product_id'    => $productId,
                    'product_title' => $node['product']['title'],
                    'variant_id'    => $variantId,
                    'variant_sku'   => $node['sku'],
                ];
            }, $edges);

        } catch (\Throwable $e) {
            Log::error("Shopify findVariantsBySku({$sku}) GraphQL failed: " . $e->getMessage());
            return [];
        }
    }

    // ── Image upload ───────────────────────────────────────────────────────

    public function uploadImageToProduct(
        string $productId,
        string $imageContent,
        string $filename,
        string $altText = '',
        ?string $variantId = null,
    ): ?string {
        $this->throttle();

        $imageData = [
            'attachment' => base64_encode($imageContent),
            'filename'   => $filename,
            'alt'        => $altText ?: pathinfo($filename, PATHINFO_FILENAME),
        ];

        // Including variant_ids in the payload is the first attempt to link the image.
        if ($variantId) {
            $imageData['variant_ids'] = [(int) $variantId];
        }

        try {
            $response = $this->http->post(
                "admin/api/{$this->apiVersion}/products/{$productId}/images.json",
                ['json' => ['image' => $imageData]]
            );

            $data = json_decode((string) $response->getBody(), true);

            return isset($data['image']['id']) ? (string) $data['image']['id'] : null;

        } catch (ClientException $e) {
            $this->handleClientException($e, "uploadImageToProduct({$productId})");
            throw new \RuntimeException('Shopify image upload failed: ' . $e->getMessage());
        }
    }

    /**
     * Return all images for a product, fields: id, alt, position.
     * Results are sorted by position (Shopify's natural order).
     */
    public function getProductImages(string $productId): array
    {
        $this->throttle();
        try {
            $response = $this->http->get(
                "admin/api/{$this->apiVersion}/products/{$productId}/images.json",
                ['query' => ['fields' => 'id,alt,position', 'limit' => 250]]
            );
            $images = json_decode((string) $response->getBody(), true)['images'] ?? [];
            usort($images, fn ($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));
            return $images;
        } catch (\Throwable $e) {
            Log::warning("Shopify getProductImages({$productId}): " . $e->getMessage());
            return [];
        }
    }

    /**
     * Delete a single product image by image ID.
     */
    public function deleteProductImage(string $productId, string $imageId): void
    {
        $this->throttle();
        try {
            $this->http->delete(
                "admin/api/{$this->apiVersion}/products/{$productId}/images/{$imageId}.json"
            );
            Log::info("Shopify: deleted image {$imageId} from product {$productId}");
        } catch (\Throwable $e) {
            Log::warning("Shopify deleteProductImage({$productId}, {$imageId}): " . $e->getMessage());
        }
    }

    /**
     * Explicitly set a variant's image_id via the Variants API.
     * Used as a second pass after uploadImageToProduct to guarantee the
     * variant picker shows the correct image.
     */
    public function setVariantImage(string $variantId, string $imageId): void
    {
        $this->throttle();

        $this->http->put(
            "admin/api/{$this->apiVersion}/variants/{$variantId}.json",
            [
                'json' => [
                    'variant' => [
                        'id'       => (int) $variantId,
                        'image_id' => (int) $imageId,
                    ],
                ],
            ]
        );
        Log::info("Shopify: variant {$variantId} image_id set to {$imageId}");
    }

    // ── Image Audit ────────────────────────────────────────────────────────

    /**
     * Stream all products (id, title, variants, images) page by page.
     * Calls $callback with each page array of products.
     */
    public function streamProductsForAudit(callable $callback): void
    {
        $cursor = null;

        do {
            $this->throttle();

            $query = ['limit' => 250, 'fields' => 'id,title,variants,images'];
            if ($cursor) {
                $query['page_info'] = $cursor;
                unset($query['fields']); // fields not allowed with page_info
            }

            try {
                $response = $this->http->get("admin/api/{$this->apiVersion}/products.json", ['query' => $query]);
            } catch (ClientException $e) {
                $this->handleClientException($e, 'streamProductsForAudit');
                break;
            }

            $products = json_decode((string) $response->getBody(), true)['products'] ?? [];
            if (!empty($products)) {
                $callback($products);
            }

            $cursor = $this->parseLinkCursor($response->getHeader('Link')[0] ?? '');
        } while ($cursor);
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
        // Flatten all entries to collect unique product IDs
        $productIds = [];
        foreach ($map as $entries) {
            foreach ($entries as $entry) {
                $productIds[$entry['product_id']] = true;
            }
        }
        $productIds = array_keys($productIds);
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

        foreach ($map as $sku => &$entries) {
            foreach ($entries as &$entry) {
                $entry['product_title'] = $titles[$entry['product_id']] ?? 'Unknown';
            }
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
