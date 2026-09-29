<?php

namespace App\Services;

use App\Models\ProductRequest;
use App\Models\ProductRequestSku;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the mapping state of a request's SKUs up to date and rolls it up onto
 * the request.
 *
 * One source feeds a SKU's status: a read-only Shopify check (the existing SKU
 * Checker). The brand manager does the mapping in Cegid on their own side, and
 * the product turning up in Shopify is how that becomes visible here — so the
 * check decides, and nobody types a status in. Two sources meant two answers,
 * and a row somebody had marked by hand then stopped tracking reality.
 *
 * This module NEVER writes to Shopify.
 *
 * Legend the brand team works to:
 *   🟢 Mapped        — mapping done; E-Commerce can proceed
 *   🟡 Pending       — with the brand manager
 *   🔴 Not Mapped    — confirmed as not mappable yet
 */
class SkuMappingService
{
    /**
     * Refresh the read-only Shopify check for every row, then recompute the
     * roll-up. Safe to call repeatedly — the hourly re-check does.
     */
    public function validate(ProductRequest $request): void
    {
        $request->update(['validation_status' => 'running']);

        try {
            $rows = $request->skus()->orderBy('id')->get();

            if ($rows->isEmpty()) {
                $this->rollUp($request, 'completed');
                return;
            }

            $shopify = $this->shopifyFor($request);

            // Every SKU in one pass of batched queries, before the loop needs
            // any of them. This used to warm the store's whole catalogue into a
            // cache first — which cost twenty minutes on the largest store and
            // could not fit it, so entries evicted each other and a missing one
            // read back as "not in Shopify".
            // null means Shopify never answered — no connection, or a lookup
            // that failed and was swallowed. Only a real answer is evidence.
            $found    = $shopify ? $this->lookupShopify($shopify, $rows->pluck('sku')->all()) : null;
            $answered = $found !== null;
            $found  ??= [];

            foreach ($rows as $row) {
                $variants  = $found[$row->sku] ?? [];
                $inShopify = !empty($variants);

                $attributes = [
                    'in_shopify'            => $inShopify,
                    'shopify_product_id'    => $inShopify ? ($variants[0]['product_id'] ?? null) : null,
                    'shopify_product_title' => $inShopify ? ($variants[0]['product_title'] ?? null) : null,
                    'shopify_published'     => $inShopify ? (bool) ($variants[0]['published'] ?? false) : null,
                    // The lookup returns descriptionHtml already. Keeping it lets the
                    // request offer AI content for only the SKUs that have no copy,
                    // rather than writing over descriptions somebody already wrote.
                    'has_description'       => $inShopify
                        ? filled(trim(strip_tags((string) ($variants[0]['existing_description'] ?? ''))))
                        : null,
                    'last_checked_at'       => now(),
                ];

                $attributes['mapping_status'] = $this->autoStatus($inShopify);

                $row->update($attributes);
            }

            $this->rollUp($request, 'completed', $answered);

        } catch (\Throwable $e) {
            Log::error("SkuMappingService: validation failed for request {$request->id}: " . $e->getMessage());

            $request->update([
                'validation_status' => 'failed',
                'validation_error'  => $e->getMessage(),
            ]);
        }
    }

    /**
     * Above this many SKUs, reading the store once beats asking about each SKU.
     *
     * A cached lookup is free; an uncached one is a throttled GraphQL call, so a
     * request of 1,020 SKUs spends over an hour asking Shopify a thousand
     * questions it could have answered from one pass. The warm costs a single
     * paginated read and is shared by every request against that store for four
     * hours — which, on a re-sync of two hundred requests, it pays back on the
     * first one.
     */
    /**
     * Status for a row nobody has touched. Already in Shopify → clearly mapped;
     * otherwise it sits with the brand manager. Never auto-flags red: "we haven't
     * been told yet" is not the same as "it cannot be mapped".
     */
    public function autoStatus(bool $inShopify): string
    {
        return $inShopify ? ProductRequest::MAP_MAPPED : ProductRequest::MAP_PENDING;
    }

    /** Recompute the denormalised counters the dashboard and workflow gate read. */
    public function rollUp(ProductRequest $request, ?string $validationStatus = null, bool $shopifyAnswered = false): void
    {
        $counts = $request->skus()
            ->selectRaw('mapping_status, COUNT(*) as aggregate')
            ->groupBy('mapping_status')
            ->pluck('aggregate', 'mapping_status');

        $mapped    = (int) ($counts[ProductRequest::MAP_MAPPED] ?? 0);
        $pending   = (int) ($counts[ProductRequest::MAP_PENDING] ?? 0);
        $notMapped = (int) ($counts[ProductRequest::MAP_NOT_MAPPED] ?? 0);

        $request->update(array_filter([
            'total_skus'        => $mapped + $pending + $notMapped,
            'mapped_skus'       => $mapped,
            'pending_skus'      => $pending,
            'not_mapped_skus'   => $notMapped,
            'validated_at'      => now(),
            'validation_status' => $validationStatus,
            'validation_error'  => null,
        ], fn ($v) => $v !== null) + [
            // Set outside the filter, which drops nulls: a run that never reached
            // Shopify has to clear the stamp, not inherit the last good one. Left
            // in place, a stale stamp says the catalogue was checked when it was
            // not, and the reopen acts on it.
            'shopify_verified_at' => $shopifyAnswered ? now() : null,
        ]);
    }

    /** Replace the SKU list on a request, keeping existing rows' recorded state. */
    public function syncSkus(ProductRequest $request, array $skus): void
    {
        $skus = array_values(array_unique(array_filter(array_map('trim', $skus))));

        $request->skus()->whereNotIn('sku', $skus ?: ['__none__'])->delete();

        $existing = $request->skus()->pluck('sku')->all();

        $new = array_values(array_diff($skus, $existing));

        foreach (array_chunk($new, 500) as $chunk) {
            ProductRequestSku::insert(array_map(fn ($sku) => [
                'product_request_id' => $request->id,
                'sku'                => $sku,
                'mapping_status'     => ProductRequest::MAP_PENDING,
                'in_shopify'         => false,
                'created_at'         => now(),
                'updated_at'         => now(),
            ], $chunk));
        }

        $request->update(['total_skus' => count($skus)]);
    }

    private function shopifyFor(ProductRequest $request): ?ShopifyService
    {
        $store = $request->store_id
            ? Store::find($request->store_id)
            : Store::getActive($request->user_id);

        if (!$store) {
            Log::warning("SkuMappingService: no store for request {$request->id} — Shopify check skipped.");
            return null;
        }

        return app(ShopifyService::class, ['store' => $store]);
    }

    /**
     * Read-only lookup of every SKU at once, keyed by SKU.
     *
     * A Shopify hiccup must not fail the whole request, so a failure reads as
     * "not found" — the SKU stays pending and the next run, hourly or on the
     * button, picks it up. Batches fail independently, so one bad call costs
     * its own batch rather than the whole request.
     *
     * @param  list<string>  $skus
     * @return array<string, list<array<string, mixed>>>|null  null when the lookup failed
     */
    private function lookupShopify(ShopifyService $shopify, array $skus): ?array
    {
        try {
            // true, per findVariantsBySkus' own contract: this caller writes a
            // per-SKU verdict, so it must be told a lookup failed rather than
            // handed an empty result that reads as "none of these exist".
            return $shopify->findVariantsBySkus($skus, throwOnFailure: true);
        } catch (\Throwable $e) {
            Log::warning('SkuMappingService: Shopify lookup failed for ' . count($skus) . ' SKUs: ' . $e->getMessage());
            return null;
        }
    }
}
