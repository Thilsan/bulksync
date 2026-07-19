<?php

namespace App\Services;

use App\Models\Store;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
     * Returns ALL variants matching the SKU across all products.
     * Each SKU is stored as its own small Redis key (not one giant blob)
     * to avoid PHP memory exhaustion during cache warming.
     */
    public function findVariantsBySkuCached(string $sku, bool $throwOnFailure = false): array
    {
        if (!$sku) {
            return [];
        }

        $gen = Cache::get($this->skuWarmSentinel());

        if ($gen === null) {
            // Cache not warmed — fall back to live lookup
            return $this->findVariantsBySku($sku, $throwOnFailure);
        }

        $cached = Cache::get($this->skuEntryKey($sku, $gen));

        // null here means cache is warmed but SKU not in Shopify
        return $cached ?? [];
    }

    /**
     * Returns ALL variants matching the barcode across all products.
     * Mirrors findVariantsBySkuCached but keyed by barcode instead of SKU.
     */
    public function findVariantsByBarcodeCached(string $barcode, bool $throwOnFailure = false): array
    {
        if (!$barcode) {
            return [];
        }

        $gen = Cache::get($this->skuWarmSentinel());

        if ($gen === null) {
            return $this->findVariantsByBarcode($barcode, $throwOnFailure);
        }

        $cached = Cache::get($this->barcodeEntryKey($barcode, $gen));

        return $cached ?? [];
    }

    /**
     * Look up by SKU first; if nothing matches, fall back to barcode.
     * Used by the bulk image upload flow, where OneDrive folders/filenames
     * are sometimes named after the item's barcode rather than its SKU.
     */
    public function findVariantsBySkuOrBarcodeCached(string $identifier, bool $throwOnFailure = false): array
    {
        $variants = $this->findVariantsBySkuCached($identifier, $throwOnFailure);

        if ($variants) {
            return $variants;
        }

        $variants = $this->findVariantsByBarcodeCached($identifier, $throwOnFailure);

        // Tag the fallback path so callers can report accurately (e.g. "Duplicate barcode" vs "Duplicate SKU")
        return array_map(fn ($v) => $v + ['matched_via' => 'barcode'], $variants);
    }

    /**
     * Fetch every variant from Shopify and store each SKU as its own Redis key.
     * Processes and stores 250 variants at a time so the PHP process never
     * holds a large array in memory — avoids OOM crashes on large stores.
     */
    public function warmSkuCache(): int
    {
        Log::info('ShopifyService: warming SKU cache…');

        // Previous generation's rows are about to become unreachable — nothing
        // ever reads them again, so the database cache driver's lazy expiry
        // (which only deletes a row when it's looked up) would never clean
        // them up on its own. Delete them explicitly once this warm finishes.
        $previousGen = Cache::get($this->skuWarmSentinel());

        // New generation timestamp makes stale keys from prior warmings unreachable
        $gen    = time();
        $ttl    = now()->addHours(4);
        $cursor = null;
        $count  = 0;
        $page   = 0;

        do {
            $this->throttle();

            $query = ['limit' => 250, 'fields' => 'id,title,published_at,variants'];
            if ($cursor) {
                $query['page_info'] = $cursor;
            }

            try {
                $response = $this->http->get("admin/api/{$this->apiVersion}/products.json", [
                    'query' => $query,
                ]);
            } catch (ClientException $e) {
                $this->handleClientException($e, 'warmSkuCache');
                break;
            }

            $products = json_decode((string) $response->getBody(), true)['products'] ?? [];

            // Accumulate only this page — freed at end of iteration
            $pageMap     = [];
            $barcodeMap  = [];
            foreach ($products as $product) {
                $published = !empty($product['published_at']);
                foreach ($product['variants'] ?? [] as $v) {
                    $entry = [
                        'product_id'    => (string) $product['id'],
                        'product_title' => $product['title'] ?? '',
                        'variant_id'    => (string) $v['id'],
                        'variant_sku'   => $v['sku'] ?? '',
                        'published'     => $published,
                    ];

                    if (!empty($v['sku'])) {
                        $pageMap[$v['sku']][] = $entry;
                        $count++;
                    }
                    if (!empty($v['barcode'])) {
                        $barcodeMap[$v['barcode']][] = $entry;
                    }
                }
            }

            // Store per-SKU — get+merge handles same SKU across multiple pages
            foreach ($pageMap as $sku => $newEntries) {
                $key      = $this->skuEntryKey($sku, $gen);
                $existing = Cache::get($key, []);
                Cache::put($key, array_merge($existing, $newEntries), $ttl);
            }
            unset($pageMap); // free page memory immediately

            // Store per-barcode — fallback lookup for identifiers that aren't SKUs
            foreach ($barcodeMap as $barcode => $newEntries) {
                $key      = $this->barcodeEntryKey($barcode, $gen);
                $existing = Cache::get($key, []);
                Cache::put($key, array_merge($existing, $newEntries), $ttl);
            }
            unset($barcodeMap);

            $page++;
            if ($page % 20 === 0) {
                Log::info("ShopifyService: warming SKU cache — {$count} variants processed…");
            }

            $cursor = $this->parseLinkCursor($response->getHeader('Link')[0] ?? '');

        } while ($cursor);

        // Sentinel stores the generation so findVariantsBySkuCached uses the right keys
        Cache::put($this->skuWarmSentinel(), $gen, $ttl);

        Log::info("ShopifyService: SKU cache warmed — {$count} variants, generation {$gen}.");

        if ($previousGen) {
            $this->purgeSkuCacheGeneration((int) $previousGen);
        }

        return $count;
    }

    /**
     * Delete every per-SKU/barcode row belonging to one old generation. Only
     * applies with the database cache driver — with any other driver (e.g.
     * Redis, which expires keys on its own) this is a no-op.
     */
    private function purgeSkuCacheGeneration(int $gen): void
    {
        if (config('cache.default') !== 'database') {
            return;
        }

        $connection = config('cache.stores.database.connection') ?: config('database.default');
        $table      = config('cache.stores.database.table', 'cache');
        $shopHash   = md5($this->shop);

        $deleted = DB::connection($connection)->table($table)
            ->where('key', 'like', "%shopify_sku_{$shopHash}_v{$gen}_%")
            ->orWhere('key', 'like', "%shopify_barcode_{$shopHash}_v{$gen}_%")
            ->delete();

        Log::info("ShopifyService: purged {$deleted} stale cache rows from generation {$gen}.");
    }

    public function isSkuCacheWarmed(): bool
    {
        return Cache::has($this->skuWarmSentinel());
    }

    public function clearSkuCache(): void
    {
        // Forgetting the sentinel makes all per-SKU keys unreachable immediately.
        // The old per-SKU keys (from the previous generation) expire naturally after 4 hours.
        Cache::forget($this->skuWarmSentinel());
    }

    /**
     * Fetch every SKU in the store and return as a hash-set (sku => true) for O(1) lookup.
     * Only retrieves the `sku` field per variant — the lightest possible bulk fetch.
     * Used by RunCsvCompareJob to compare an uploaded SKU list against the full catalogue.
     */
    public function getAllShopifySkuSet(): array
    {
        Log::info('ShopifyService: fetching all Shopify SKUs for CSV compare…');

        $skuSet = [];
        $cursor = null;
        $count  = 0;

        do {
            $this->throttle();

            $query = ['limit' => 250, 'fields' => 'sku'];
            if ($cursor) {
                $query['page_info'] = $cursor;
            }

            try {
                $response = $this->http->get("admin/api/{$this->apiVersion}/variants.json", [
                    'query' => $query,
                ]);
            } catch (ClientException $e) {
                $this->handleClientException($e, 'getAllShopifySkuSet');
                break;
            }

            $variants = json_decode((string) $response->getBody(), true)['variants'] ?? [];

            foreach ($variants as $v) {
                if (!empty($v['sku'])) {
                    $skuSet[$v['sku']] = true;
                    $count++;
                }
            }

            $cursor = $this->parseLinkCursor($response->getHeader('Link')[0] ?? '');

        } while ($cursor);

        Log::info("ShopifyService: fetched {$count} Shopify SKUs for CSV compare.");

        return $skuSet;
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
    public function findVariantsBySku(string $sku, bool $throwOnFailure = false): array
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
                        'query'     => 'query($q:String!){productVariants(first:250,query:$q){edges{node{id sku product{id title status vendor productType tags descriptionHtml collections(first:20){edges{node{title}}}}}}}}',
                        'variables' => ['q' => "sku:'{$sku}'"],
                    ],
                ]
            );

            $data  = json_decode((string) $response->getBody(), true);
            $edges = $data['data']['productVariants']['edges'] ?? [];

            return array_map(function ($edge) {
                $node        = $edge['node'];
                $variantId   = ltrim(str_replace('gid://shopify/ProductVariant/', '', $node['id']), '/');
                $productId   = ltrim(str_replace('gid://shopify/Product/', '', $node['product']['id']), '/');
                $collections = array_map(fn ($c) => $c['node']['title'] ?? '', $node['product']['collections']['edges'] ?? []);

                return [
                    'product_id'           => $productId,
                    'product_title'        => $node['product']['title'] ?? '',
                    'variant_id'           => $variantId,
                    'variant_sku'          => $node['sku'],
                    'published'            => ($node['product']['status'] ?? '') === 'ACTIVE',
                    'vendor'               => $node['product']['vendor'] ?? '',
                    'product_type'         => $node['product']['productType'] ?? '',
                    'tags'                 => $node['product']['tags'] ?? [],
                    'collections'          => array_filter($collections),
                    'existing_description' => $node['product']['descriptionHtml'] ?? '',
                ];
            }, $edges);

        } catch (\Throwable $e) {
            Log::error("Shopify findVariantsBySku({$sku}) GraphQL failed: " . $e->getMessage());
            if ($throwOnFailure) {
                throw new \RuntimeException("Shopify SKU lookup failed for {$sku}: " . $e->getMessage(), 0, $e);
            }
            return [];
        }
    }

    /**
     * Find a variant by exact barcode using the GraphQL Admin API.
     * Fallback for when the OneDrive folder/filename identifier doesn't
     * match any SKU — some catalogues are organised by barcode instead.
     */
    public function findVariantsByBarcode(string $barcode, bool $throwOnFailure = false): array
    {
        if (!$barcode) {
            return [];
        }

        $this->throttle();

        try {
            $response = $this->http->post(
                "admin/api/{$this->apiVersion}/graphql.json",
                [
                    'json' => [
                        'query'     => 'query($q:String!){productVariants(first:250,query:$q){edges{node{id sku barcode product{id title status vendor productType tags collections(first:20){edges{node{title}}}}}}}}',
                        'variables' => ['q' => "barcode:'{$barcode}'"],
                    ],
                ]
            );

            $data  = json_decode((string) $response->getBody(), true);
            $edges = $data['data']['productVariants']['edges'] ?? [];

            return array_map(function ($edge) {
                $node        = $edge['node'];
                $variantId   = ltrim(str_replace('gid://shopify/ProductVariant/', '', $node['id']), '/');
                $productId   = ltrim(str_replace('gid://shopify/Product/', '', $node['product']['id']), '/');
                $collections = array_map(fn ($c) => $c['node']['title'] ?? '', $node['product']['collections']['edges'] ?? []);

                return [
                    'product_id'    => $productId,
                    'product_title' => $node['product']['title'] ?? '',
                    'variant_id'    => $variantId,
                    'variant_sku'   => $node['sku'],
                    'published'     => ($node['product']['status'] ?? '') === 'ACTIVE',
                    'vendor'        => $node['product']['vendor'] ?? '',
                    'product_type'  => $node['product']['productType'] ?? '',
                    'tags'          => $node['product']['tags'] ?? [],
                    'collections'   => array_filter($collections),
                ];
            }, $edges);

        } catch (\Throwable $e) {
            Log::error("Shopify findVariantsByBarcode({$barcode}) GraphQL failed: " . $e->getMessage());
            if ($throwOnFailure) {
                throw new \RuntimeException("Shopify barcode lookup failed for {$barcode}: " . $e->getMessage(), 0, $e);
            }
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
     * Add a single new variant to a product that already exists in this
     * store — used when a sibling SKU of the same source product was
     * migrated in an earlier run, so we're filling in another colour/size
     * on it rather than creating a duplicate product. Shopify auto-adds any
     * new option value (e.g. a colour this product didn't have yet here)
     * onto the product's options.
     */
    public function addVariantToProduct(string $productId, array $variantData): ?string
    {
        $this->throttle();

        $payload = array_filter($variantData, fn ($val) => $val !== null);

        try {
            $response = $this->http->post(
                "admin/api/{$this->apiVersion}/products/{$productId}/variants.json",
                ['json' => ['variant' => $payload]]
            );

            $data = json_decode((string) $response->getBody(), true);

            return isset($data['variant']['id']) ? (string) $data['variant']['id'] : null;

        } catch (ClientException $e) {
            $this->handleClientException($e, "addVariantToProduct({$productId})");
            throw new \RuntimeException('Shopify variant creation failed: ' . $e->getMessage());
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
                ['query' => ['fields' => 'id,src,alt,position,variant_ids', 'limit' => 250]]
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
     * Fetch the photos explicitly assigned to ONE variant via Shopify's
     * variant media feature — the same list shown on that variant's own
     * edit page in Shopify Admin. This is the authoritative source for
     * "this colour's photos"; a colour can have several, and they are not
     * reliably reconstructable from the classic image_id/variant_ids REST
     * fields alone.
     */
    public function getVariantMedia(string $variantId): array
    {
        $this->throttle();

        $gid = "gid://shopify/ProductVariant/{$variantId}";

        try {
            $response = $this->http->post("admin/api/{$this->apiVersion}/graphql.json", [
                'json' => [
                    'query' => 'query($id: ID!) {
                        productVariant(id: $id) {
                            media(first: 250) {
                                edges {
                                    node {
                                        id
                                        ... on MediaImage {
                                            image { url altText }
                                        }
                                    }
                                }
                            }
                        }
                    }',
                    'variables' => ['id' => $gid],
                ],
            ]);

            $edges = json_decode((string) $response->getBody(), true)['data']['productVariant']['media']['edges'] ?? [];

            return array_values(array_filter(array_map(function ($edge) {
                $node = $edge['node'] ?? [];
                $url  = $node['image']['url'] ?? null;
                if (!$url) return null;

                return [
                    'id'  => ltrim(str_replace('gid://shopify/MediaImage/', '', $node['id'] ?? ''), '/'),
                    'src' => $url,
                    'alt' => $node['image']['altText'] ?? '',
                ];
            }, $edges)));
        } catch (\Throwable $e) {
            Log::warning("Shopify getVariantMedia({$variantId}): " . $e->getMessage());
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

    /**
     * Tag an already-uploaded image with one or more variants, via the
     * Images API. This is how a color's whole photo gallery (not just its
     * single swatch image) gets carried over — Shopify lets many images
     * share the same variant_ids.
     */
    public function setImageVariants(string $productId, string $imageId, array $variantIds): void
    {
        $this->throttle();

        $this->http->put(
            "admin/api/{$this->apiVersion}/products/{$productId}/images/{$imageId}.json",
            [
                'json' => [
                    'image' => [
                        'id'          => (int) $imageId,
                        'variant_ids' => array_map('intval', $variantIds),
                    ],
                ],
            ]
        );
    }

    // ── Full product migration ──────────────────────────────────────────────

    /**
     * Fetch everything needed to recreate this product in another store:
     * core fields, options, every variant (price/inventory/SKU/barcode/weight),
     * and every image. One REST call returns almost all of it.
     */
    public function getFullProduct(string $productId): ?array
    {
        $this->throttle();

        try {
            $response = $this->http->get("admin/api/{$this->apiVersion}/products/{$productId}.json");
            $product  = json_decode((string) $response->getBody(), true)['product'] ?? null;
            if (!$product) return null;

            $product['images'] = $product['images'] ?? [];
            usort($product['images'], fn ($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));

            return $product;
        } catch (\Throwable $e) {
            Log::error("Shopify getFullProduct({$productId}): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a brand-new product in this store from a full product payload
     * fetched via getFullProduct() on the source store. Always created as a
     * draft — never published automatically. Copies price/inventory as-is,
     * keeps each variant's image linked to the correct photo (source and
     * target get different image IDs, so this maps them by position after
     * creation).
     *
     * @return array{product_id: string, image_map: array<string,string>} Shopify product ID and old→new image ID map
     */
    public function createFullProduct(array $sourceProduct): array
    {
        $this->throttle();

        $variants = array_map(function ($v) {
            return array_filter([
                'sku'                 => $v['sku'] ?? null,
                'price'               => $v['price'] ?? null,
                'compare_at_price'    => $v['compare_at_price'] ?? null,
                'inventory_quantity'  => $v['inventory_quantity'] ?? 0,
                'inventory_management' => $v['inventory_management'] ?? null,
                'weight'              => $v['weight'] ?? null,
                'weight_unit'         => $v['weight_unit'] ?? null,
                'barcode'             => $v['barcode'] ?? null,
                'option1'             => $v['option1'] ?? null,
                'option2'             => $v['option2'] ?? null,
                'option3'             => $v['option3'] ?? null,
            ], fn ($val) => $val !== null);
        }, $sourceProduct['variants'] ?? []);

        $images = array_map(fn ($img) => array_filter([
            'src' => $img['src'] ?? null,
            'alt' => $img['alt'] ?? null,
        ]), $sourceProduct['images'] ?? []);

        $payload = array_filter([
            'title'        => $sourceProduct['title'] ?? '',
            'body_html'    => $sourceProduct['body_html'] ?? '',
            'vendor'       => $sourceProduct['vendor'] ?? '',
            'product_type' => $sourceProduct['product_type'] ?? '',
            'tags'         => $sourceProduct['tags'] ?? '',
            'status'       => 'draft', // never auto-publish a migrated product
            'options'      => $sourceProduct['options'] ?? [],
            'variants'     => $variants,
            'images'       => $images,
        ]);

        // Longer timeout than the client default (30s) — Shopify fetches every image
        // src URL server-side before responding, which can take a while for products
        // with several images.
        $response    = $this->http->post("admin/api/{$this->apiVersion}/products.json", [
            'json'    => ['product' => $payload],
            'timeout' => 120,
        ]);
        $newProduct  = json_decode((string) $response->getBody(), true)['product'] ?? [];
        $newProductId = (string) ($newProduct['id'] ?? '');

        // Map source image position → new image ID, so variant image links can be recreated
        $sourceImages = $sourceProduct['images'] ?? [];
        usort($sourceImages, fn ($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));

        $newImages = $newProduct['images'] ?? [];
        usort($newImages, fn ($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));

        $imageMap = [];
        foreach ($sourceImages as $index => $sourceImage) {
            if (isset($newImages[$index]['id'])) {
                $imageMap[(string) $sourceImage['id']] = (string) $newImages[$index]['id'];
            }
        }

        // Map source variant ID → new variant ID (position-based, same order in both arrays)
        $newVariants  = $newProduct['variants'] ?? [];
        $variantIdMap = [];
        foreach ($sourceProduct['variants'] ?? [] as $index => $sourceVariant) {
            if (isset($newVariants[$index]['id'])) {
                $variantIdMap[(string) $sourceVariant['id']] = (string) $newVariants[$index]['id'];
            }
        }

        // Re-tag every image with the same variant(s) it belonged to on the source
        // product, so a color's whole photo gallery carries over — not just its
        // single swatch image.
        foreach ($sourceImages as $sourceImage) {
            $newImageId       = $imageMap[(string) ($sourceImage['id'] ?? '')] ?? null;
            $sourceVariantIds = $sourceImage['variant_ids'] ?? [];

            if (!$newImageId || empty($sourceVariantIds)) continue;

            $newVariantIds = array_values(array_filter(array_map(
                fn ($vid) => $variantIdMap[(string) $vid] ?? null,
                $sourceVariantIds
            )));

            if (empty($newVariantIds)) continue;

            try {
                $this->setImageVariants($newProductId, $newImageId, $newVariantIds);
            } catch (\Throwable $e) {
                Log::warning("Shopify createFullProduct: failed to tag image {$newImageId} to variants: " . $e->getMessage());
            }
        }

        // Explicitly set each variant's primary swatch image (some themes read variant.image_id directly)
        foreach ($sourceProduct['variants'] ?? [] as $index => $sourceVariant) {
            $sourceImageId = $sourceVariant['image_id'] ?? null;
            $newVariantId  = $newVariants[$index]['id'] ?? null;

            if ($sourceImageId && $newVariantId && isset($imageMap[(string) $sourceImageId])) {
                try {
                    $this->setVariantImage((string) $newVariantId, $imageMap[(string) $sourceImageId]);
                } catch (\Throwable $e) {
                    Log::warning("Shopify createFullProduct: failed to link variant {$newVariantId} image: " . $e->getMessage());
                }
            }
        }

        return ['product_id' => $newProductId, 'image_map' => $imageMap];
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

    // ── Metafield update ───────────────────────────────────────────────────

    /**
     * Read the product's current custom.features list so new CSV features
     * can be merged in (append-if-new) rather than overwriting the whole list.
     */
    private function getProductFeatures(string $productId): array
    {
        $this->throttle();

        $gid = "gid://shopify/Product/{$productId}";

        try {
            $response = $this->http->post("admin/api/{$this->apiVersion}/graphql.json", [
                'json' => [
                    'query'     => 'query($id:ID!){product(id:$id){metafield(namespace:"custom",key:"features"){value}}}',
                    'variables' => ['id' => $gid],
                ],
            ]);

            $data  = json_decode((string) $response->getBody(), true);
            $value = $data['data']['product']['metafield']['value'] ?? null;

            if (!$value) return [];

            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
            Log::warning("Shopify getProductFeatures({$productId}): " . $e->getMessage());
            return [];
        }
    }

    /**
     * Read the product's existing custom.material and custom.features
     * metafields (if set) so AI Content generation can use them as confirmed
     * facts instead of visually guessing material from the photo.
     *
     * @return array{material: string, features: array}
     */
    public function getProductMaterialAndFeatures(string $productId): array
    {
        $this->throttle();

        $gid = "gid://shopify/Product/{$productId}";

        try {
            $response = $this->http->post("admin/api/{$this->apiVersion}/graphql.json", [
                'json' => [
                    'query'     => 'query($id:ID!){product(id:$id){material:metafield(namespace:"custom",key:"material"){value} features:metafield(namespace:"custom",key:"features"){value}}}',
                    'variables' => ['id' => $gid],
                ],
            ]);

            $data    = json_decode((string) $response->getBody(), true);
            $product = $data['data']['product'] ?? [];

            $material     = $product['material']['value'] ?? '';
            $featuresJson = $product['features']['value'] ?? null;
            $features     = $featuresJson ? json_decode($featuresJson, true) : [];

            return [
                'material'  => is_string($material) ? $material : '',
                'features'  => is_array($features) ? $features : [],
            ];
        } catch (\Throwable $e) {
            Log::warning("Shopify getProductMaterialAndFeatures({$productId}): " . $e->getMessage());
            return ['material' => '', 'features' => []];
        }
    }

    public function updateProductMetafields(string $productId, string $material, string $features): void
    {
        $metafields = [];

        if ($material !== '') {
            $metafields[] = [
                'namespace' => 'custom',
                'key'       => 'material',
                'value'     => $material,
                'type'      => 'single_line_text_field',
            ];
        }

        if ($features !== '') {
            $newItems      = array_filter(array_map('trim', explode(',', $features)));
            $existingItems = $this->getProductFeatures($productId);

            $merged = $existingItems;
            foreach ($newItems as $item) {
                $alreadyPresent = collect($existingItems)->contains(fn ($e) => strcasecmp(trim($e), $item) === 0);
                if (!$alreadyPresent) {
                    $merged[] = $item;
                }
            }

            $metafields[] = [
                'namespace' => 'custom',
                'key'       => 'features',
                'value'     => json_encode(array_values($merged)),
                'type'      => 'list.single_line_text_field',
            ];
        }

        if (empty($metafields)) return;

        $this->throttle();

        $gid = "gid://shopify/Product/{$productId}";

        $this->http->post("admin/api/{$this->apiVersion}/graphql.json", [
            'json' => [
                'query' => 'mutation($input:ProductInput!){productUpdate(input:$input){product{id}userErrors{field message}}}',
                'variables' => [
                    'input' => [
                        'id'         => $gid,
                        'metafields' => $metafields,
                    ],
                ],
            ],
        ]);
    }

    // ── AI Content update ──────────────────────────────────────────────────

    public function updateProductContent(string $productId, string $description, string $metaTitle, string $metaDescription, string $title = ''): void
    {
        $product = ['id' => (int) $productId, 'body_html' => $description];
        if ($title !== '') {
            $product['title'] = $title;
        }

        $this->throttle();
        $this->http->put("admin/api/{$this->apiVersion}/products/{$productId}.json", [
            'json' => ['product' => $product],
        ]);

        if ($metaTitle) {
            $this->throttle();
            $this->http->post("admin/api/{$this->apiVersion}/products/{$productId}/metafields.json", [
                'json' => ['metafield' => ['namespace' => 'global', 'key' => 'title_tag', 'value' => $metaTitle, 'type' => 'single_line_text_field']],
            ]);
        }

        if ($metaDescription) {
            $this->throttle();
            $this->http->post("admin/api/{$this->apiVersion}/products/{$productId}/metafields.json", [
                'json' => ['metafield' => ['namespace' => 'global', 'key' => 'description_tag', 'value' => $metaDescription, 'type' => 'single_line_text_field']],
            ]);
        }
    }

    /**
     * Fetch up to 250 of the store's collections (title + numeric ID) so AI
     * Content can suggest ONLY real, existing collections for a product —
     * never invent fictional collection names.
     */
    public function getAllCollectionTitles(): array
    {
        $this->throttle();

        try {
            $response = $this->http->post("admin/api/{$this->apiVersion}/graphql.json", [
                'json' => [
                    'query' => 'query{collections(first:250){edges{node{id title}}}}',
                ],
            ]);

            $data  = json_decode((string) $response->getBody(), true);
            $edges = $data['data']['collections']['edges'] ?? [];

            return array_map(fn ($edge) => [
                'id'    => ltrim(str_replace('gid://shopify/Collection/', '', $edge['node']['id']), '/'),
                'title' => $edge['node']['title'] ?? '',
            ], $edges);
        } catch (\Throwable $e) {
            Log::warning('Shopify getAllCollectionTitles: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Add new tags to a product WITHOUT removing any existing tags — fetches
     * the current tag list fresh (it may have changed since generation) and
     * merges in only genuinely new ones (case-insensitive dedupe).
     */
    public function addProductTags(string $productId, array $newTags): void
    {
        if (empty($newTags)) return;

        $this->throttle();

        try {
            $response = $this->http->get("admin/api/{$this->apiVersion}/products/{$productId}.json", [
                'query' => ['fields' => 'tags'],
            ]);
            $data = json_decode((string) $response->getBody(), true);
            $existingTags = array_filter(array_map('trim', explode(',', $data['product']['tags'] ?? '')));
        } catch (\Throwable $e) {
            Log::warning("Shopify addProductTags({$productId}) fetch failed: " . $e->getMessage());
            $existingTags = [];
        }

        $merged = $existingTags;
        foreach ($newTags as $tag) {
            $tag = trim($tag);
            if (!$tag) continue;
            $alreadyPresent = collect($existingTags)->contains(fn ($e) => strcasecmp(trim($e), $tag) === 0);
            if (!$alreadyPresent) {
                $merged[] = $tag;
            }
        }

        $this->throttle();

        $this->http->put("admin/api/{$this->apiVersion}/products/{$productId}.json", [
            'json' => ['product' => ['id' => (int) $productId, 'tags' => implode(', ', $merged)]],
        ]);
    }

    /**
     * Add a product to existing Shopify collections by title — never creates
     * new collections, only matches against the real list already in the
     * store. Smart/automated collections will silently no-op (Shopify manages
     * their membership by rule, not manual assignment) — logged, not thrown.
     */
    public function addProductToCollections(string $productId, array $collectionTitles, array $allCollections): void
    {
        if (empty($collectionTitles)) return;

        $gid = "gid://shopify/Product/{$productId}";

        foreach ($collectionTitles as $title) {
            $match = collect($allCollections)->first(fn ($c) => strcasecmp(trim($c['title']), trim($title)) === 0);
            if (!$match) continue;

            try {
                $this->throttle();

                $collectionGid = "gid://shopify/Collection/{$match['id']}";
                $this->http->post("admin/api/{$this->apiVersion}/graphql.json", [
                    'json' => [
                        'query'     => 'mutation($id:ID!,$productIds:[ID!]!){collectionAddProducts(id:$id,productIds:$productIds){userErrors{field message}}}',
                        'variables' => ['id' => $collectionGid, 'productIds' => [$gid]],
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::warning("Shopify addProductToCollections({$productId}, {$title}): " . $e->getMessage());
            }
        }
    }

    public function updateImageAlt(string $productId, string $imageId, string $altText): void
    {
        $this->throttle();
        $this->http->put("admin/api/{$this->apiVersion}/products/{$productId}/images/{$imageId}.json", [
            'json' => ['image' => ['id' => (int) $imageId, 'alt' => $altText]],
        ]);
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

    private function skuWarmSentinel(): string
    {
        return 'shopify_sku_warmed_' . md5($this->shop);
    }

    private function skuEntryKey(string $sku, int $gen): string
    {
        return 'shopify_sku_' . md5($this->shop) . '_v' . $gen . '_' . md5($sku);
    }

    private function barcodeEntryKey(string $barcode, int $gen): string
    {
        return 'shopify_barcode_' . md5($this->shop) . '_v' . $gen . '_' . md5($barcode);
    }
}
