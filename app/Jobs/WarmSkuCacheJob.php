<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\ShopifyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class WarmSkuCacheJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 10800;
    public int $tries   = 2;

    // Two warms for the same store must never overlap. Each one purges every
    // generation but its own, so a warm finishing mid-flight would delete the
    // rows another is still writing — leaving a sentinel pointing at an
    // incomplete generation, which reads back as "SKU not in Shopify" and
    // silently skips uploads. Lock for the full timeout so a killed worker
    // can't strand the lock either.
    public int $uniqueFor = 10800;

    public function __construct(public readonly int $storeId) {}

    public function uniqueId(): string
    {
        return (string) $this->storeId;
    }

    public function handle(): void
    {
        $store = Store::find($this->storeId);

        if (!$store || !$store->shopify_domain || !$store->shopify_access_token) {
            Log::warning("WarmSkuCacheJob: store {$this->storeId} not found or missing credentials — skipping.");
            return;
        }

        Log::info("WarmSkuCacheJob: warming SKU cache for store \"{$store->name}\"…");

        $shopify = new ShopifyService($store);
        $count   = $shopify->warmSkuCache();

        Log::info("WarmSkuCacheJob: completed for \"{$store->name}\" — {$count} variants cached.");
    }

    public function failed(\Throwable $e): void
    {
        Log::error("WarmSkuCacheJob failed for store {$this->storeId}: " . $e->getMessage());
    }
}
