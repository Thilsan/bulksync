<?php

namespace App\Jobs;

use App\Models\SkuCheckSession;
use App\Services\ShopifyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunSkuCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 10800; // 3 hours — large stores need long cache warming
    public int $tries   = 3;

    public function __construct(public readonly int $sessionId) {}

    public function handle(): void
    {
        $session = SkuCheckSession::findOrFail($this->sessionId);
        $store   = $session->store_id
            ? \App\Models\Store::find($session->store_id)
            : \App\Models\Store::getActive($session->user_id);

        $shopify = new ShopifyService($store);

        $skus = array_filter(array_map('trim', explode("\n", $session->raw_skus)));
        $skus = array_values(array_unique($skus));

        $session->update([
            'status'     => 'running',
            'total_skus' => count($skus),
        ]);

        Log::info("RunSkuCheckJob: checking " . count($skus) . " SKUs for session {$this->sessionId}");

        try {
            $skuCount = count($skus);

            if ($skuCount > 500) {
                if (!$shopify->isSkuCacheWarmed()) {
                    Log::info("RunSkuCheckJob: large batch ({$skuCount} SKUs) — warming SKU cache first…");
                    $shopify->warmSkuCache();
                    Log::info("RunSkuCheckJob: SKU cache ready.");
                } else {
                    Log::info("RunSkuCheckJob: SKU cache already warm — skipping.");
                }
            } else {
                Log::info("RunSkuCheckJob: small batch ({$skuCount} SKUs) — using live lookups directly.");
            }

            // Write results directly to CSV — no DB rows
            $dir = storage_path('app/sku-checks');
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $filePath = "{$dir}/{$this->sessionId}.csv";
            $handle   = fopen($filePath, 'w');
            fputcsv($handle, ['SKU', 'Status', 'Product ID']);

            $scanned      = 0;
            $available    = 0;
            $notAvailable = 0;

            foreach ($skus as $sku) {
                $variants  = $shopify->findVariantsBySkuCached($sku);
                $isAvail   = !empty($variants);
                $productId = $isAvail ? $variants[0]['product_id'] : '';

                fputcsv($handle, [
                    $sku,
                    $isAvail ? 'Available' : 'Not Available',
                    $productId,
                ]);

                $isAvail ? $available++ : $notAvailable++;
                $scanned++;

                if ($scanned % 500 === 0) {
                    $session->update(['scanned_skus' => $scanned]);
                }
            }

            fclose($handle);

            $session->update([
                'status'              => 'completed',
                'scanned_skus'        => $scanned,
                'total_skus'          => $scanned,
                'available_count'     => $available,
                'not_available_count' => $notAvailable,
                'raw_skus'            => null,
            ]);

            Log::info("RunSkuCheckJob: done — {$available} available, {$notAvailable} not found.");

        } catch (\Throwable $e) {
            Log::error("RunSkuCheckJob failed: " . $e->getMessage());
            $session->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        }
    }

    public function failed(\Throwable $e): void
    {
        SkuCheckSession::where('id', $this->sessionId)->update([
            'status'        => 'failed',
            'error_message' => $e->getMessage(),
        ]);
    }
}
