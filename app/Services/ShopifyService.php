<?php

namespace App\Services;

use App\Exceptions\ShopifyRequestException;
use App\Models\Store;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Carbon;
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

    /**
     * SKUs per batched lookup, and the page size those batches ask for. Fifty
     * leaves room for five variants each inside a 250-variant page; a batch that
     * fills the page anyway is split rather than trusted.
     */
    private const SKU_BATCH      = 50;
    private const SKU_BATCH_PAGE = 250;

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


    /**
     * Find a variant by exact SKU using the GraphQL Admin API.
     * The REST GET /variants.json?sku= endpoint silently ignores the sku
     * filter and returns unrelated variants, so GraphQL is the reliable path.
     */
    public function findVariantsBySku(string $sku, bool $throwOnFailure = false, bool $lean = false): array
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
                        'query'     => $this->variantLookupQuery('sku', $lean),
                        'variables' => ['q' => "sku:'{$sku}'"],
                    ],
                ]
            );

            $data = json_decode((string) $response->getBody(), true);
            $this->assertNoGraphQlErrors($data, "findVariantsBySku({$sku})");

            $edges = $data['data']['productVariants']['edges'] ?? [];

            return array_map(function ($edge) {
                $node        = $edge['node'];
                $variantId   = ltrim(str_replace('gid://shopify/ProductVariant/', '', $node['id']), '/');
                $productId   = ltrim(str_replace('gid://shopify/Product/', '', $node['product']['id']), '/');
                $collections = array_map(fn ($c) => $c['node']['title'] ?? '', $node['product']['collections']['edges'] ?? []);

                return [
                    'product_id'           => $productId,
                    'product_title'        => $node['product']['title'] ?? '',
                    'handle'               => $node['product']['handle'] ?? '',
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
     * Look up many SKUs live, in as few calls as possible: sku => variants.
     *
     * The single-SKU form above costs one API call per SKU, which is what pushed
     * long lists into warming the whole catalogue instead — reading every variant
     * in the store to answer a few hundred questions, and then trusting a cache
     * that can be evicted from underneath the answer. Shopify's variant search
     * accepts OR, so a batch of SKUs travels in one query and nothing here is
     * cached: every answer comes from Shopify as it stands right now.
     *
     * Matches are attributed by the `sku` Shopify returns, not by which query
     * asked for them — inside a batch there is no other way to tell whose match
     * is whose. That is stricter than the single-SKU path, which reports whatever
     * the search returns under the SKU that was asked for.
     *
     * @param  list<string>  $skus
     * @return array<string, list<array<string, mixed>>>  keyed as the caller spelled it
     */
    public function findVariantsBySkus(array $skus, bool $throwOnFailure = false): array
    {
        // One lookup key per SKU, but every spelling the caller used gets its own
        // answer back — a list holding both "ABC" and "abc" must not lose one.
        $spellings = [];

        foreach ($skus as $sku) {
            $sku = trim($sku);

            if ($sku !== '') {
                $spellings[mb_strtolower($sku)][] = $sku;
            }
        }

        if (count($spellings) === 0) {
            return [];
        }

        $results = [];

        // Asked for as the caller spelled it: Shopify's search is not promised to
        // be case-insensitive, so the lowercased form stays a local key only.
        $terms = array_map(fn ($spelling) => $spelling[0], $spellings);

        foreach (array_chunk(array_values($terms), self::SKU_BATCH) as $batch) {
            foreach ($this->fetchSkuBatch($batch, $throwOnFailure) as $key => $variants) {
                foreach ($spellings[$key] ?? [] as $spelling) {
                    $results[$spelling] = $variants;
                }
            }
        }

        return $results;
    }

    /**
     * One batched search, split and retried if the page cap is reached.
     *
     * A batch asks for at most SKU_BATCH_PAGE variants. Reaching that cap means
     * the page may have cut off variants belonging to SKUs later in the batch,
     * and a missing variant reads back as "not in Shopify" — the silent wrong
     * answer this whole path exists to avoid. So a full page is halved and asked
     * again rather than believed.
     *
     * @param  list<string>  $batch  lowercased SKUs
     * @return array<string, list<array<string, mixed>>>  keyed by lowercased sku
     */
    private function fetchSkuBatch(array $batch, bool $throwOnFailure): array
    {
        $this->throttle();

        try {
            $response = $this->http->post(
                "admin/api/{$this->apiVersion}/graphql.json",
                [
                    'json' => [
                        'query'     => $this->variantBatchQuery(),
                        'variables' => ['q' => $this->skuSearchExpression($batch)],
                    ],
                ]
            );

            $data = json_decode((string) $response->getBody(), true);
            $this->assertNoGraphQlErrors($data, 'findVariantsBySkus(' . count($batch) . ' skus)');

            $edges = $data['data']['productVariants']['edges'] ?? [];

            if (count($edges) >= self::SKU_BATCH_PAGE && count($batch) > 1) {
                $half = (int) ceil(count($batch) / 2);

                return array_merge(
                    $this->fetchSkuBatch(array_slice($batch, 0, $half), $throwOnFailure),
                    $this->fetchSkuBatch(array_slice($batch, $half), $throwOnFailure)
                );
            }

            $found = [];

            foreach ($edges as $edge) {
                $node = $edge['node'] ?? [];
                $sku  = mb_strtolower(trim((string) ($node['sku'] ?? '')));

                if ($sku === '') {
                    continue;
                }

                $found[$sku][] = [
                    'product_id'    => ltrim(str_replace('gid://shopify/Product/', '', $node['product']['id'] ?? ''), '/'),
                    'product_title' => $node['product']['title'] ?? '',
                    'variant_id'    => ltrim(str_replace('gid://shopify/ProductVariant/', '', $node['id'] ?? ''), '/'),
                    'variant_sku'   => $node['sku'] ?? '',
                    'published'     => ($node['product']['status'] ?? '') === 'ACTIVE',
                ];
            }

            return $found;

        } catch (\Throwable $e) {
            Log::error('Shopify findVariantsBySkus failed for ' . count($batch) . ' SKUs: ' . $e->getMessage());

            // Callers that report per-SKU results must pass true: an empty return
            // here is indistinguishable from "none of these exist", which would
            // mark a whole batch Not Available on a transport hiccup.
            if ($throwOnFailure) {
                throw new \RuntimeException('Shopify SKU lookup failed: ' . $e->getMessage(), 0, $e);
            }

            return [];
        }
    }

    /**
     * The search expression for a batch: sku:'A' OR sku:'B' OR …
     *
     * Quotes and backslashes are escaped rather than dropped — a SKU containing
     * one would otherwise end the quoted term early and silently widen the search
     * to match things nobody asked about.
     *
     * @param  list<string>  $batch
     */
    private function skuSearchExpression(array $batch): string
    {
        return implode(' OR ', array_map(
            fn ($sku) => "sku:'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $sku) . "'",
            $batch
        ));
    }

    /**
     * The batched form of variantLookupQuery: a wider page, because one call now
     * carries many SKUs, and only the fields a SKU check reports.
     */
    private function variantBatchQuery(): string
    {
        return 'query($q:String!){productVariants(first:' . self::SKU_BATCH_PAGE
            . ',query:$q){edges{node{id sku product{id title status}}}}}';
    }

    /**
     * Find a variant by exact barcode using the GraphQL Admin API.
     * Fallback for when the OneDrive folder/filename identifier doesn't
     * match any SKU — some catalogues are organised by barcode instead.
     */
    public function findVariantsByBarcode(string $barcode, bool $throwOnFailure = false, bool $lean = false): array
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
                        'query'     => $this->variantLookupQuery('barcode', $lean),
                        'variables' => ['q' => "barcode:'{$barcode}'"],
                    ],
                ]
            );

            $data = json_decode((string) $response->getBody(), true);
            $this->assertNoGraphQlErrors($data, "findVariantsByBarcode({$barcode})");

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

    /**
     * Live SKU-then-barcode lookup.
     *
     * The image upload paths use this. They write to Shopify, so they need the
     * store as it is right now — which is now the only way anything reads it.
     * There was once a cached twin backed by a periodic warm of the whole
     * catalogue; it was removed because a store too large to fit the cache
     * evicted its own entries, and an evicted entry is indistinguishable from a
     * SKU that was never there.
     */
    public function findVariantsBySkuOrBarcode(string $identifier, bool $throwOnFailure = false): array
    {
        // lean: the upload path needs an id to attach the image to, nothing else.
        // The full query drags in collections/tags/description for the AI content
        // generator, and Shopify prices a GraphQL call by the fields it asks for —
        // paying that on every image is what pushes a large batch into throttling.
        $variants = $this->findVariantsBySku($identifier, $throwOnFailure, true);

        if ($variants) {
            return $variants;
        }

        $variants = $this->findVariantsByBarcode($identifier, $throwOnFailure, true);

        // Tag the fallback path so callers can report accurately (e.g. "Duplicate barcode" vs "Duplicate SKU")
        return array_map(fn ($v) => $v + ['matched_via' => 'barcode'], $variants);
    }

    /**
     * Find products whose title contains the given style code as one of its
     * space-separated tokens, e.g. an OneDrive folder "W60830-126" matching
     * a product titled "W60830/126 T-Shirt Ivory 2 Ss26". A set/bundle
     * product can list several style codes up front — one per piece, each
     * with its own OneDrive folder — e.g. "KG314107O KG5141O9O KGQ14100N Set
     * Sleepsuit Multicolor 0-3M SS26" — so every token is checked, not just
     * the first; each matching folder's images land in that same product's
     * gallery. Folder/file punctuation around the code (/, -, _, spaces)
     * doesn't always match the title's punctuation exactly, so both sides
     * are canonicalized (letters + digits only, uppercased) before
     * comparing — only the leading alphanumeric run is sent to Shopify as a
     * search term, since that portion is guaranteed to appear literally in
     * the title before any separator.
     */
    public function findProductsByStyleCode(string $styleCode, bool $throwOnFailure = false): array
    {
        $canonical = $this->canonicalizeStyleCode($styleCode);
        $prefix    = $this->leadingAlnumRun($styleCode);

        if (!$canonical || !$prefix) {
            return [];
        }

        $this->throttle();

        try {
            $response = $this->http->post(
                "admin/api/{$this->apiVersion}/graphql.json",
                [
                    'json' => [
                        'query'     => 'query($q:String!){products(first:50,query:$q){edges{node{id title status}}}}',
                        'variables' => ['q' => "title:{$prefix}*"],
                    ],
                ]
            );

            $data = json_decode((string) $response->getBody(), true);
            $this->assertNoGraphQlErrors($data, "findProductsByStyleCode({$styleCode})");

            $edges = $data['data']['products']['edges'] ?? [];

            $matches = [];
            foreach ($edges as $edge) {
                $node   = $edge['node'];
                $title  = trim($node['title'] ?? '');
                $tokens = preg_split('/\s+/', $title, -1, PREG_SPLIT_NO_EMPTY);

                $tokenMatches = false;
                foreach ($tokens as $token) {
                    if ($this->canonicalizeStyleCode($token) === $canonical) {
                        $tokenMatches = true;
                        break;
                    }
                }

                if (!$tokenMatches) {
                    continue;
                }

                $matches[] = [
                    'product_id'    => ltrim(str_replace('gid://shopify/Product/', '', $node['id']), '/'),
                    'product_title' => $title,
                    'published'     => ($node['status'] ?? '') === 'ACTIVE',
                ];
            }

            return $matches;

        } catch (\Throwable $e) {
            Log::error("Shopify findProductsByStyleCode({$styleCode}) GraphQL failed: " . $e->getMessage());
            if ($throwOnFailure) {
                throw new \RuntimeException("Shopify style code lookup failed for {$styleCode}: " . $e->getMessage(), 0, $e);
            }
            return [];
        }
    }

    private function canonicalizeStyleCode(string $raw): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw));
    }

    private function leadingAlnumRun(string $raw): string
    {
        preg_match('/^[A-Za-z0-9]+/', trim($raw), $m);
        return $m[0] ?? '';
    }


    /**
     * Every variant of the product a SKU belongs to, grouped by colour.
     *
     * A SKU check answers "is this SKU in Shopify"; this answers the question
     * that follows it — which colours the product actually carries, which of
     * them have a photo, and which sizes within a colour are still missing one.
     *
     * "Has an image" means the variant owns a photo, not that its product's
     * gallery holds one: the classic image_id link and the newer variant media
     * attachment both count, because a photo added by hand in the admin may
     * arrive under either — the same rule variantHasOwnImage() applies when it
     * decides whether an upload would duplicate.
     *
     * One call per SKU, on demand. It is deliberately not folded into the bulk
     * job: a run of ten thousand SKUs would pay for ten thousand of these to
     * answer a question asked about a handful of rows.
     *
     * @return array<string, mixed>|null  null when no variant carries the SKU
     */
    public function getSkuVariantBreakdown(string $sku, bool $throwOnFailure = false): ?array
    {
        $sku = trim($sku);

        if ($sku === '') {
            return null;
        }

        try {
            try {
                $edges      = $this->fetchSkuBreakdownEdges($sku, true);
                $stockKnown = true;
            } catch (\RuntimeException $e) {
                // Stock has taken this lookup down once already, colours and
                // all. Losing the colours over a stock column is the wrong
                // trade, so a refused stock field is asked again without it,
                // and the screen says stock is unknown rather than showing 0.
                if (!$this->isStockFieldUnavailable($e->getMessage())) {
                    throw $e;
                }

                Log::warning("Shopify getSkuVariantBreakdown({$sku}): stock unavailable ({$e->getMessage()}), reporting variants without it");

                $edges      = $this->fetchSkuBreakdownEdges($sku, false);
                $stockKnown = false;
            }

            // The search is not promised to be case-exact, and a sku: term can
            // match more than one variant, so the asked-for spelling wins and
            // anything else is a fallback rather than a silent substitution.
            $matched = null;

            foreach ($edges as $edge) {
                $node = $edge['node'] ?? [];

                if (mb_strtolower(trim((string) ($node['sku'] ?? ''))) === mb_strtolower($sku)) {
                    $matched = $node;
                    break;
                }
            }

            $matched ??= $edges[0]['node'] ?? null;

            if (!is_array($matched) || empty($matched['product'])) {
                return null;
            }

            return $this->shapeSkuBreakdown($sku, $matched, $stockKnown);

        } catch (\Throwable $e) {
            Log::error("Shopify getSkuVariantBreakdown({$sku}) failed: " . $e->getMessage());

            if ($throwOnFailure) {
                throw new \RuntimeException("Shopify variant breakdown failed for {$sku}: " . $e->getMessage(), 0, $e);
            }

            return null;
        }
    }

    /**
     * The variants a SKU search returns, each with its whole product attached.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchSkuBreakdownEdges(string $sku, bool $withStock): array
    {
        $this->throttle();

        $response = $this->http->post(
            "admin/api/{$this->apiVersion}/graphql.json",
            [
                'json' => [
                    'query'     => $this->skuBreakdownQuery($withStock),
                    'variables' => ['q' => $this->skuSearchExpression([$sku])],
                ],
            ]
        );

        $data = json_decode((string) $response->getBody(), true);
        $this->assertNoGraphQlErrors($data, "getSkuVariantBreakdown({$sku})");

        return $data['data']['productVariants']['edges'] ?? [];
    }

    /**
     * Is this Shopify refusing the stock field itself — a missing
     * read_inventory scope, or the field gone from the version being served?
     *
     * Only those may fall back. A throttle or a network failure must still
     * fail, or the retry quietly reports stock unknown for a whole catalogue.
     */
    private function isStockFieldUnavailable(string $message): bool
    {
        foreach (['read_inventory', 'inventoryQuantity'] as $sign) {
            if (stripos($message, $sign) !== false) {
                return true;
            }
        }

        return stripos($message, 'access denied') !== false
            && stripos($message, 'inventor') !== false;
    }

    /**
     * Turn one matched variant's product into the colour/size shape the SKU
     * checker renders.
     *
     * @param  array<string, mixed>  $matched
     * @param  bool  $stockKnown  false when the store would not report stock
     * @return array<string, mixed>
     */
    private function shapeSkuBreakdown(string $sku, array $matched, bool $stockKnown = true): array
    {
        $product    = $matched['product'];
        $matchedGid = $matched['id'] ?? '';

        $optionNames = array_map(
            fn ($o) => (string) ($o['name'] ?? ''),
            $product['options'] ?? []
        );

        $colourOption = $this->optionNamed($optionNames, '/colou?r|shade/i', 0);
        $sizeOption   = $this->optionNamed($optionNames, '/size/i', 1);

        $colours    = [];
        $total      = 0;
        $withImg    = 0;
        $totalStock = 0;

        foreach ($product['variants']['edges'] ?? [] as $edge) {
            $node = $edge['node'] ?? [];

            $options  = [];
            foreach ($node['selectedOptions'] ?? [] as $opt) {
                $options[(string) ($opt['name'] ?? '')] = (string) ($opt['value'] ?? '');
            }

            $colour = $colourOption !== null ? ($options[$colourOption] ?? '') : '';
            $size   = $sizeOption   !== null ? ($options[$sizeOption]   ?? '') : '';

            // A single-option product still has to land somewhere, and its one
            // option is already the colour by the fallback above — so an empty
            // label means the product genuinely has none to show.
            $colourKey = $colour !== '' ? $colour : '—';

            $images   = $this->variantImageUrls($node);
            $hasImage = count($images) > 0;

            $colours[$colourKey] ??= [
                'colour'            => $colourKey,
                'sizes'             => [],
                'variant_count'     => 0,
                'with_image_count'  => 0,
                'stock'             => $stockKnown ? 0 : null,
                'preview'           => null,
            ];

            // null, not 0, when the store would not say: a zero reads as
            // "out of stock", which is a different and possibly wrong answer.
            $stock = $stockKnown ? (int) ($node['inventoryQuantity'] ?? 0) : null;

            $colours[$colourKey]['sizes'][] = [
                'size'       => $size !== '' ? $size : ($node['title'] ?? ''),
                'sku'        => $node['sku'] ?? '',
                'variant_id' => ltrim(str_replace('gid://shopify/ProductVariant/', '', $node['id'] ?? ''), '/'),
                'has_image'  => $hasImage,
                'image_count'=> count($images),
                'preview'    => $images[0] ?? null,
                'is_match'   => ($node['id'] ?? '') === $matchedGid,
                'stock'      => $stock,
            ];

            $colours[$colourKey]['variant_count']++;
            $total++;

            if ($stockKnown) {
                $colours[$colourKey]['stock'] += $stock;
                $totalStock += $stock;
            }

            if ($hasImage) {
                $colours[$colourKey]['with_image_count']++;
                $colours[$colourKey]['preview'] ??= $images[0];
                $withImg++;
            }
        }

        return [
            'sku'             => $sku,
            'product_id'      => ltrim(str_replace('gid://shopify/Product/', '', $product['id'] ?? ''), '/'),
            'product_title'   => $product['title'] ?? '',
            'published'       => ($product['status'] ?? '') === 'ACTIVE',
            'colour_option'   => $colourOption,
            'size_option'     => $sizeOption,
            'gallery_count'   => count($product['media']['edges'] ?? []),
            'variant_count'   => $total,
            'with_image_count'=> $withImg,
            'stock'           => $stockKnown ? $totalStock : null,
            'colours'         => array_values($colours),
        ];
    }

    /**
     * The option whose name reads as colour (or size), falling back to position
     * when a store names its options something else entirely.
     *
     * @param  list<string>  $names
     */
    private function optionNamed(array $names, string $pattern, int $fallbackIndex): ?string
    {
        foreach ($names as $name) {
            if ($name !== '' && preg_match($pattern, $name)) {
                return $name;
            }
        }

        return $names[$fallbackIndex] ?? null;
    }

    /**
     * Photos this variant owns, legacy image link and variant media together.
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function variantImageUrls(array $node): array
    {
        $urls = [];

        if (!empty($node['image']['url'])) {
            $urls[] = $node['image']['url'];
        }

        foreach ($node['media']['edges'] ?? [] as $edge) {
            $url = $edge['node']['image']['url'] ?? null;
            if ($url) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * The whole product behind one SKU in a single call: its options, its
     * gallery size, and every variant with the photos it owns.
     */
    private function skuBreakdownQuery(bool $withStock = true): string
    {
        // Stock is the variant's own inventoryQuantity: a plain number, which
        // Shopify does not charge for. Not inventoryLevels — that connection,
        // nested inside variants(250) inside this search, took the query to
        // 1306 points against Shopify's 1000-point ceiling and was refused
        // outright, colours and all. first:3 rather than 10 on the same budget:
        // one SKU names one variant, and each extra multiplies the product body.
        $stock = $withStock ? ' inventoryQuantity' : '';

        return 'query($q:String!){productVariants(first:3,query:$q){edges{node{
            id sku
            product{
                id title status
                options{name}
                media(first:250){edges{node{id}}}
                variants(first:250){edges{node{
                    id sku title' . $stock . '
                    selectedOptions{name value}
                    image{url}
                    media(first:10){edges{node{... on MediaImage{image{url}}}}}
                }}}
            }
        }}}}';
    }

    // ── Image upload ───────────────────────────────────────────────────────

    public function uploadImageToProduct(
        string $productId,
        string $imageContent,
        string $filename,
        string $altText = '',
        ?string $variantId = null,
        ?int $position = null,
    ): ?string {
        $this->throttle();

        $imageData = [
            'attachment' => base64_encode($imageContent),
            'filename'   => $filename,
            'alt'        => $altText ?: pathinfo($filename, PATHINFO_FILENAME),
        ];

        /*
         * Where the image lands in the product's gallery. Left out, Shopify
         * appends, and the order becomes whichever upload happened to finish
         * first — which with several workers running is not an order at all.
         * Naming the position is the only way a chosen sequence survives being
         * uploaded in parallel.
         */
        if ($position !== null) {
            $imageData['position'] = max(1, $position);
        }

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
            throw ShopifyRequestException::fromClientException(
                $e,
                'Shopify image upload failed: ' . $this->clientErrorDetail($e),
            );
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
     * Move one image to a given place in a product's gallery.
     *
     * Needed because naming a position on upload does not settle the order.
     * Shopify clamps a position to the gallery as it stands at that moment, so
     * asking for position 7 of an eventual 27 lands the image at the end if
     * only six are up yet — and with several workers uploading at once, "as it
     * stands at that moment" is just whichever job finished first. Positions
     * set afterwards, against a complete gallery, are the only ones that hold.
     *
     * Images are renumbered around the one that moves, so a caller setting a
     * whole sequence has to walk it in ascending order. See SettleGalleryOrderJob.
     */
    public function setImagePosition(string $productId, string $imageId, int $position): bool
    {
        $this->throttle();

        try {
            $this->http->put(
                "admin/api/{$this->apiVersion}/products/{$productId}/images/{$imageId}.json",
                ['json' => ['image' => ['id' => (int) $imageId, 'position' => max(1, $position)]]],
            );

            return true;
        } catch (\Throwable $e) {
            Log::warning("Shopify setImagePosition({$productId}, {$imageId}): " . $e->getMessage());

            return false;
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
     * Does this variant already have a photo of its OWN — one assigned to the
     * variant, not merely sitting in its product's gallery?
     *
     * This is the question the bulk upload's skip rule turns on. Alt text alone
     * could not answer it: an image only carries the SKU as alt text when this
     * tool uploaded it, so photos that arrived by AutoImport or by hand were
     * invisible and their SKUs got a second copy of every file.
     *
     * Both signals are checked in one call — `image` is the legacy variant
     * image_id link, `media` is the newer variant media attachment, and a photo
     * added through the Shopify admin may show up under either.
     *
     * $throwOnFailure: callers deciding whether to skip an upload MUST pass
     * true. Swallowing an API error here reads as "no image" and uploads a
     * duplicate, which a retry cannot undo.
     */
    public function variantHasOwnImage(string $variantId, bool $throwOnFailure = false): bool
    {
        $this->throttle();

        $gid = "gid://shopify/ProductVariant/{$variantId}";

        try {
            $response = $this->http->post("admin/api/{$this->apiVersion}/graphql.json", [
                'json' => [
                    'query' => 'query($id: ID!) {
                        productVariant(id: $id) {
                            image { id }
                            media(first: 1) { edges { node { id } } }
                        }
                    }',
                    'variables' => ['id' => $gid],
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            $this->assertNoGraphQlErrors($data, "variantHasOwnImage({$variantId})");

            $variant = $data['data']['productVariant'] ?? null;

            // A variant Shopify no longer knows about has no image to protect.
            // Let the upload proceed and fail loudly on the write instead.
            if (!is_array($variant)) {
                return false;
            }

            return !empty($variant['image']['id']) || !empty($variant['media']['edges']);

        } catch (\Throwable $e) {
            Log::error("Shopify variantHasOwnImage({$variantId}) failed: " . $e->getMessage());

            if ($throwOnFailure) {
                throw new \RuntimeException(
                    "Shopify variant image lookup failed for {$variantId}: " . $e->getMessage(), 0, $e
                );
            }

            return false;
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

    // ── Product count ──────────────────────────────────────────────────────

    /**
     * How many products the store holds. Used to give an audit a denominator
     * before it starts streaming, so progress is a percentage rather than a
     * running total with no end in sight.
     *
     * Returns 0 rather than throwing: a missing count costs a progress bar,
     * and is no reason to abandon an audit that would otherwise run fine.
     */
    public function getProductCount(): int
    {
        $this->throttle();

        try {
            $response = $this->http->get("admin/api/{$this->apiVersion}/products/count.json");
            $data     = json_decode((string) $response->getBody(), true);

            return (int) ($data['count'] ?? 0);
        } catch (\Throwable $e) {
            Log::warning('Shopify getProductCount: ' . $e->getMessage());
            return 0;
        }
    }

    // ── SEO Audit ──────────────────────────────────────────────────────────
    /**
     * Product id => handle, for a batch of ids.
     *
     * The handle is a product's URL, which is the only thing Google Analytics
     * knows a product by — analytics reports a landing page, never a product
     * id, so nothing can be matched up without this.
     *
     * Ids that no longer exist are simply absent from the result rather than
     * failing the batch: a deleted product is a normal thing to find when
     * looking back at work done weeks ago.
     *
     * @param  list<string>  $productIds  numeric ids or gids
     * @return array<string, string>
     */
    public function getProductHandles(array $productIds): array
    {
        $handles = [];

        foreach (array_chunk(array_values(array_unique($productIds)), 100) as $chunk) {
            $this->throttle();

            $gids = array_map(
                fn ($id) => str_contains((string) $id, 'gid://') ? (string) $id : "gid://shopify/Product/{$id}",
                $chunk,
            );

            try {
                $response = $this->http->post("admin/api/{$this->apiVersion}/graphql.json", [
                    'json' => [
                        'query'     => 'query($ids:[ID!]!){nodes(ids:$ids){... on Product{id handle title}}}',
                        'variables' => ['ids' => $gids],
                    ],
                ]);

                $data = json_decode((string) $response->getBody(), true);
                $this->assertNoGraphQlErrors($data, 'getProductHandles');

                foreach ($data['data']['nodes'] ?? [] as $node) {
                    if (empty($node['id']) || empty($node['handle'])) {
                        continue;
                    }

                    $handles[$this->numericId($node['id'])] = $node['handle'];
                }
            } catch (\Throwable $e) {
                Log::warning('Shopify getProductHandles: ' . $e->getMessage());
            }
        }

        return $handles;
    }


    /**
     * Products per GraphQL page, and images fetched per product. Shopify
     * budgets a query at roughly (products x images) points against a 1000
     * ceiling, so 25 x 20 leaves headroom while still covering every image on
     * all but the largest galleries. Products past the image cap report the
     * images they have; alt-text gaps beyond the twentieth image are not
     * counted, which understates rather than invents a problem.
     */
    private const SEO_PAGE      = 25;
    private const SEO_IMAGE_CAP = 20;

    /**
     * Stream every product with the fields an SEO audit needs, page by page.
     *
     * REST cannot serve this: the meta title and meta description live in the
     * `global.title_tag` / `global.description_tag` metafields, which
     * products.json omits entirely and which would otherwise cost one extra
     * call per product. GraphQL exposes both as `seo { title description }`.
     *
     * Calls $callback with each page as a plain array of products.
     */
    public function streamProductsForSeoAudit(callable $callback): void
    {
        $cursor = null;

        do {
            $this->throttle();

            $response = $this->http->post("admin/api/{$this->apiVersion}/graphql.json", [
                'json' => [
                    'query'     => $this->seoAuditQuery(),
                    'variables' => ['cursor' => $cursor],
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            $this->assertNoGraphQlErrors($data, 'streamProductsForSeoAudit');

            $connection = $data['data']['products'] ?? [];
            $edges      = $connection['edges'] ?? [];

            $products = array_map(function (array $edge) {
                $node = $edge['node'];

                $images = array_map(
                    fn ($imageEdge) => ['alt' => $imageEdge['node']['altText'] ?? null],
                    $node['images']['edges'] ?? []
                );

                return [
                    'id'               => $this->numericId($node['id'] ?? ''),
                    'title'            => $node['title'] ?? '',
                    'handle'           => $node['handle'] ?? '',
                    'status'           => strtolower((string) ($node['status'] ?? '')),
                    'tags'             => $node['tags'] ?? [],
                    'description'      => $node['description'] ?? '',
                    'meta_title'       => $node['seo']['title'] ?? null,
                    'meta_description' => $node['seo']['description'] ?? null,
                    'images'           => $images,
                    'sku'              => $node['variants']['edges'][0]['node']['sku'] ?? null,
                ];
            }, $edges);

            if (!empty($products)) {
                $callback($products);
            }

            $cursor = ($connection['pageInfo']['hasNextPage'] ?? false)
                ? ($connection['pageInfo']['endCursor'] ?? null)
                : null;
        } while ($cursor);
    }

    /**
     * How many collections the store holds, for the audit's progress bar.
     *
     * Custom and smart collections are separate endpoints in REST, and a store
     * uses both, so the two counts are added. Returns 0 rather than throwing:
     * a missing count costs a progress bar and nothing else.
     */
    public function getCollectionCount(): int
    {
        $total = 0;

        foreach (['custom_collections', 'smart_collections'] as $endpoint) {
            $this->throttle();

            try {
                $response = $this->http->get("admin/api/{$this->apiVersion}/{$endpoint}/count.json");
                $total += (int) (json_decode((string) $response->getBody(), true)['count'] ?? 0);
            } catch (\Throwable $e) {
                Log::warning("Shopify getCollectionCount ({$endpoint}): " . $e->getMessage());
            }
        }

        return $total;
    }

    /**
     * Stream every collection with the fields an SEO audit needs.
     *
     * Worth auditing separately from products because collection pages are what
     * rank for category searches — "cabin luggage qatar" — while a product page
     * competes for a model name almost nobody types. There are only ever a few
     * dozen of them, and they carry a share of search demand out of all
     * proportion to their number.
     *
     * GraphQL's `collections` covers both custom and smart collections, so
     * unlike the count above this needs only one pass.
     */
    public function streamCollectionsForSeoAudit(callable $callback): void
    {
        $cursor = null;

        do {
            $this->throttle();

            $response = $this->http->post("admin/api/{$this->apiVersion}/graphql.json", [
                'json' => [
                    'query'     => $this->collectionSeoQuery(),
                    'variables' => ['cursor' => $cursor],
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            $this->assertNoGraphQlErrors($data, 'streamCollectionsForSeoAudit');

            $connection  = $data['data']['collections'] ?? [];
            $collections = array_map(fn (array $edge) => [
                'id'               => $this->numericId($edge['node']['id'] ?? ''),
                'title'            => $edge['node']['title'] ?? '',
                'handle'           => $edge['node']['handle'] ?? '',
                'description'      => $edge['node']['description'] ?? '',
                'meta_title'       => $edge['node']['seo']['title'] ?? null,
                'meta_description' => $edge['node']['seo']['description'] ?? null,
            ], $connection['edges'] ?? []);

            if (!empty($collections)) {
                $callback($collections);
            }

            $cursor = ($connection['pageInfo']['hasNextPage'] ?? false)
                ? ($connection['pageInfo']['endCursor'] ?? null)
                : null;
        } while ($cursor);
    }

    /**
     * Read one collection's own fields plus a sample of what is inside it.
     *
     * The product titles are the point: a collection has no photograph to write
     * from, so what it actually contains is the only evidence of what it is.
     *
     * @return array<string, mixed>|null
     */
    public function getCollectionForContent(string $collectionId, int $sampleSize = 12): ?array
    {
        $this->throttle();

        try {
            $response = $this->http->post("admin/api/{$this->apiVersion}/graphql.json", [
                'json' => [
                    'query' => 'query($id:ID!,$n:Int!){collection(id:$id){id title handle description seo{title description}'
                        . 'products(first:$n){edges{node{title productType vendor}}}}}',
                    'variables' => [
                        'id' => "gid://shopify/Collection/{$collectionId}",
                        'n'  => $sampleSize,
                    ],
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            $this->assertNoGraphQlErrors($data, "getCollectionForContent({$collectionId})");

            $node = $data['data']['collection'] ?? null;

            if (!$node) {
                return null;
            }

            $products = array_map(fn ($edge) => [
                'title'  => $edge['node']['title'] ?? '',
                'type'   => $edge['node']['productType'] ?? '',
                'vendor' => $edge['node']['vendor'] ?? '',
            ], $node['products']['edges'] ?? []);

            return [
                'id'               => $this->numericId($node['id'] ?? ''),
                'title'            => $node['title'] ?? '',
                'handle'           => $node['handle'] ?? '',
                'description'      => $node['description'] ?? '',
                'meta_title'       => $node['seo']['title'] ?? null,
                'meta_description' => $node['seo']['description'] ?? null,
                'products'         => $products,
            ];
        } catch (\Throwable $e) {
            Log::error("Shopify getCollectionForContent({$collectionId}) failed: " . $e->getMessage());

            return null;
        }
    }

    /**
     * Write a collection's description and SEO fields.
     *
     * GraphQL rather than the REST pair used for products: a collection's seo
     * fields are settable directly on collectionUpdate, so one call does what
     * would otherwise be a PUT plus two metafield posts.
     *
     * An empty string leaves a field alone rather than blanking it — nothing
     * here should be able to erase copy somebody wrote by hand.
     */
    public function updateCollectionContent(
        string $collectionId,
        string $description = '',
        string $metaTitle = '',
        string $metaDescription = '',
    ): void {
        $input = ['id' => "gid://shopify/Collection/{$collectionId}"];

        if ($description !== '') {
            $input['descriptionHtml'] = $description;
        }

        $seo = [];
        if ($metaTitle !== '')       $seo['title'] = $metaTitle;
        if ($metaDescription !== '') $seo['description'] = $metaDescription;
        if ($seo)                    $input['seo'] = $seo;

        // Nothing but the id: there is no change to make, and sending it would
        // spend a call to tell Shopify so.
        if (count($input) === 1) {
            return;
        }

        $this->throttle();

        $response = $this->http->post("admin/api/{$this->apiVersion}/graphql.json", [
            'json' => [
                'query'     => 'mutation($input:CollectionInput!){collectionUpdate(input:$input){userErrors{field message}}}',
                'variables' => ['input' => $input],
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true);
        $this->assertNoGraphQlErrors($data, "updateCollectionContent({$collectionId})");

        // userErrors are not GraphQL errors — the call succeeds and reports the
        // refusal in the payload, so it has to be read separately or a rejected
        // write looks like a successful one.
        $userErrors = $data['data']['collectionUpdate']['userErrors'] ?? [];

        if (!empty($userErrors)) {
            $detail = implode('; ', array_map(fn ($e) => $e['message'] ?? 'unknown', $userErrors));

            throw new \RuntimeException("Shopify refused the collection update: {$detail}");
        }
    }

    private function collectionSeoQuery(): string
    {
        return <<<'GQL'
        query($cursor: String) {
          collections(first: 100, after: $cursor) {
            pageInfo { hasNextPage endCursor }
            edges {
              node {
                id
                title
                handle
                description
                seo { title description }
              }
            }
          }
        }
        GQL;
    }

    private function seoAuditQuery(): string
    {
        $page  = self::SEO_PAGE;
        $imgs  = self::SEO_IMAGE_CAP;

        return <<<GQL
        query(\$cursor: String) {
          products(first: {$page}, after: \$cursor) {
            pageInfo { hasNextPage endCursor }
            edges {
              node {
                id
                title
                handle
                status
                tags
                description
                seo { title description }
                images(first: {$imgs}) { edges { node { altText } } }
                variants(first: 1) { edges { node { sku } } }
              }
            }
          }
        }
        GQL;
    }

    /** "gid://shopify/Product/123" -> "123"; anything else passes through. */
    private function numericId(string $gid): string
    {
        return $gid !== '' && str_contains($gid, '/')
            ? substr($gid, strrpos($gid, '/') + 1)
            : $gid;
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

    // ── Analytics ────────────────────────────────────────────────────────────

    /** Pages fetched before a range is reported capped rather than walked in full. */
    private const ANALYTICS_MAX_PAGES = 20;

    /** How many of the range's best-selling products the caller sees. */
    private const ANALYTICS_TOP_PRODUCTS = 20;

    /**
     * How many brands and divisions travel back per store.
     *
     * Deeper than any card shows, because the dashboard pools these across
     * every website before taking its top few — trimming to the display size
     * here would lose a brand that is seventh everywhere and second overall.
     */
    private const ANALYTICS_MAX_GROUPS = 100;

    /** How far back a token without read_all_orders can see. */
    private const ORDER_HISTORY_DAYS = 60;

    /**
     * Revenue, order count, sales channel and product breakdowns for this
     * store, for one date range — read straight from Shopify's own orders
     * rather than the pre-aggregated ecommerce-server endpoint the Orders tab
     * uses. `status:any` is asked for explicitly: GraphQL's default order
     * query mirrors the REST API's "open only" default, which would silently
     * drop closed and cancelled orders from the total.
     *
     * Channel comes from `channelInformation`, which Shopify marks deprecated
     * in favour of `attribution` — but `attribution` does not exist before the
     * 2026-07 schema, and this class pins $apiVersion well behind that. Moving
     * to it means moving every other call in here to a newer version too, so
     * the deprecated-but-present field is the correct one for this version.
     * It's an order-level property, so it's tallied once per order rather than
     * per line item the way product revenue is.
     *
     * A store with more than ANALYTICS_MAX_PAGES pages of orders in range stops
     * there rather than walking the whole history — `capped` tells the caller
     * the figures are a partial (but honestly labelled) picture.
     *
     * A token without `read_all_orders` sees only the last 60 days of orders
     * — Shopify returns nothing older, with no error, so "This year" quietly
     * becomes "since early August". The token's scopes come back alongside the
     * first page, and when they say the range reaches past that window,
     * `history_from` names the first day actually read so the figures can be
     * labelled instead of trusted. It stays null when the scopes did not come
     * back, since an unknown is not the same as a confirmed gap.
     */
    public function getOrderAnalytics(Carbon $from, Carbon $to): array
    {
        $visibleFrom = Carbon::now()->subDays(self::ORDER_HISTORY_DAYS);
        $historyFrom = null;

        $query = sprintf(
            "created_at:>='%s' AND created_at:<='%s' AND status:any",
            $from->startOfDay()->toIso8601String(),
            $to->endOfDay()->toIso8601String(),
        );

        $orders   = 0;
        $revenue  = 0.0;
        $currency = null;
        $products = [];
        $channels = [];
        $vendors  = [];
        $types    = [];
        $cursor   = null;
        $capped   = false;

        for ($page = 0; $page < self::ANALYTICS_MAX_PAGES; $page++) {
            $this->throttle();

            $response = $this->http->post("admin/api/{$this->apiVersion}/graphql.json", [
                'json' => [
                    'query'     => $this->orderAnalyticsQuery(),
                    'variables' => ['q' => $query, 'cursor' => $cursor],
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            $this->assertNoGraphQlErrors($data, 'getOrderAnalytics');

            $scopes = $data['data']['appInstallation']['accessScopes'] ?? null;

            if ($page === 0 && \is_array($scopes)
                && !\in_array('read_all_orders', array_column($scopes, 'handle'), true)
                && $from->lessThan($visibleFrom)) {
                $historyFrom = $visibleFrom->toDateString();
            }

            $edges = $data['data']['orders']['edges'] ?? [];

            foreach ($edges as $edge) {
                $node = $edge['node'] ?? [];
                $orders++;

                $shopMoney = $node['totalPriceSet']['shopMoney'] ?? [];
                $orderRevenue = (float) ($shopMoney['amount'] ?? 0);
                $revenue  += $orderRevenue;
                $currency ??= $shopMoney['currencyCode'] ?? null;

                // Attribution lives on the order, not the line item — a single
                // channel name per order, unlike revenue which is split by product.
                // Manual and draft orders carry no channelInformation at all,
                // and on a real storefront that is a third of them — enough
                // that "Unknown" would swallow most of the answer. The app
                // that created the order names it well enough to stand in.
                $channel = (string) (
                    $node['channelInformation']['channelDefinition']['channelName']
                    ?? $node['app']['name']
                    ?? 'Unknown'
                );
                $channels[$channel]['orders']  ??= 0;
                $channels[$channel]['revenue'] ??= 0.0;
                $channels[$channel]['orders']  += 1;
                $channels[$channel]['revenue'] += $orderRevenue;

                // Brands and divisions are counted once per order however
                // many of its lines carry them — "three orders had Luca Barra
                // in them" is the question, and tallying line items answers a
                // different one that reads the same. Revenue is still summed
                // per line, because that half genuinely is per item.
                $orderVendors = [];
                $orderTypes   = [];

                foreach ($node['lineItems']['edges'] ?? [] as $lineEdge) {
                    $line  = $lineEdge['node'] ?? [];
                    $title = (string) ($line['title'] ?? '');
                    $money = (float) ($line['discountedTotalSet']['shopMoney']['amount'] ?? 0);

                    // vendor sits on the line item, so it survives the product
                    // being deleted. productType does not exist on LineItem in
                    // this API version and has to come through the product,
                    // which is null once that product is gone.
                    $vendor = trim((string) ($line['vendor'] ?? '')) ?: 'Not recorded';
                    $type   = trim((string) ($line['product']['productType'] ?? '')) ?: 'Not recorded';

                    $orderVendors[$vendor] = ($orderVendors[$vendor] ?? 0.0) + $money;
                    $orderTypes[$type]     = ($orderTypes[$type] ?? 0.0) + $money;

                    if ($title === '') {
                        continue;
                    }

                    $products[$title]['quantity'] ??= 0;
                    $products[$title]['revenue']  ??= 0.0;
                    $products[$title]['quantity'] += (int) ($line['quantity'] ?? 0);
                    $products[$title]['revenue']  += $money;
                }

                foreach ($orderVendors as $vendor => $money) {
                    $vendors[$vendor]['orders']  ??= 0;
                    $vendors[$vendor]['revenue'] ??= 0.0;
                    $vendors[$vendor]['orders']  += 1;
                    $vendors[$vendor]['revenue'] += $money;
                }

                foreach ($orderTypes as $type => $money) {
                    $types[$type]['orders']  ??= 0;
                    $types[$type]['revenue'] ??= 0.0;
                    $types[$type]['orders']  += 1;
                    $types[$type]['revenue'] += $money;
                }
            }

            $pageInfo = $data['data']['orders']['pageInfo'] ?? [];

            if (empty($pageInfo['hasNextPage'])) {
                break;
            }

            if ($page + 1 >= self::ANALYTICS_MAX_PAGES) {
                $capped = true;
                break;
            }

            $cursor = $edges ? end($edges)['cursor'] ?? null : null;

            if (!$cursor) {
                break;
            }
        }

        $topProducts = collect($products)
            ->map(fn ($totals, $title) => ['title' => $title, ...$totals])
            ->sortByDesc('revenue')
            ->take(self::ANALYTICS_TOP_PRODUCTS)
            ->values()
            ->all();

        $byChannel = collect($channels)
            ->map(fn ($totals, $channel) => ['channel' => $channel, ...$totals])
            ->sortByDesc('revenue')
            ->values()
            ->all();

        return [
            'orders'          => $orders,
            'revenue'         => $revenue,
            'currency'        => $currency ?? 'USD',
            'top_products'    => $topProducts,
            'by_channel'      => $byChannel,
            'by_vendor'       => $this->rankGroups($vendors),
            'by_product_type' => $this->rankGroups($types),
            'capped'          => $capped,
            'history_from'    => $historyFrom,
        ];
    }

    /**
     * Brands or divisions as a ranked list, busiest first.
     *
     * Sorted by orders rather than revenue: that is the question these answer,
     * and ranking by money would put one jewellery sale above thirty of
     * something cheap. "Not recorded" sorts with everything else rather than
     * being pinned last — it is a real quantity of real orders, and a store
     * where it ranks first has a catalogue problem worth seeing.
     *
     * @param  array<string, array{orders: int, revenue: float}>  $groups
     */
    private function rankGroups(array $groups): array
    {
        return collect($groups)
            ->map(fn ($totals, $label) => ['label' => $label, ...$totals])
            ->sortByDesc('orders')
            ->take(self::ANALYTICS_MAX_GROUPS)
            ->values()
            ->all();
    }

    /**
     * The API version the cancellation list asks with. The staff note typed
     * into Shopify's cancel dialog only exists on newer versions than the
     * one the rest of this class is pinned to.
     */
    private const CANCELLATION_API_VERSION = '2026-01';

    /** Enough for any month these stores have had; the list is for reading, not totalling. */
    private const CANCELLATION_LIMIT = 100;

    /**
     * Cancelled orders in a range, most recently cancelled first, with the
     * reason and staff note given when they were cancelled.
     *
     * By order date, the range is when they were placed. Otherwise it is
     * when they were cancelled: Shopify's search has no cancelled-at filter,
     * so this asks for cancelled orders updated since the start — cancelling
     * is an update, so every one in range is among them — and keeps those
     * whose cancelledAt actually falls inside it.
     *
     * @return list<array{id:string,number:string,reason:?string,staff_note:?string,cancelled_at:string,total:float,currency:?string,url:string}>
     */
    public function getCancelledOrders(Carbon $from, Carbon $to, string $basis = 'created'): array
    {
        $start = $from->copy()->startOfDay();
        $end   = $to->copy()->endOfDay();

        // On "Order date" the range is when the order was placed, the same
        // way the delivery tiles count it, so the list and the Cancelled tile
        // answer the same question. Any other basis lists what was cancelled
        // in the range.
        $byCreated = $basis === 'created';

        $variables = ['q' => $byCreated
            ? sprintf("status:cancelled AND created_at:>='%s' AND created_at:<='%s'", $start->toIso8601String(), $end->toIso8601String())
            : sprintf("status:cancelled AND updated_at:>='%s'", $start->toIso8601String())];

        // No customer name: the stores' apps are not granted protected
        // customer data, and asking for it makes Shopify refuse the whole
        // query. The staff note needs a newer API than some stores answer on,
        // so it is dropped on refusal rather than losing the order with it.
        try {
            $data = $this->cancellationRequest($this->cancelledOrdersQuery(withNote: true), $variables);
        } catch (\RuntimeException $e) {
            if (stripos($e->getMessage(), 'cancellation') === false && stripos($e->getMessage(), 'staffNote') === false) {
                throw $e;
            }

            $data = $this->cancellationRequest($this->cancelledOrdersQuery(withNote: false), $variables);
        }

        $orders = [];

        foreach ($data['data']['orders']['edges'] ?? [] as $edge) {
            $node = $edge['node'] ?? [];
            $at   = $node['cancelledAt'] ?? null;

            if (!$at) {
                continue;
            }

            if (!$byCreated && !Carbon::parse($at)->betweenIncluded($start, $end)) {
                continue;
            }

            $money = $node['totalPriceSet']['shopMoney'] ?? [];

            $orders[] = [
                'id'           => (string) ($node['legacyResourceId'] ?? ''),
                'number'       => (string) ($node['name'] ?? ''),
                'reason'       => $node['cancelReason'] ?? null,
                'staff_note'   => trim((string) ($node['cancellation']['staffNote'] ?? '')) ?: null,
                'cancelled_at' => $at,
                'ordered_at'   => $node['createdAt'] ?? null,
                'payment'      => $node['displayFinancialStatus'] ?? null,
                'total'        => (float) ($money['amount'] ?? 0),
                'currency'     => $money['currencyCode'] ?? null,
                'url'          => "https://{$this->shop}/admin/orders/" . ($node['legacyResourceId'] ?? ''),
            ];
        }

        usort($orders, fn ($a, $b) => strcmp($b['cancelled_at'], $a['cancelled_at']));

        return $orders;
    }

    private function cancellationRequest(string $query, array $variables): array
    {
        $this->throttle();

        try {
            $response = $this->http->post('admin/api/' . self::CANCELLATION_API_VERSION . '/graphql.json', [
                'json' => ['query' => $query, 'variables' => $variables],
            ]);
        } catch (ClientException $e) {
            // An HTTP refusal carries its reason in the body, which is the
            // part worth showing; Guzzle's own message truncates it.
            $status = $e->getResponse()->getStatusCode();
            $body   = mb_substr(trim((string) $e->getResponse()->getBody()), 0, 300);

            throw new \RuntimeException("Shopify HTTP {$status} in getCancelledOrders: {$body}", $status, $e);
        }

        $data = json_decode((string) $response->getBody(), true);
        $this->assertNoGraphQlErrors($data, 'getCancelledOrders');

        return $data;
    }

    private function cancelledOrdersQuery(bool $withNote): string
    {
        return 'query($q:String!){'
            . 'orders(first:' . self::CANCELLATION_LIMIT . ',query:$q,sortKey:UPDATED_AT,reverse:true){'
            . 'edges{node{'
            . 'legacyResourceId name createdAt cancelledAt cancelReason displayFinancialStatus '
            . 'totalPriceSet{shopMoney{amount currencyCode}}'
            . ($withNote ? ' cancellation{staffNote}' : '')
            . '}}}}';
    }

    private function orderAnalyticsQuery(): string
    {
        return 'query($q:String!,$cursor:String){'
            . 'orders(first:250,query:$q,after:$cursor,sortKey:CREATED_AT){'
            . 'edges{cursor node{'
            . 'totalPriceSet{shopMoney{amount currencyCode}}'
            . 'channelInformation{channelDefinition{channelName}}'
            . 'app{name}'
            . 'lineItems(first:250){edges{node{title quantity discountedTotalSet{shopMoney{amount}} vendor product{productType}}}}'
            . '}}'
            . 'pageInfo{hasNextPage}'
            . '}'
            . 'appInstallation{accessScopes{handle}}'
            . '}';
    }

    // ── Product performance (bulk exports) ─────────────────────────────────

    /** How long one bulk export may run before it is given up on. */
    private const BULK_MAX_WAIT_SECONDS = 2400;

    private const BULK_POLL_SECONDS = 5;

    /**
     * The shop's own timezone and currency. Sales are bucketed into days in the
     * shop's timezone, so "yesterday" means what the team there means by it.
     *
     * @return array{timezone:string,currency:string}
     */
    public function getShopSettings(): array
    {
        $this->throttle();

        $response = $this->http->post("admin/api/{$this->apiVersion}/graphql.json", [
            'json' => ['query' => '{shop{ianaTimezone currencyCode}}'],
        ]);

        $data = json_decode((string) $response->getBody(), true);
        $this->assertNoGraphQlErrors($data, 'getShopSettings');

        return [
            'timezone' => (string) ($data['data']['shop']['ianaTimezone'] ?? 'UTC'),
            'currency' => (string) ($data['data']['shop']['currencyCode'] ?? 'USD'),
        ];
    }

    /**
     * Every product in the store, with its first variant's SKU, written to
     * $path as Shopify's JSONL. Returns false when the store has no products.
     */
    public function exportProductsForPerformance(string $path): bool
    {
        return $this->runBulkExport(<<<'GQL'
        {
          products {
            edges {
              node {
                id title handle vendor productType status totalInventory createdAt
                featuredImage { url }
                variants { edges { node { sku } } }
              }
            }
          }
        }
        GQL, $path);
    }

    /**
     * Every order created since $from, with its line items, written to $path as
     * Shopify's JSONL. Returns false when there are no orders in range.
     *
     * A bulk export rather than paging: a year of orders with their line items
     * is far past what a single GraphQL query may cost, and a bulk operation
     * has no cost ceiling and no page cap. Without the read_all_orders scope
     * Shopify silently returns only the last 60 days — the caller reads the
     * earliest order date back rather than assuming the whole range arrived.
     */
    public function exportOrdersForPerformance(Carbon $from, string $path): bool
    {
        $since = $from->toIso8601String();

        return $this->runBulkExport(<<<GQL
        {
          orders(query: "created_at:>='{$since}'") {
            edges {
              node {
                id createdAt cancelledAt test
                lineItems {
                  edges {
                    node {
                      quantity
                      product { id }
                      discountedTotalSet { shopMoney { amount } }
                    }
                  }
                }
              }
            }
          }
        }
        GQL, $path);
    }

    /**
     * Starts a bulk query, waits for it, and streams the result to $path.
     * Shopify allows one bulk query per app per shop at a time, so one already
     * running (say, a run that was killed mid-wait) is waited out first.
     */
    private function runBulkExport(string $query, string $path): bool
    {
        $mutation = 'mutation($q:String!){bulkOperationRunQuery(query:$q){bulkOperation{id status} userErrors{field message}}}';

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->throttle();

            $response = $this->http->post("admin/api/{$this->apiVersion}/graphql.json", [
                'json' => ['query' => $mutation, 'variables' => ['q' => $query]],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            $this->assertNoGraphQlErrors($data, 'runBulkExport');

            $userErrors = $data['data']['bulkOperationRunQuery']['userErrors'] ?? [];

            if (empty($userErrors)) {
                break;
            }

            $detail = implode('; ', array_map(fn ($e) => $e['message'] ?? 'unknown', $userErrors));

            if ($attempt === 0 && stripos($detail, 'in progress') !== false) {
                $this->waitForBulkOperation();
                continue;
            }

            throw new \RuntimeException("Shopify refused the bulk export: {$detail}");
        }

        $operation = $this->waitForBulkOperation();

        if (($operation['status'] ?? '') !== 'COMPLETED') {
            $reason = $operation['errorCode'] ?? $operation['status'] ?? 'unknown';
            throw new \RuntimeException("Shopify bulk export did not complete: {$reason}");
        }

        // A completed export with nothing in it has no file at all.
        if (empty($operation['url'])) {
            return false;
        }

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        (new Client(['timeout' => 900]))->get($operation['url'], ['sink' => $path]);

        return true;
    }

    /** Polls the shop's current bulk query until it stops running, and returns it. */
    private function waitForBulkOperation(): array
    {
        $deadline = time() + self::BULK_MAX_WAIT_SECONDS;

        do {
            $this->throttle();

            $response = $this->http->post("admin/api/{$this->apiVersion}/graphql.json", [
                'json' => ['query' => '{currentBulkOperation{id status errorCode objectCount url}}'],
            ]);

            $data = json_decode((string) $response->getBody(), true);
            $this->assertNoGraphQlErrors($data, 'waitForBulkOperation');

            $operation = $data['data']['currentBulkOperation'] ?? null;

            if (!$operation || !in_array($operation['status'] ?? '', ['CREATED', 'RUNNING', 'CANCELING'], true)) {
                return $operation ?? [];
            }

            sleep(self::BULK_POLL_SECONDS);
        } while (time() < $deadline);

        throw new \RuntimeException('Shopify bulk export was still running after ' . (self::BULK_MAX_WAIT_SECONDS / 60) . ' minutes.');
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
    /**
     * The variant lookup query, keyed by SKU or barcode.
     *
     * Lean asks only for what identifies a product; the full form adds the
     * merchandising fields the AI content generator reads. Shopify charges a
     * GraphQL call by requested cost, and `collections(first:20)` alone costs
     * more than the rest of the node put together — so the lean form is
     * roughly an order of magnitude cheaper per call, and correspondingly
     * further from the throttle ceiling on a long batch.
     */
    private function variantLookupQuery(string $field, bool $lean): string
    {
        // A shared identifier lands on several variants of ONE product; callers
        // treat a spread across multiple products as ambiguous and skip it. 50 is
        // far past any real variant count while costing a fifth of 250.
        return $lean
            ? 'query($q:String!){productVariants(first:50,query:$q){edges{node{id sku ' . ($field === 'barcode' ? 'barcode ' : '') . 'product{id title status}}}}}'
            // descriptionHtml is read back only on the SKU path (the content
            // generator's "existing description"), so the barcode form leaves it out.
            // handle rides along free on a query already being made: it is the
            // product's URL, and Search Console knows a page by nothing else.
            : 'query($q:String!){productVariants(first:250,query:$q){edges{node{id sku ' . ($field === 'barcode' ? 'barcode ' : '') . 'product{id title handle status vendor productType tags ' . ($field === 'sku' ? 'descriptionHtml ' : '') . 'collections(first:20){edges{node{title}}}}}}}}';
    }

    /**
     * Shopify reports a throttled or rejected GraphQL call with HTTP 200 and an
     * `errors` array — there is no status code for Guzzle to raise and no
     * ClientException for handleClientException to see. Left unchecked, `data`
     * arrives null, the edge list reads as empty, and the caller records "no
     * such SKU" for a product sitting right there in the admin — the same false
     * No Match a stale cache produced, now caused by load instead of staleness.
     *
     * Raising it lets the job's retry/backoff handle it as the transient
     * condition it is.
     */
    private function assertNoGraphQlErrors(?array $data, string $context): void
    {
        $errors = $data['errors'] ?? null;

        if (empty($errors)) {
            return;
        }

        $messages = [];
        $throttled = false;

        foreach ((array) $errors as $error) {
            if (!is_array($error)) {
                $messages[] = (string) $error;
                continue;
            }

            $messages[] = $error['message'] ?? 'unknown error';

            if (strtoupper($error['extensions']['code'] ?? '') === 'THROTTLED') {
                $throttled = true;
            }
        }

        $detail = implode('; ', array_filter($messages)) ?: 'unknown error';

        if ($throttled || stripos($detail, 'throttl') !== false) {
            // Empty the local bucket so this worker's next call waits rather
            // than charging straight back into the same ceiling.
            self::$callBucket = 0.0;
            Log::warning("Shopify GraphQL throttled in {$context} — backing off");
        }

        throw new \RuntimeException("Shopify GraphQL error in {$context}: {$detail}");
    }

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

        Log::error("Shopify {$code} in {$context}: " . $this->clientErrorDetail($e));

        return null;
    }

    /**
     * Guzzle's exception message stops after the first 120 characters of the
     * response body, which is usually just enough to cut off Shopify's actual
     * reason for the rejection. Pull the reason out of the JSON instead, so
     * what lands in the item's note explains the failure.
     */
    private function clientErrorDetail(ClientException $e): string
    {
        $response = $e->getResponse();
        $status   = $response->getStatusCode();

        // The stream may already have been read once — rewind before re-reading.
        $stream = $response->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $body = (string) $stream;

        $errors = json_decode($body, true)['errors'] ?? null;

        // Shopify returns errors either as a plain string or as
        // {"field": ["message", ...]} — flatten both to one line.
        $detail = match (true) {
            is_string($errors) => $errors,
            is_array($errors)  => implode('; ', array_map(
                fn ($field, $messages) => (is_int($field) ? '' : "{$field}: ")
                    . implode(', ', (array) $messages),
                array_keys($errors),
                $errors,
            )),
            default => trim(mb_substr($body, 0, 300)),
        };

        return "HTTP {$status}" . ($detail !== '' ? " — {$detail}" : '');
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
}
