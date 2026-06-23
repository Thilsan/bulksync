<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\ShopifyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RunStoreImageSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 10800;
    public int $tries   = 1;

    public function __construct(
        public readonly string $token,
        public readonly int    $fromStoreId,
        public readonly int    $toStoreId,
        public readonly array  $skus,
    ) {}

    public function handle(): void
    {
        $fromStore = Store::findOrFail($this->fromStoreId);
        $toStore   = Store::findOrFail($this->toStoreId);

        $source = new ShopifyService($fromStore);
        $target = new ShopifyService($toStore);

        $dir = storage_path('app/store-sync');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filePath = "{$dir}/{$this->token}.csv";
        $handle   = fopen($filePath, 'w');
        fputcsv($handle, ['SKU', 'Product Name', 'Images Found', 'Images Copied', 'Status']);

        $total     = count($this->skus);
        $processed = 0;
        $success   = 0;
        $failed    = 0;

        $this->updateProgress('running', $total, 0, 0, 0);

        Log::info("RunStoreImageSyncJob: syncing {$total} SKUs from store {$this->fromStoreId} to {$this->toStoreId}");

        foreach ($this->skus as $sku) {
            // Find product in source store
            $sourceVariants = $source->findVariantsBySku($sku);

            if (empty($sourceVariants)) {
                fputcsv($handle, [$sku, '', 0, 0, 'SKU not found in source store']);
                $processed++; $failed++;
                $this->updateProgress('running', $total, $processed, $success, $failed);
                continue;
            }

            $sourceProductId = $sourceVariants[0]['product_id'];
            $productTitle    = $sourceVariants[0]['product_title'] ?? '';

            // Get images from source store
            $images = $source->getProductImages($sourceProductId);

            if (empty($images)) {
                fputcsv($handle, [$sku, $productTitle, 0, 0, 'No images in source store']);
                $processed++; $failed++;
                $this->updateProgress('running', $total, $processed, $success, $failed);
                continue;
            }

            // Find product in target store
            $targetVariants = $target->findVariantsBySku($sku);

            if (empty($targetVariants)) {
                fputcsv($handle, [$sku, $productTitle, count($images), 0, 'SKU not found in target store']);
                $processed++; $failed++;
                $this->updateProgress('running', $total, $processed, $success, $failed);
                continue;
            }

            $targetProductId = $targetVariants[0]['product_id'];

            // Download each image from source and upload to target
            $copied = 0;
            foreach ($images as $image) {
                try {
                    $imgUrl  = $image['src'] ?? '';
                    if (!$imgUrl) continue;

                    // Strip query string from Shopify CDN URL to get clean filename
                    $cleanUrl = strtok($imgUrl, '?');
                    $filename = basename(parse_url($cleanUrl, PHP_URL_PATH));

                    $imageContent = @file_get_contents($imgUrl);
                    if ($imageContent === false || $imageContent === '') continue;

                    $target->uploadImageToProduct(
                        $targetProductId,
                        $imageContent,
                        $filename,
                        $image['alt'] ?? ''
                    );
                    $copied++;
                } catch (\Throwable $e) {
                    Log::error("RunStoreImageSyncJob: failed to copy image for SKU {$sku}: " . $e->getMessage());
                }
            }

            $total_images = count($images);
            if ($copied === $total_images) {
                $statusMsg = 'Success';
                $success++;
            } elseif ($copied > 0) {
                $statusMsg = "Partial ({$copied}/{$total_images})";
                $success++;
            } else {
                $statusMsg = 'Failed to copy images';
                $failed++;
            }

            fputcsv($handle, [$sku, $productTitle, $total_images, $copied, $statusMsg]);
            $processed++;
            $this->updateProgress('running', $total, $processed, $success, $failed);
        }

        fclose($handle);
        $this->updateProgress('completed', $total, $processed, $success, $failed);

        Log::info("RunStoreImageSyncJob: done — {$success} succeeded, {$failed} failed.");
    }

    private function updateProgress(string $status, int $total, int $processed, int $success, int $failed): void
    {
        Cache::put("store_sync_{$this->token}", [
            'status'    => $status,
            'total'     => $total,
            'processed' => $processed,
            'success'   => $success,
            'failed'    => $failed,
        ], now()->addHours(2));
    }

    public function failed(\Throwable $e): void
    {
        Cache::put("store_sync_{$this->token}", [
            'status'    => 'failed',
            'total'     => count($this->skus),
            'processed' => 0,
            'success'   => 0,
            'failed'    => 0,
            'error'     => $e->getMessage(),
        ], now()->addHours(2));

        Log::error("RunStoreImageSyncJob failed: " . $e->getMessage());
    }
}
