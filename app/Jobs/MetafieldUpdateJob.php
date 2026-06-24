<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\ShopifyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MetafieldUpdateJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;
    public int $tries   = 1;

    public function __construct(
        public readonly string $cacheKey,
        public readonly int $storeId,
        public readonly array $rows,
    ) {}

    public function handle(): void
    {
        $store = Store::find($this->storeId);
        if (!$store) {
            Cache::put($this->cacheKey, ['status' => 'failed', 'error' => 'Store not found.'], 3600);
            return;
        }

        $shopify = new ShopifyService($store);
        $total   = count($this->rows);
        $results = [];

        Cache::put($this->cacheKey, [
            'status'    => 'processing',
            'total'     => $total,
            'processed' => 0,
            'results'   => [],
        ], 3600);

        foreach ($this->rows as $index => $row) {
            $sku      = strtoupper(trim($row['sku'] ?? ''));
            $material = trim($row['material'] ?? '');
            $features = trim($row['features'] ?? '');

            if (!$sku) continue;

            try {
                $variants = $shopify->findVariantsBySku($sku);

                if (empty($variants)) {
                    $results[] = ['sku' => $sku, 'status' => 'not_found', 'message' => 'SKU not found in Shopify'];
                } else {
                    $productId = $variants[0]['product_id'];
                    $shopify->updateProductMetafields($productId, $material, $features);
                    $results[] = ['sku' => $sku, 'status' => 'updated', 'message' => 'Updated successfully'];
                }
            } catch (\Throwable $e) {
                Log::warning('MetafieldUpdateJob item failed', ['sku' => $sku, 'error' => $e->getMessage()]);
                $results[] = ['sku' => $sku, 'status' => 'failed', 'message' => $e->getMessage()];
            }

            Cache::put($this->cacheKey, [
                'status'    => 'processing',
                'total'     => $total,
                'processed' => $index + 1,
                'results'   => $results,
            ], 3600);
        }

        Cache::put($this->cacheKey, [
            'status'    => 'done',
            'total'     => $total,
            'processed' => $total,
            'results'   => $results,
        ], 3600);
    }
}
