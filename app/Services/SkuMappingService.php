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

            foreach ($rows as $row) {
                $variants  = $shopify ? $this->lookupShopify($shopify, $row->sku) : [];
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

            $this->rollUp($request, 'completed');

        } catch (\Throwable $e) {
            Log::error("SkuMappingService: validation failed for request {$request->id}: " . $e->getMessage());

            $request->update([
                'validation_status' => 'failed',
                'validation_error'  => $e->getMessage(),
            ]);
        }
    }

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
    public function rollUp(ProductRequest $request, ?string $validationStatus = null): void
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
        ], fn ($v) => $v !== null));
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

        return new ShopifyService($store);
    }

    /**
     * Read-only lookup. A Shopify hiccup must not fail the whole request, so a
     * failure reads as "not found" — the SKU stays pending and the next run,
     * hourly or on the button, picks it up.
     */
    private function lookupShopify(ShopifyService $shopify, string $sku): array
    {
        try {
            return $shopify->findVariantsBySkuCached($sku);
        } catch (\Throwable $e) {
            Log::warning("SkuMappingService: Shopify lookup failed for {$sku}: " . $e->getMessage());
            return [];
        }
    }
}
