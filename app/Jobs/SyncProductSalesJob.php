<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\ProductSalesSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The nightly read behind the Product Performance page.
 *
 * Without a store it only fans out — one job per connected website — so a slow
 * or broken store cannot hold up the rest, and each gets the full timeout. Unique
 * per store, because Shopify allows one bulk export per shop at a time and a
 * second copy would only sit waiting for the first.
 */
class SyncProductSalesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries   = 1;
    public int $uniqueFor = 3600;

    public function __construct(public ?int $storeId = null, public bool $full = false)
    {
        $this->onQueue('maintenance');
    }

    public function uniqueId(): string
    {
        return (string) ($this->storeId ?? 'all');
    }

    public function handle(): void
    {
        if ($this->storeId === null) {
            Store::whereNotNull('shopify_domain')
                ->whereNotNull('shopify_access_token')
                ->pluck('id')
                ->each(fn (int $id) => self::dispatch($id, $this->full));

            return;
        }

        $store = Store::find($this->storeId);

        if (!$store) {
            return;
        }

        try {
            $this->service()->sync($store, $this->full);
        } catch (\Throwable $e) {
            Log::warning('Product sales sync failed', ['store' => $store->id, 'error' => $e->getMessage()]);

            // Shown on the page, so a store that has quietly stopped updating
            // says why rather than just looking stale.
            $store->forceFill(['sales_sync_error' => mb_substr($e->getMessage(), 0, 1000)])->save();
        }
    }

    /** Seam: overridden in tests so nothing reaches Shopify. */
    protected function service(): ProductSalesSyncService
    {
        return app(ProductSalesSyncService::class);
    }
}
