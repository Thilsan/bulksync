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

    public int $timeout = 10800;
    public int $tries   = 3;

    /**
     * SKUs per progress update. The lookup batches internally; this only decides
     * how often the session's counter moves, which is what the page polls.
     */
    private const PROGRESS_CHUNK = 200;

    public function __construct(public readonly int $sessionId) {}

    /**
     * A check asks about the SKUs somebody pasted, so it reads exactly those from
     * Shopify — batched into as few calls as the search allows.
     *
     * It used to warm the whole catalogue first for any list over 500, reading
     * every variant in the store to answer a few hundred questions, then reading
     * the answers back out of a cache. On the largest store that is 400k+
     * variants per run and around twenty minutes, and a cache under memory
     * pressure evicts entries while the sentinel still claims to be warm — which
     * reads back as "not in Shopify" and reports a SKU that exists as Not
     * Available. Asking Shopify directly is faster at every list size and cannot
     * be stale.
     */
    public function handle(): void
    {
        $session = SkuCheckSession::findOrFail($this->sessionId);
        $store   = $session->store_id
            ? \App\Models\Store::find($session->store_id)
            : \App\Models\Store::getActive($session->user_id);

        $shopify = app(ShopifyService::class, ['store' => $store]);

        $skus = array_filter(array_map('trim', explode("\n", $session->raw_skus)));
        $skus = array_values(array_unique($skus));

        $session->update([
            'status'     => 'running',
            'total_skus' => count($skus),
        ]);

        Log::info('RunSkuCheckJob: checking ' . count($skus) . " SKUs for session {$this->sessionId}");

        try {
            // Write results directly to CSV — no DB rows
            $dir = storage_path('app/sku-checks');
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $filePath = "{$dir}/{$this->sessionId}.csv";
            $handle   = fopen($filePath, 'w');
            fputcsv($handle, ['SKU', 'Status', 'Product ID', 'Product Name', 'Published']);

            $scanned      = 0;
            $available    = 0;
            $notAvailable = 0;

            foreach (array_chunk($skus, self::PROGRESS_CHUNK) as $chunk) {
                // true: a failed lookup must fail the session, not come back empty
                // and mark every SKU in the chunk Not Available.
                $found = $shopify->findVariantsBySkus($chunk, true);

                foreach ($chunk as $sku) {
                    $variants     = $found[$sku] ?? [];
                    $isAvail      = !empty($variants);
                    $productId    = $isAvail ? ($variants[0]['product_id'] ?? '') : '';
                    $productTitle = $isAvail ? ($variants[0]['product_title'] ?? '') : '';
                    $published    = $isAvail ? (($variants[0]['published'] ?? false) ? 'TRUE' : 'FALSE') : '';

                    fputcsv($handle, [
                        $sku,
                        $isAvail ? 'Available' : 'Not Available',
                        $productId,
                        $productTitle,
                        $published,
                    ]);

                    $isAvail ? $available++ : $notAvailable++;
                    $scanned++;
                }

                $session->update(['scanned_skus' => $scanned]);
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
            Log::error('RunSkuCheckJob failed: ' . $e->getMessage());
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
