<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\ProductSalesSyncService;
use Illuminate\Console\Command;

/**
 * Runs the Product Performance sync in the foreground, so the result or the
 * error is on screen rather than in a log. The nightly schedule does the same
 * work through the queue.
 */
class SyncProductSales extends Command
{
    protected $signature = 'product-sales:sync
                            {--store= : Only this store id}
                            {--full : Re-read the whole year, not just the last two weeks}';

    protected $description = 'Read each website\'s catalogue and product sales from Shopify for the Product Performance page';

    public function handle(ProductSalesSyncService $service): int
    {
        $stores = Store::whereNotNull('shopify_domain')
            ->whereNotNull('shopify_access_token')
            ->when($this->option('store'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('name')
            ->get();

        if ($stores->isEmpty()) {
            $this->warn('No connected store matches.');
            return self::FAILURE;
        }

        $failed = 0;

        foreach ($stores as $store) {
            $this->line("<info>{$store->name}</info> (#{$store->id}) …");

            try {
                $result = $service->sync($store, (bool) $this->option('full'));
                $store->refresh();

                $this->line(sprintf(
                    '  %s products, %s product-days of sales from %s. Data covers from %s.',
                    number_format($result['products']),
                    number_format($result['sales_rows']),
                    $result['from'],
                    $store->sales_covered_from?->toDateString() ?? '—',
                ));
            } catch (\Throwable $e) {
                $failed++;
                $store->forceFill(['sales_sync_error' => mb_substr($e->getMessage(), 0, 1000)])->save();
                $this->error('  ' . $e->getMessage());
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
