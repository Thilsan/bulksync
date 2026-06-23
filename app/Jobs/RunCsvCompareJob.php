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

class RunCsvCompareJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 10800;
    public int $tries   = 3;

    public function __construct(public readonly int $sessionId) {}

    public function handle(): void
    {
        $session = SkuCheckSession::findOrFail($this->sessionId);
        $store   = $session->store_id
            ? \App\Models\Store::find($session->store_id)
            : \App\Models\Store::getActive($session->user_id);

        $shopify = new ShopifyService($store);

        $mySkus = array_filter(array_map('trim', explode("\n", $session->raw_skus)));
        $mySkus = array_values(array_unique($mySkus));

        $session->update([
            'status'     => 'running',
            'total_skus' => count($mySkus),
        ]);

        Log::info("RunCsvCompareJob: comparing " . count($mySkus) . " SKUs against full Shopify catalogue for session {$this->sessionId}");

        try {
            // Fetch the full Shopify SKU set in one bulk sweep
            $shopifySkus = $shopify->getAllShopifySkuSet();
            Log::info("RunCsvCompareJob: received " . count($shopifySkus) . " unique Shopify SKUs.");

            $dir = storage_path('app/sku-checks');
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $filePath = "{$dir}/{$this->sessionId}.csv";
            $handle   = fopen($filePath, 'w');
            fputcsv($handle, ['SKU', 'Status']);

            $available    = 0;
            $notAvailable = 0;

            foreach ($mySkus as $i => $sku) {
                $isAvail = isset($shopifySkus[$sku]);
                fputcsv($handle, [$sku, $isAvail ? 'Available' : 'Not Available']);
                $isAvail ? $available++ : $notAvailable++;

                if (($i + 1) % 1000 === 0) {
                    $session->update(['scanned_skus' => $i + 1]);
                }
            }

            fclose($handle);

            $session->update([
                'status'              => 'completed',
                'scanned_skus'        => count($mySkus),
                'total_skus'          => count($mySkus),
                'available_count'     => $available,
                'not_available_count' => $notAvailable,
                'raw_skus'            => null,
            ]);

            Log::info("RunCsvCompareJob: done — {$available} available, {$notAvailable} not found.");

        } catch (\Throwable $e) {
            Log::error("RunCsvCompareJob failed: " . $e->getMessage());
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
