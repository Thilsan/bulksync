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
        public readonly string $migrationType = 'images_only', // 'images_only' or 'full_product'
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
                if ($this->migrationType === 'full_product') {
                    $sourceCollections = $sourceVariants[0]['collections'] ?? [];
                    $ok = $this->migrateFullProduct($source, $target, $sku, $sourceProductId, $productTitle, $sourceCollections, $handle);
                    $processed++;
                    $ok ? $success++ : $failed++;
                    $this->updateProgress('running', $total, $processed, $success, $failed);
                } else {
                    fputcsv($handle, [$sku, $productTitle, count($images), 0, 'SKU not found in target store']);
                    $processed++; $failed++;
                    $this->updateProgress('running', $total, $processed, $success, $failed);
                }
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

    /**
     * Create the product from scratch in the target store — used only when
     * the SKU doesn't exist there at all. Always created as a draft. Copies
     * price/inventory/variants/images as-is, plus tags, matching collections
     * (by name, never creates new ones), and Material/Features metafields.
     */
    private function migrateFullProduct(
        ShopifyService $source,
        ShopifyService $target,
        string $sku,
        string $sourceProductId,
        string $productTitle,
        array $sourceCollections,
        $handle,
    ): bool {
        $sourceProduct = $source->getFullProduct($sourceProductId);

        if (!$sourceProduct) {
            fputcsv($handle, [$sku, $productTitle, 0, 0, 'Failed to fetch full product from source store']);
            return false;
        }

        try {
            $result       = $target->createFullProduct($sourceProduct);
            $newProductId = $result['product_id'];

            if (!$newProductId) {
                fputcsv($handle, [$sku, $productTitle, count($sourceProduct['images'] ?? []), 0, 'Product creation returned no ID']);
                return false;
            }

            // Collections — matched by name against the target store's real list, additive only
            if (!empty($sourceCollections)) {
                $targetCollections = $target->getAllCollectionTitles();
                $target->addProductToCollections($newProductId, $sourceCollections, $targetCollections);
            }

            // Material/Features metafields
            $materialAndFeatures = $source->getProductMaterialAndFeatures($sourceProductId);
            if ($materialAndFeatures['material'] || !empty($materialAndFeatures['features'])) {
                $target->updateProductMetafields(
                    $newProductId,
                    $materialAndFeatures['material'],
                    implode(', ', $materialAndFeatures['features']),
                );
            }

            $imageCount = count($sourceProduct['images'] ?? []);
            fputcsv($handle, [$sku, $productTitle, $imageCount, $imageCount, "New product created as draft (ID: {$newProductId})"]);
            return true;
        } catch (\Throwable $e) {
            Log::error("RunStoreImageSyncJob: full product migration failed for SKU {$sku}: " . $e->getMessage());
            fputcsv($handle, [$sku, $productTitle, 0, 0, 'Failed to create product: ' . $e->getMessage()]);
            return false;
        }
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
