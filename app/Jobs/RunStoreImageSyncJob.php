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
     * Images Only mode — for each SKU, sync only that variant's own photos
     * (same resolution as Full Product mode), not the whole product's gallery
     * across every colour/size. Requires the SKU to already exist in the
     * target store.
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
            $sourceVariantId = $sourceVariants[0]['variant_id'];
            $productTitle    = $sourceVariants[0]['product_title'] ?? '';

            $images = $this->resolveVariantImages($source, $sourceProductId, $sourceVariantId);

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
            $targetVariantId = $targetVariants[0]['variant_id'];

            $copied    = 0;
            $primaryId = null;
            foreach ($images as $image) {
                try {
                    $imgUrl = $image['src'] ?? '';
                    if (!$imgUrl) continue;

                    $cleanUrl = strtok($imgUrl, '?');
                    $filename = basename(parse_url($cleanUrl, PHP_URL_PATH));

                    $imageContent = @file_get_contents($imgUrl);
                    if ($imageContent === false || $imageContent === '') continue;

                    // uploadImageToProduct() tags the new image with $targetVariantId itself,
                    // so every uploaded photo is already linked to this variant on upload.
                    $newImageId = $target->uploadImageToProduct($targetProductId, $imageContent, $filename, $image['alt'] ?? '', $targetVariantId);

                    if ($newImageId) {
                        $primaryId ??= $newImageId;
                        $copied++;
                    }
                } catch (\Throwable $e) {
                    Log::error("RunStoreImageSyncJob: failed to copy image for SKU {$sku}: " . $e->getMessage());
                }
            }

            if ($primaryId) {
                $target->setVariantImage($targetVariantId, $primaryId);
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

                // Already exists in target — sync just this variant's own gallery photos, not the whole product
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
     * For a SKU whose product already exists in the target store: copy every
     * gallery photo belonging to that variant (a colour/size can have several,
     * not just one), never the sibling colours/sizes' photos.
     */
    private function syncSingleVariantImage(ShopifyService $source, ShopifyService $target, string $sku, array $sourceVariant, array $targetVariant, $handle, string $productTitle): void
    {
        $sourceProductId = $sourceVariant['product_id'];
        $sourceVariantId = $sourceVariant['variant_id'];
        $targetProductId = $targetVariant['product_id'];
        $targetVariantId = $targetVariant['variant_id'];

        $variantImages = $this->resolveVariantImages($source, $sourceProductId, $sourceVariantId);

        if (empty($variantImages)) {
            fputcsv($handle, [$sku, $productTitle, 0, 0, 'No image found for this variant in source store']);
            return;
        }

        $total     = count($variantImages);
        $copied    = 0;
        $primaryId = null;

        foreach ($variantImages as $variantImage) {
            try {
                $imgUrl = $variantImage['src'] ?? '';
                if (!$imgUrl) continue;

                $cleanUrl = strtok($imgUrl, '?');
                $filename = basename(parse_url($cleanUrl, PHP_URL_PATH));

                $imageContent = @file_get_contents($imgUrl);
                if ($imageContent === false || $imageContent === '') continue;

                // uploadImageToProduct() tags the new image with $targetVariantId itself,
                // so every uploaded photo is already linked to this variant on upload.
                $newImageId = $target->uploadImageToProduct($targetProductId, $imageContent, $filename, $variantImage['alt'] ?? '', $targetVariantId);

                if ($newImageId) {
                    $primaryId ??= $newImageId;
                    $copied++;
                }
            } catch (\Throwable $e) {
                Log::error("RunStoreImageSyncJob: failed to sync variant image for SKU {$sku}: " . $e->getMessage());
            }
        }

        if ($primaryId) {
            $target->setVariantImage($targetVariantId, $primaryId);
        }

        $statusMsg = $copied === $total ? 'Success (variant images)' : ($copied > 0 ? "Partial ({$copied}/{$total})" : 'Failed to copy variant images');
        fputcsv($handle, [$sku, $productTitle, $total, $copied, $statusMsg]);
    }

    /**
     * Resolve the photos belonging to just one variant, trying the most
     * authoritative source first:
     *   1) Shopify's variant media assignment (what that variant's own edit
     *      page in Shopify Admin shows) — a colour can have several photos.
     *   2) the classic image_id anchor → next-anchor position block, for
     *      stores that never adopted per-variant media assignment.
     *   3) the product's first image, if it has no per-variant data at all.
     */
    private function resolveVariantImages(ShopifyService $source, string $productId, string $variantId): array
    {
        $variantImages = $source->getVariantMedia($variantId);
        if (!empty($variantImages)) {
            return $variantImages;
        }

        $sourceProduct = $source->getFullProduct($productId);
        $allVariants   = $sourceProduct['variants'] ?? [];
        $allImages     = $sourceProduct['images'] ?? [];

        $imageBlocksByVariant = $this->partitionImagesByVariant($allVariants, $allImages);
        $variantImages        = $imageBlocksByVariant[(string) $variantId] ?? [];

        if (empty($variantImages) && empty($imageBlocksByVariant) && !empty($allImages)) {
            $variantImages = [$allImages[0]];
        }

        return $variantImages;
    }

    /**
     * Partition a product's images into contiguous per-variant photo blocks.
     * Merchants typically upload one variant's whole photo set as a single
     * consecutive run and only tag ONE photo in that run to the variant (its
     * image_id) — the rest of the run is untagged. So a variant's full photo
     * set is: from its own tagged image's position, up to (not including) the
     * next variant's tagged position.
     *
     * @param array $allVariants every variant of the product, each with 'id' and 'image_id'
     * @param array $allImages   every image of the product, position-sorted, each with 'id'
     * @return array<string,array> variantId => images belonging to that variant; empty if
     *         the product has no per-variant tagging at all
     */
    private function partitionImagesByVariant(array $allVariants, array $allImages): array
    {
        $imageList        = array_values($allImages);
        $ordinalByImageId = [];
        foreach ($imageList as $ordinal => $img) {
            $ordinalByImageId[(string) ($img['id'] ?? '')] = $ordinal;
        }

        $anchors = []; // ordinal => variantId
        foreach ($allVariants as $variant) {
            $variantId = (string) ($variant['id'] ?? '');
            $imageId   = (string) ($variant['image_id'] ?? '');
            if ($variantId !== '' && $imageId !== '' && isset($ordinalByImageId[$imageId])) {
                $anchors[$ordinalByImageId[$imageId]] = $variantId;
            }
        }

        if (empty($anchors)) {
            return [];
        }

        ksort($anchors);
        $anchorOrdinals = array_keys($anchors);
        $total          = count($imageList);

        $blocks = [];
        foreach ($anchors as $ordinal => $variantId) {
            $next = $total;
            foreach ($anchorOrdinals as $o) {
                if ($o > $ordinal) { $next = $o; break; }
            }
            $blocks[$variantId] = array_slice($imageList, $ordinal, $next - $ordinal);
        }

        return $blocks;
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

        // For each included variant, prefer Shopify's variant media assignment
        // (the authoritative "this colour's photos" list); fall back to the
        // classic image_id anchor → next-anchor position block for products
        // that never adopted per-variant media assignment.
        $allImages            = $sourceProduct['images'] ?? [];
        $imageBlocksByVariant = $this->partitionImagesByVariant($allVariants, $allImages);

        $filteredImages     = [];
        $seenImageIds       = [];
        $anyVariantMedia    = false;
        foreach ($filteredVariants as $v) {
            $variantId = (string) ($v['id'] ?? '');
            $media     = $source->getVariantMedia($variantId);

            if (!empty($media)) {
                $anyVariantMedia = true;
            } else {
                $media = $imageBlocksByVariant[$variantId] ?? [];
            }

            foreach ($media as $img) {
                $imgId = (string) ($img['id'] ?? '');
                if (!isset($seenImageIds[$imgId])) {
                    $seenImageIds[$imgId] = true;
                    $img['variant_ids'] = [(int) $variantId];
                    $filteredImages[] = $img;
                }
            }
        }

        // No variant had media assignment AND no image_id tagging at all
        // (simple product, single photo set) — use everything.
        if (!$anyVariantMedia && empty($imageBlocksByVariant)) {
            $filteredImages = $allImages;
        }

        usort($filteredImages, fn ($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));

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
