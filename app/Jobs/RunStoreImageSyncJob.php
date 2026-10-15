<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsInSlices;
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
    use Dispatchable, InteractsWithQueue, Queueable, RunsInSlices, SerializesModels;

    // One slice: a couple of minutes of SKUs, plus room for the product in
    // hand to finish (see RunsInSlices).
    public int $timeout = 1800;
    public int $tries   = 1;

    /**
     * @param array|null $resume where the previous slice of this run stopped:
     *                           phase, offset, running counts and, in Full
     *                           Product mode, the SKUs resolved so far. Null
     *                           starts the run.
     */
    public function __construct(
        public readonly string $token,
        public readonly int    $fromStoreId,
        public readonly int    $toStoreId,
        public readonly array  $skus,
        public readonly string $migrationType = 'images_only', // 'images_only' or 'full_product'
        public readonly ?array $resume = null,
        // Full Product only: make a product the target already has a copy of
        // the source one, instead of just adding its photos.
        public readonly bool   $overwriteExisting = false,
    ) {}

    /** Counts and position, carried from slice to slice. */
    private array $state;

    public function handle(): void
    {
        $this->startSlice();

        $fromStore = Store::findOrFail($this->fromStoreId);
        $toStore   = Store::findOrFail($this->toStoreId);

        // Through the container so tests can stand in for each store.
        $source = app()->makeWith(ShopifyService::class, ['store' => $fromStore]);
        $target = app()->makeWith(ShopifyService::class, ['store' => $toStore]);

        $dir = storage_path('app/store-sync');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filePath = "{$dir}/{$this->token}.csv";
        $total    = count($this->skus);

        if ($this->resume === null) {
            $handle = fopen($filePath, 'w');
            fputcsv($handle, ['SKU', 'Product Name', 'Images Found', 'Images Copied', 'Status']);

            $this->state = ['phase' => 'resolve', 'offset' => 0, 'processed' => 0, 'success' => 0, 'failed' => 0, 'resolved' => []];
            $this->updateProgress('running', $total, 0, 0, 0);

            Log::info("RunStoreImageSyncJob: syncing {$total} SKUs from store {$this->fromStoreId} to {$this->toStoreId}");
        } else {
            $handle      = fopen($filePath, 'a');
            $this->state = $this->resume;
        }

        $finished = $this->migrationType === 'full_product'
            ? $this->runFullProductMode($source, $target, $handle, $total)
            : $this->runImagesOnlyMode($source, $target, $handle, $total);

        fclose($handle);

        if (!$finished) {
            $this->handOver(new static($this->token, $this->fromStoreId, $this->toStoreId, $this->skus, $this->migrationType, $this->state, $this->overwriteExisting));

            return;
        }

        ['processed' => $processed, 'success' => $success, 'failed' => $failed] = $this->state;

        $this->updateProgress('completed', $total, $processed, $success, $failed);

        StoreMigrationSession::where('token', $this->token)->update([
            'status'        => 'completed',
            'success_count' => $success,
            'failed_count'  => $failed,
        ]);

        Log::info("RunStoreImageSyncJob: done — {$success} succeeded, {$failed} failed.");
    }

    /** Count one SKU's outcome and publish the progress. */
    private function tally(int $total, bool $ok): void
    {
        $this->state['processed']++;
        $ok ? $this->state['success']++ : $this->state['failed']++;

        $this->updateProgress('running', $total, $this->state['processed'], $this->state['success'], $this->state['failed']);
    }

    /**
     * Images Only mode — for each SKU, sync only that variant's own photos
     * (same resolution as Full Product mode), not the whole product's gallery
     * across every colour/size. Requires the SKU to already exist in the
     * target store.
     *
     * @return bool true when every SKU is done, false when the slice ran out first
     */
    private function runImagesOnlyMode(ShopifyService $source, ShopifyService $target, $handle, int $total): bool
    {
        for ($i = $this->state['offset']; $i < $total; $i++) {
            $this->syncImagesOnly($source, $target, $handle, $total, $this->skus[$i]);

            $this->state['offset'] = $i + 1;

            if ($this->sliceIsUp() && $i < $total - 1) {
                return false;
            }
        }

        return true;
    }

    private function syncImagesOnly(ShopifyService $source, ShopifyService $target, $handle, int $total, string $sku): void
    {
        $sourceVariants = $source->findVariantsBySku($sku);

        if (empty($sourceVariants)) {
            fputcsv($handle, [$sku, '', 0, 0, 'SKU not found in source store']);
            $this->tally($total, false);
            return;
        }

        $sourceProductId = $sourceVariants[0]['product_id'];
        $sourceVariantId = $sourceVariants[0]['variant_id'];
        $productTitle    = $sourceVariants[0]['product_title'] ?? '';

        $images = $this->resolveVariantImages($source, $sourceProductId, $sourceVariantId);

        if (empty($images)) {
            fputcsv($handle, [$sku, $productTitle, 0, 0, 'No images in source store']);
            $this->tally($total, false);
            return;
        }

        $targetVariants = $target->findVariantsBySku($sku);

        if (empty($targetVariants)) {
            fputcsv($handle, [$sku, $productTitle, count($images), 0, 'SKU not found in target store']);
            $this->tally($total, false);
            return;
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
        } elseif ($copied > 0) {
            $statusMsg = "Partial ({$copied}/{$totalImages})";
        } else {
            $statusMsg = 'Failed to copy images';
        }

        fputcsv($handle, [$sku, $productTitle, $totalImages, $copied, $statusMsg]);
        $this->tally($total, $copied > 0);
    }

    /**
     * Full Product mode — groups the requested SKUs by their source product
     * FIRST, so a product with 5 variants only migrates the specific SKU(s)
     * you actually listed, never the sibling colors/sizes you didn't ask for.
     * SKUs that already exist in the target store are just image-synced
     * (same as Images Only), scoped to that one variant's own photo — or,
     * with $overwriteExisting, their product is mirrored from the source.
     *
     * Two phases, each sliced: resolve every SKU against the source store,
     * then work through the products. The grouping has to be complete before
     * anything is created — siblings that landed in different slices would
     * otherwise each create the product, because Shopify's search does not
     * see a product the moment it is made.
     *
     * @return bool true when every SKU is done, false when the slice ran out first
     */
    private function runFullProductMode(ShopifyService $source, ShopifyService $target, $handle, int $total): bool
    {
        if ($this->state['phase'] === 'resolve') {
            for ($i = $this->state['offset']; $i < $total; $i++) {
                $sku            = $this->skus[$i];
                $sourceVariants = $source->findVariantsBySku($sku);

                if (empty($sourceVariants)) {
                    fputcsv($handle, [$sku, '', 0, 0, 'SKU not found in source store']);
                    $this->tally($total, false);
                } else {
                    // Only what the second phase reads, since this rides along
                    // in every slice's payload.
                    $this->state['resolved'][$sku] = [
                        'product_id'    => $sourceVariants[0]['product_id'],
                        'variant_id'    => $sourceVariants[0]['variant_id'],
                        'product_title' => $sourceVariants[0]['product_title'] ?? '',
                        'collections'   => array_values($sourceVariants[0]['collections'] ?? []),
                    ];
                }

                $this->state['offset'] = $i + 1;

                if ($this->sliceIsUp() && $i < $total - 1) {
                    return false;
                }
            }

            $this->state['phase']  = 'migrate';
            $this->state['offset'] = 0;
        }

        // sourceProductId => [sku, sku, ...] (only ones actually requested), in
        // the order the SKUs were listed — the same every slice.
        $skusByProduct = [];
        foreach ($this->state['resolved'] as $sku => $variant) {
            $skusByProduct[$variant['product_id']][] = (string) $sku;
        }

        $groups = array_keys($skusByProduct);

        if ($this->state['offset'] >= count($groups)) {
            return true;
        }

        $targetCollections = $target->getAllCollectionTitles();

        for ($g = $this->state['offset']; $g < count($groups); $g++) {
            // Numeric product IDs come back from array_keys() as ints.
            $this->migrateProductGroup($source, $target, $handle, $total, (string) $groups[$g], $skusByProduct[$groups[$g]], $targetCollections);

            $this->state['offset'] = $g + 1;

            if ($this->sliceIsUp() && $g < count($groups) - 1) {
                return false;
            }
        }

        return true;
    }

    /** Every requested SKU of one source product: image-sync the ones the target has, create or extend for the rest. */
    private function migrateProductGroup(ShopifyService $source, ShopifyService $target, $handle, int $total, string $sourceProductId, array $requestedSkus, array $targetCollections): void
    {
        $resolved          = $this->state['resolved'];
        $productTitle      = $resolved[$requestedSkus[0]]['product_title'] ?? '';
        $sourceCollections = $resolved[$requestedSkus[0]]['collections'] ?? [];

        $missingSkus  = [];
        $existingSkus = [];

        foreach ($requestedSkus as $sku) {
            $targetVariants = $target->findVariantsBySku($sku);

            if (empty($targetVariants)) {
                $missingSkus[] = $sku;
            } else {
                $existingSkus[$sku] = $targetVariants[0];
            }
        }

        // The mirror covers the whole product, so it takes the missing
        // siblings with it rather than creating them separately.
        if ($this->overwriteExisting && !empty($existingSkus)) {
            $this->overwriteExistingProduct($source, $target, $handle, $total, $sourceProductId, $requestedSkus, $existingSkus, $targetCollections);
            return;
        }

        foreach ($existingSkus as $sku => $targetVariant) {
            // Already exists in target — sync just this variant's own gallery photos, not the whole product
            $this->syncSingleVariantImage($source, $target, (string) $sku, $resolved[$sku], $targetVariant, $handle, $productTitle);
            $this->tally($total, true);
        }

        if (empty($missingSkus)) {
            return;
        }

        $sourceProduct = $source->getFullProduct($sourceProductId);

        if (!$sourceProduct) {
            foreach ($missingSkus as $sku) {
                fputcsv($handle, [$sku, $productTitle, 0, 0, 'Failed to fetch full product from source store']);
                $this->tally($total, false);
            }
            return;
        }

        // A sibling SKU of this same source product may already have been
        // migrated in an earlier run — its target product already exists,
        // so add this variant there instead of creating a duplicate product.
        $existingTargetProductId = $this->findExistingTargetProduct($target, $sourceProduct, $missingSkus);

        if ($existingTargetProductId) {
            $results = $this->addVariantsToExistingProduct($source, $target, $missingSkus, $sourceProduct, $existingTargetProductId, $handle, $productTitle);

            foreach ($missingSkus as $sku) {
                $this->tally($total, $results[$sku] ?? false);
            }
            return;
        }

        [$ok, $newProductId] = $this->migrateFullProduct($source, $target, $missingSkus, $sourceProduct, $productTitle, $sourceCollections, $targetCollections, $handle);

        foreach ($missingSkus as $sku) {
            $this->tally($total, $ok);
        }
    }

    /**
     * Make the target's product a copy of the source product: text, SEO,
     * options, every variant and the whole gallery. Variants the source does
     * not have are deleted from the target; ones it lacks are added.
     *
     * Refuses, touching nothing, when the two stores do not hold the product
     * the same way — mirroring a whole product onto one that holds only part
     * of it would drag in SKUs that already live on other target products.
     *
     * @param array<string,array> $existingSkus requested SKU => its target variant
     */
    private function overwriteExistingProduct(
        ShopifyService $source,
        ShopifyService $target,
        $handle,
        int $total,
        string $sourceProductId,
        array $requestedSkus,
        array $existingSkus,
        array $targetCollections,
    ): void {
        $resolved     = $this->state['resolved'];
        $productTitle = $resolved[$requestedSkus[0]]['product_title'] ?? '';

        $fail = function (string $reason) use ($handle, $total, $requestedSkus, $productTitle) {
            foreach ($requestedSkus as $sku) {
                fputcsv($handle, [$sku, $productTitle, 0, 0, $reason]);
                $this->tally($total, false);
            }
        };

        $targetProductIds = array_values(array_unique(array_map(fn ($v) => (string) $v['product_id'], $existingSkus)));

        if (count($targetProductIds) > 1) {
            $fail('Not updated: these SKUs are on ' . count($targetProductIds) . ' separate products in the target store');
            return;
        }

        $targetProductId = $targetProductIds[0];
        $sourceProduct   = $source->getFullProduct($sourceProductId);
        $targetProduct   = $target->getFullProduct($targetProductId);

        if (!$sourceProduct || !$targetProduct) {
            $fail('Failed to fetch the product from ' . (!$sourceProduct ? 'source' : 'target') . ' store');
            return;
        }

        $variantIdsBySku = [];
        $targetSkus      = [];
        foreach ($targetProduct['variants'] ?? [] as $variant) {
            $sku = (string) ($variant['sku'] ?? '');
            $variantIdsBySku[strtoupper($sku)] = (string) $variant['id'];
            $targetSkus[strtoupper($sku)]      = $sku;
        }

        $sourceSkus = [];
        foreach ($sourceProduct['variants'] ?? [] as $variant) {
            $sku = (string) ($variant['sku'] ?? '');

            // Without a SKU a variant cannot be matched, so every run would
            // delete and re-create it, losing its stock each time.
            if ($sku === '') {
                $fail('Not updated: a variant of the source product has no SKU');
                return;
            }

            $sourceSkus[strtoupper($sku)] = true;

            if (isset($variantIdsBySku[strtoupper($sku)])) {
                continue;
            }

            foreach ($target->findVariantsBySku($sku) as $elsewhere) {
                if ((string) $elsewhere['product_id'] !== $targetProductId) {
                    $fail("Not updated: SKU {$sku} is already on another product in the target store");
                    return;
                }
            }
        }

        try {
            $seo = $source->getProductSeo($sourceProductId);
            $target->overwriteProduct($targetProductId, $sourceProduct, $variantIdsBySku, $seo);
        } catch (\Throwable $e) {
            Log::error("RunStoreImageSyncJob: overwriting product {$targetProductId} from {$sourceProductId} failed: " . $e->getMessage());
            $fail('Failed to update product: ' . $e->getMessage());
            return;
        }

        try {
            $sourceCollections = $resolved[$requestedSkus[0]]['collections'] ?? [];
            if (!empty($sourceCollections) && !empty($targetCollections)) {
                $target->addProductToCollections($targetProductId, $sourceCollections, $targetCollections);
            }

            $materialAndFeatures = $source->getProductMaterialAndFeatures($sourceProductId);
            if ($materialAndFeatures['material'] || !empty($materialAndFeatures['features'])) {
                $target->updateProductMetafields(
                    $targetProductId,
                    $materialAndFeatures['material'],
                    implode(', ', $materialAndFeatures['features']),
                );
            }
        } catch (\Throwable $e) {
            // The product itself is already updated; say what is missing rather than failing it.
            Log::warning("RunStoreImageSyncJob: collections/metafields for product {$targetProductId}: " . $e->getMessage());
        }

        $removed    = array_values(array_diff_key($targetSkus, $sourceSkus));
        $imageCount = count($sourceProduct['images'] ?? []);
        $note       = "Updated existing product (ID: {$targetProductId}): "
                    . count($sourceProduct['variants'] ?? []) . " variant(s), {$imageCount} image(s) replaced";

        if (!empty($removed)) {
            $note .= '; removed variants: ' . implode(', ', array_map(fn ($s) => $s === '' ? '(no SKU)' : $s, $removed));
        }

        foreach ($requestedSkus as $sku) {
            fputcsv($handle, [$sku, $productTitle, $imageCount, $imageCount, $note]);
            $this->tally($total, true);
        }
    }

    /**
     * Check whether the target store already has a product for this same
     * source product — created by a previous migration of a SIBLING SKU —
     * by looking up every other variant of the source product (not just the
     * ones requested in this run) against the target store.
     */
    private function findExistingTargetProduct(ShopifyService $target, array $sourceProduct, array $excludeSkus): ?string
    {
        $excludeSkuSet = array_flip(array_map('strtoupper', $excludeSkus));

        foreach ($sourceProduct['variants'] ?? [] as $variant) {
            $sku = $variant['sku'] ?? '';
            if ($sku === '' || isset($excludeSkuSet[strtoupper($sku)])) {
                continue;
            }

            $targetVariants = $target->findVariantsBySku($sku);
            if (!empty($targetVariants)) {
                return $targetVariants[0]['product_id'];
            }
        }

        return null;
    }

    /**
     * Add each missing SKU as a new variant onto a target product that
     * already exists (created by a previous migration of a sibling SKU),
     * then sync just that variant's own photo block — never touching or
     * duplicating the sibling variants already on that product.
     *
     * @return array<string,bool> sku => success
     */
    private function addVariantsToExistingProduct(
        ShopifyService $source,
        ShopifyService $target,
        array $missingSkus,
        array $sourceProduct,
        string $targetProductId,
        $handle,
        string $productTitle,
    ): array {
        $requestedSkuSet = array_flip(array_map('strtoupper', $missingSkus));
        $variantsToAdd    = array_filter(
            $sourceProduct['variants'] ?? [],
            fn ($v) => isset($requestedSkuSet[strtoupper($v['sku'] ?? '')])
        );

        $results = [];

        foreach ($variantsToAdd as $sourceVariant) {
            $sku = $sourceVariant['sku'];

            try {
                $newVariantId = $target->addVariantToProduct($targetProductId, [
                    'sku'                  => $sourceVariant['sku'] ?? null,
                    'price'                => $sourceVariant['price'] ?? null,
                    'compare_at_price'     => $sourceVariant['compare_at_price'] ?? null,
                    'inventory_quantity'   => $sourceVariant['inventory_quantity'] ?? 0,
                    'inventory_management' => $sourceVariant['inventory_management'] ?? null,
                    'weight'               => $sourceVariant['weight'] ?? null,
                    'weight_unit'          => $sourceVariant['weight_unit'] ?? null,
                    'barcode'              => $sourceVariant['barcode'] ?? null,
                    'option1'              => $sourceVariant['option1'] ?? null,
                    'option2'              => $sourceVariant['option2'] ?? null,
                    'option3'              => $sourceVariant['option3'] ?? null,
                ]);

                if (!$newVariantId) {
                    fputcsv($handle, [$sku, $productTitle, 0, 0, 'Failed to add variant to existing target product']);
                    $results[$sku] = false;
                    continue;
                }

                $this->syncSingleVariantImage(
                    $source,
                    $target,
                    $sku,
                    ['product_id' => (string) $sourceProduct['id'], 'variant_id' => (string) $sourceVariant['id']],
                    ['product_id' => $targetProductId, 'variant_id' => $newVariantId],
                    $handle,
                    $productTitle
                );
                $results[$sku] = true;
            } catch (\Throwable $e) {
                Log::error("RunStoreImageSyncJob: failed to add variant for SKU {$sku} to existing product {$targetProductId}: " . $e->getMessage());
                fputcsv($handle, [$sku, $productTitle, 0, 0, 'Failed to add variant: ' . $e->getMessage()]);
                $results[$sku] = false;
            }
        }

        return $results;
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
     * Resolve the photos belonging to just one variant. Merchants upload a
     * colour's whole photo set as one consecutive run and only tag the ONE
     * anchor photo in that run to the variant (image_id) — so the anchor's
     * position, up to the next variant's anchor position, is the real photo
     * block. Shopify's variant media connection (GraphQL) is checked as a
     * secondary source — in practice it often just mirrors the single
     * image_id anchor rather than the full block, so it's only useful when
     * the product has no image_id tagging to anchor on at all.
     */
    private function resolveVariantImages(ShopifyService $source, string $productId, string $variantId): array
    {
        $sourceProduct = $source->getFullProduct($productId);
        $allVariants   = $sourceProduct['variants'] ?? [];
        $allImages     = $sourceProduct['images'] ?? [];

        $imageBlocksByVariant = $this->partitionImagesByVariant($allVariants, $allImages);
        $variantImages        = $imageBlocksByVariant[(string) $variantId] ?? [];

        if (!empty($variantImages)) {
            return $variantImages;
        }

        $variantImages = $source->getVariantMedia($variantId);
        if (!empty($variantImages)) {
            return $variantImages;
        }

        if (empty($imageBlocksByVariant) && !empty($allImages)) {
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
        array $sourceProduct,
        string $productTitle,
        array $sourceCollections,
        array $targetCollections,
        $handle,
    ): array {
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

        // For each included variant, prefer the classic image_id anchor →
        // next-anchor position block (the real photo set for that colour's
        // upload run); fall back to Shopify's variant media connection only
        // when the product has no image_id tagging to anchor on at all.
        $allImages            = $sourceProduct['images'] ?? [];
        $imageBlocksByVariant = $this->partitionImagesByVariant($allVariants, $allImages);

        $filteredImages = [];
        $seenImageIds   = [];
        foreach ($filteredVariants as $v) {
            $variantId = (string) ($v['id'] ?? '');
            $media     = $imageBlocksByVariant[$variantId] ?? [];

            if (empty($media)) {
                $media = $source->getVariantMedia($variantId);
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

        // No variant had an image_id anchor anywhere in this product (simple
        // product, single photo set) — use everything.
        if (empty($imageBlocksByVariant) && empty($filteredImages)) {
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
            $materialAndFeatures = $source->getProductMaterialAndFeatures((string) $sourceProduct['id']);
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
            Log::error("RunStoreImageSyncJob: full product migration failed for product {$sourceProduct['id']}: " . $e->getMessage());
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
