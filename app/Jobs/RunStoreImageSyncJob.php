<?php

namespace App\Jobs;

use App\Models\Store;
use App\Models\StoreMigrationSession;
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

        if ($this->migrationType === 'full_product') {
            $this->runFullProductMode($source, $target, $handle, $total, $processed, $success, $failed);
        } else {
            $this->runImagesOnlyMode($source, $target, $handle, $total, $processed, $success, $failed);
        }

        fclose($handle);
        $this->updateProgress('completed', $total, $processed, $success, $failed);

        StoreMigrationSession::where('token', $this->token)->update([
            'status'        => 'completed',
            'success_count' => $success,
            'failed_count'  => $failed,
        ]);

        Log::info("RunStoreImageSyncJob: done — {$success} succeeded, {$failed} failed.");
    }

    /**
     * Images Only mode — unchanged from before: for each SKU, sync all of the
     * source product's images to the matching product in the target store.
     * Requires the SKU to already exist in the target store.
     */
    private function runImagesOnlyMode(ShopifyService $source, ShopifyService $target, $handle, int $total, int &$processed, int &$success, int &$failed): void
    {
        foreach ($this->skus as $sku) {
            $sourceVariants = $source->findVariantsBySku($sku);

            if (empty($sourceVariants)) {
                fputcsv($handle, [$sku, '', 0, 0, 'SKU not found in source store']);
                $processed++; $failed++;
                $this->updateProgress('running', $total, $processed, $success, $failed);
                continue;
            }

            $sourceProductId = $sourceVariants[0]['product_id'];
            $productTitle    = $sourceVariants[0]['product_title'] ?? '';

            $images = $source->getProductImages($sourceProductId);

            if (empty($images)) {
                fputcsv($handle, [$sku, $productTitle, 0, 0, 'No images in source store']);
                $processed++; $failed++;
                $this->updateProgress('running', $total, $processed, $success, $failed);
                continue;
            }

            $targetVariants = $target->findVariantsBySku($sku);

            if (empty($targetVariants)) {
                fputcsv($handle, [$sku, $productTitle, count($images), 0, 'SKU not found in target store']);
                $processed++; $failed++;
                $this->updateProgress('running', $total, $processed, $success, $failed);
                continue;
            }

            $targetProductId = $targetVariants[0]['product_id'];

            $copied = 0;
            foreach ($images as $image) {
                try {
                    $imgUrl = $image['src'] ?? '';
                    if (!$imgUrl) continue;

                    $cleanUrl = strtok($imgUrl, '?');
                    $filename = basename(parse_url($cleanUrl, PHP_URL_PATH));

                    $imageContent = @file_get_contents($imgUrl);
                    if ($imageContent === false || $imageContent === '') continue;

                    $target->uploadImageToProduct($targetProductId, $imageContent, $filename, $image['alt'] ?? '');
                    $copied++;
                } catch (\Throwable $e) {
                    Log::error("RunStoreImageSyncJob: failed to copy image for SKU {$sku}: " . $e->getMessage());
                }
            }

            $totalImages = count($images);
            if ($copied === $totalImages) {
                $statusMsg = 'Success';
                $success++;
            } elseif ($copied > 0) {
                $statusMsg = "Partial ({$copied}/{$totalImages})";
                $success++;
            } else {
                $statusMsg = 'Failed to copy images';
                $failed++;
            }

            fputcsv($handle, [$sku, $productTitle, $totalImages, $copied, $statusMsg]);
            $processed++;
            $this->updateProgress('running', $total, $processed, $success, $failed);
        }
    }

    /**
     * Full Product mode — groups the requested SKUs by their source product
     * FIRST, so a product with 5 variants only migrates the specific SKU(s)
     * you actually listed, never the sibling colors/sizes you didn't ask for.
     * SKUs that already exist in the target store are just image-synced
     * (same as Images Only), scoped to that one variant's own photo.
     */
    private function runFullProductMode(ShopifyService $source, ShopifyService $target, $handle, int $total, int &$processed, int &$success, int &$failed): void
    {
        $targetCollections = $target->getAllCollectionTitles();

        // Resolve every SKU against the source store up front and group by
        // product, so we know — before creating anything — exactly which
        // sibling SKUs of the same product were also requested in this batch.
        $skuToSourceVariant = [];
        $skusByProduct      = []; // sourceProductId => [sku, sku, ...] (only ones actually requested)

        foreach ($this->skus as $sku) {
            $sourceVariants = $source->findVariantsBySku($sku);

            if (empty($sourceVariants)) {
                fputcsv($handle, [$sku, '', 0, 0, 'SKU not found in source store']);
                $processed++; $failed++;
                $this->updateProgress('running', $total, $processed, $success, $failed);
                continue;
            }

            $skuToSourceVariant[$sku] = $sourceVariants[0];
            $skusByProduct[$sourceVariants[0]['product_id']][] = $sku;
        }

        foreach ($skusByProduct as $sourceProductId => $requestedSkus) {
            $productTitle      = $skuToSourceVariant[$requestedSkus[0]]['product_title'] ?? '';
            $sourceCollections = $skuToSourceVariant[$requestedSkus[0]]['collections'] ?? [];

            $missingSkus = [];

            foreach ($requestedSkus as $sku) {
                $targetVariants = $target->findVariantsBySku($sku);

                if (empty($targetVariants)) {
                    $missingSkus[] = $sku;
                    continue;
                }

                // Already exists in target — sync just this variant's own image, not the whole gallery
                $this->syncSingleVariantImage($source, $target, $sku, $skuToSourceVariant[$sku], $targetVariants[0], $handle, $productTitle);
                $processed++; $success++;
                $this->updateProgress('running', $total, $processed, $success, $failed);
            }

            if (empty($missingSkus)) {
                continue;
            }

            [$ok, $newProductId] = $this->migrateFullProduct($source, $target, $missingSkus, $sourceProductId, $productTitle, $sourceCollections, $targetCollections, $handle);

            foreach ($missingSkus as $sku) {
                $processed++;
                $ok ? $success++ : $failed++;
                $this->updateProgress('running', $total, $processed, $success, $failed);
            }
        }
    }

    /**
     * For a SKU whose product already exists in the target store: copy only
     * that variant's own photo (matched via the source variant's image_id),
     * not every colour/size photo the product has.
     */
    private function syncSingleVariantImage(ShopifyService $source, ShopifyService $target, string $sku, array $sourceVariant, array $targetVariant, $handle, string $productTitle): void
    {
        $sourceProductId = $sourceVariant['product_id'];
        $sourceVariantId = $sourceVariant['variant_id'];
        $targetProductId = $targetVariant['product_id'];
        $targetVariantId = $targetVariant['variant_id'];

        $sourceImages = $source->getProductImages($sourceProductId);

        // Find the specific image linked to this variant; fall back to the
        // first image if the product has no per-variant image assignment.
        $variantImage = null;
        foreach ($sourceImages as $img) {
            if (($img['variant_ids'] ?? null) && in_array((int) $sourceVariantId, $img['variant_ids'] ?? [], true)) {
                $variantImage = $img;
                break;
            }
        }
        $variantImage ??= $sourceImages[0] ?? null;

        if (!$variantImage) {
            fputcsv($handle, [$sku, $productTitle, 0, 0, 'No image found for this variant in source store']);
            return;
        }

        try {
            $imgUrl = $variantImage['src'] ?? '';
            if (!$imgUrl) {
                fputcsv($handle, [$sku, $productTitle, 1, 0, 'Variant image has no source URL']);
                return;
            }

            $cleanUrl = strtok($imgUrl, '?');
            $filename = basename(parse_url($cleanUrl, PHP_URL_PATH));

            $imageContent = @file_get_contents($imgUrl);
            if ($imageContent === false || $imageContent === '') {
                fputcsv($handle, [$sku, $productTitle, 1, 0, 'Failed to download variant image']);
                return;
            }

            $newImageId = $target->uploadImageToProduct($targetProductId, $imageContent, $filename, $variantImage['alt'] ?? '', $targetVariantId);

            if ($newImageId) {
                $target->setVariantImage($targetVariantId, $newImageId);
            }

            fputcsv($handle, [$sku, $productTitle, 1, 1, 'Success (variant image only)']);
        } catch (\Throwable $e) {
            Log::error("RunStoreImageSyncJob: failed to sync variant image for SKU {$sku}: " . $e->getMessage());
            fputcsv($handle, [$sku, $productTitle, 1, 0, 'Failed to copy variant image: ' . $e->getMessage()]);
        }
    }

    /**
     * Create the product from scratch in the target store, containing ONLY
     * the variant(s) whose SKU was requested — never the sibling colours/sizes
     * the source product also has. Always created as a draft. Copies
     * price/inventory as-is, plus tags, matching collections (by name, never
     * creates new ones), and Material/Features metafields.
     *
     * @return array{0: bool, 1: ?string} [success, new target product ID or null]
     */
    private function migrateFullProduct(
        ShopifyService $source,
        ShopifyService $target,
        array $requestedSkus,
        string $sourceProductId,
        string $productTitle,
        array $sourceCollections,
        array $targetCollections,
        $handle,
    ): array {
        $sourceProduct = $source->getFullProduct($sourceProductId);

        if (!$sourceProduct) {
            foreach ($requestedSkus as $sku) {
                fputcsv($handle, [$sku, $productTitle, 0, 0, 'Failed to fetch full product from source store']);
            }
            return [false, null];
        }

        // Keep only the variant(s) actually requested — not every sibling
        // colour/size the source product has.
        $requestedSkuSet  = array_flip(array_map('strtoupper', $requestedSkus));
        $allVariants      = $sourceProduct['variants'] ?? [];
        $filteredVariants = array_values(array_filter(
            $allVariants,
            fn ($v) => isset($requestedSkuSet[strtoupper($v['sku'] ?? '')])
        ));

        if (empty($filteredVariants)) {
            foreach ($requestedSkus as $sku) {
                fputcsv($handle, [$sku, $productTitle, 0, 0, 'Requested SKU variant not found on source product']);
            }
            return [false, null];
        }

        // Keep only images linked to the included variant(s); if none of them
        // have a specific image assigned, fall back to all product images
        // (covers simple products with no per-variant photo).
        $requestedImageIds = array_values(array_filter(array_map(fn ($v) => $v['image_id'] ?? null, $filteredVariants)));
        $allImages         = $sourceProduct['images'] ?? [];
        $filteredImages    = !empty($requestedImageIds)
            ? array_values(array_filter($allImages, fn ($img) => in_array($img['id'] ?? null, $requestedImageIds, true)))
            : $allImages;

        // Trim each option's value list down to only what the included variants actually use
        $filteredOptions = [];
        foreach ($sourceProduct['options'] ?? [] as $index => $option) {
            $key        = 'option' . ($index + 1);
            $usedValues = array_values(array_unique(array_filter(array_map(fn ($v) => $v[$key] ?? null, $filteredVariants))));
            if (!empty($usedValues)) {
                $filteredOptions[] = ['name' => $option['name'] ?? '', 'values' => $usedValues];
            }
        }

        $sourceProduct['variants'] = $filteredVariants;
        $sourceProduct['images']   = $filteredImages;
        $sourceProduct['options']  = $filteredOptions;

        try {
            $result       = $target->createFullProduct($sourceProduct);
            $newProductId = $result['product_id'];

            if (!$newProductId) {
                foreach ($requestedSkus as $sku) {
                    fputcsv($handle, [$sku, $productTitle, count($filteredImages), 0, 'Product creation returned no ID']);
                }
                return [false, null];
            }

            // Collections — matched by name against the target store's real list, additive only
            if (!empty($sourceCollections) && !empty($targetCollections)) {
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

            $imageCount  = count($filteredImages);
            $variantNote = count($filteredVariants) . ' variant(s)';
            foreach ($requestedSkus as $sku) {
                fputcsv($handle, [$sku, $productTitle, $imageCount, $imageCount, "New product created as draft with {$variantNote} (ID: {$newProductId})"]);
            }
            return [true, $newProductId];
        } catch (\Throwable $e) {
            Log::error("RunStoreImageSyncJob: full product migration failed for product {$sourceProductId}: " . $e->getMessage());
            foreach ($requestedSkus as $sku) {
                fputcsv($handle, [$sku, $productTitle, 0, 0, 'Failed to create product: ' . $e->getMessage()]);
            }
            return [false, null];
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

        StoreMigrationSession::where('token', $this->token)->update([
            'status'        => 'failed',
            'error_message' => $e->getMessage(),
        ]);

        Log::error("RunStoreImageSyncJob failed: " . $e->getMessage());
    }
}
