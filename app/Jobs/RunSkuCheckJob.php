<?php

namespace App\Jobs;

use App\Models\SkuCheckItem;
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

    public int $timeout = 3600;
    public int $tries   = 1;

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
            // Warm cache if not already warm (fast lookup for all SKUs)
            if (!$shopify->isSkuCacheWarmed()) {
                $productCount = $shopify->getProductCount();
                if ($productCount < 10000) {
                    Log::info("RunSkuCheckJob: warming SKU cache first…");
                    $shopify->warmSkuCache();
                }
            }

            $buffer        = [];
            $scanned       = 0;
            $available     = 0;
            $notAvailable  = 0;

            foreach ($skus as $sku) {
                $variants = $shopify->findVariantsBySkuCached($sku);

                $buffer[] = [
                    'sku_check_session_id' => $session->id,
                    'sku'                  => $sku,
                    'available'            => !empty($variants),
                    'product_title'        => !empty($variants) ? $variants[0]['product_title'] : '',
                    'product_id'           => !empty($variants) ? $variants[0]['product_id'] : '',
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ];

                !empty($variants) ? $available++ : $notAvailable++;
                $scanned++;

                if (count($buffer) >= 500) {
                    SkuCheckItem::insert($buffer);
                    $buffer = [];
                    $session->update(['scanned_skus' => $scanned]);
                }
            }

            if (!empty($buffer)) {
                SkuCheckItem::insert($buffer);
            }

            $session->update([
                'status'             => 'completed',
                'scanned_skus'       => $scanned,
                'total_skus'         => $scanned,
                'available_count'    => $available,
                'not_available_count'=> $notAvailable,
                'raw_skus'           => null, // free up space
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
