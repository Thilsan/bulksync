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
                $results[] = ['sku' => $sku, 'status' => 'failed', 'message' => $this->safeUtf8($e->getMessage())];
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

    /**
     * Guzzle exceptions often embed the raw request/response body in their
     * message — if that body ever contains invalid UTF-8, storing it as-is
     * would make every later json response for this cache key throw. Strip
     * anything invalid so the status page never crashes on a bad exception.
     */
    private function safeUtf8(string $message): string
    {
        return mb_convert_encoding($message, 'UTF-8', 'UTF-8');
    }
}
