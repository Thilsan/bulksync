<?php

namespace App\Services;

use App\Models\ProductRequest;
use App\Models\ProductRequestSku;
use App\Models\Store;
use Illuminate\Support\Facades\Log;

/**
 * Resolves every SKU on a Product Creation Request against Cegid and Shopify
 * and rolls the result up onto the request.
 *
 * Legend the brand team works to:
 *   🟢 Mapped        — present in Cegid AND Shopify
 *   🟡 Pending       — present in one of them; Supply Chain still finishing
 *   🔴 Not Mapped    — present in neither
 */
class SkuMappingService
{
    public function __construct(private readonly CegidService $cegid = new CegidService()) {}

    /**
     * Re-resolve every SKU on the request and update the roll-up counters.
     * Safe to call repeatedly — that is exactly what the hourly re-check does.
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

            $cegidMap = $this->cegid->lookup($rows->pluck('sku')->all());
            $shopify  = $this->shopifyFor($request);

            foreach ($rows as $row) {
                $inCegid = $cegidMap[$row->sku] ?? null;

                // Manual entry wins when there is no automatic Cegid lookup:
                // Supply Chain ticking the box is the only signal we have.
                if ($inCegid === null && !$this->cegid->isConfigured()) {
                    $inCegid = $row->in_cegid;
                }

                $variants  = $shopify ? $this->lookupShopify($shopify, $row->sku) : [];
                $inShopify = !empty($variants);

                $row->update([
                    'in_cegid'              => $inCegid,
                    'in_shopify'            => $inShopify,
                    'shopify_product_id'    => $inShopify ? ($variants[0]['product_id'] ?? null) : null,
                    'shopify_product_title' => $inShopify ? ($variants[0]['product_title'] ?? null) : null,
                    'shopify_published'     => $inShopify ? (bool) ($variants[0]['published'] ?? false) : null,
                    'mapping_status'        => $this->resolveStatus($inCegid, $inShopify),
                    'last_checked_at'       => now(),
                ]);
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
     * Cegid answer + Shopify presence → badge colour.
     *
     * Cegid is the authority here, not Shopify. The whole point of the module is
     * to prepare content BEFORE the product exists online, so a SKU is normally
     * absent from Shopify for most of the workflow — gating on Shopify would
     * park every request in Waiting for Mapping forever.
     *
     * Shopify presence is only used as positive evidence: if the product is
     * already live, mapping is plainly done whatever Cegid says.
     *
     * A null Cegid answer means "nobody has told us yet" — not "absent" — so it
     * reads as Pending (with Supply Chain), never as a red Not Mapped.
     */
    public function resolveStatus(?bool $inCegid, bool $inShopify): string
    {
        if ($inCegid === true || $inShopify) {
            return ProductRequest::MAP_MAPPED;
        }

        if ($inCegid === false) {
            return ProductRequest::MAP_NOT_MAPPED;
        }

        return ProductRequest::MAP_PENDING;
    }

    /** Recompute the denormalised counters the dashboard reads. */
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

    /** Replace the SKU list on a request, keeping existing rows' resolved state. */
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
            Log::warning("SkuMappingService: no store for request {$request->id} — Shopify side skipped.");
            return null;
        }

        return new ShopifyService($store);
    }

    /** A Shopify hiccup must not fail the whole request — treat it as "not found". */
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
