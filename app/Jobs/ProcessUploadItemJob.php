<?php

namespace App\Jobs;

use App\Exceptions\ShopifyRequestException;
use App\Models\UploadItem;
use App\Models\UploadSession;
use App\Services\ImageProcessingService;
use App\Services\OneDriveService;
use App\Services\ShopifyService;
use App\Services\UploadBaselineResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessUploadItemJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;  // 3 minutes per image
    public int $tries   = 3;
    public int $backoff = 30;   // 30 seconds between retries

    public function __construct(
        public readonly int $itemId,
    ) {}

    public function handle(
        OneDriveService        $oneDrive,
        ImageProcessingService $imageService,
        UploadBaselineResolver $baselines,
    ): void {
        $item = UploadItem::find($this->itemId);

        // Skip only if a previous attempt already FINISHED this item (terminal
        // state). In-flight statuses ('processing', 'matched') mean a prior
        // attempt died mid-job — timeout kill, worker restart — and the queue
        // retry must be allowed to resume it, or the item orphans forever.
        if (!$item || in_array($item->status, ['uploaded', 'skipped', 'exists'])) {
            return;
        }

        $item->update(['status' => 'processing']);

        $session = UploadSession::find($item->upload_session_id);
        $store   = ($session?->store_id) ? \App\Models\Store::find($session->store_id) : \App\Models\Store::getActive($session?->user_id);
        $shopify = new ShopifyService($store);

        if ($session?->user_id) {
            $user = \App\Models\User::find($session->user_id);
            if ($user) {
                $oneDrive->setUser($user);
            }
        }

        $matchingMode = $session->matching_mode ?? 'sku_barcode';

        try {
            // ── 1. Look up matching Shopify product(s) — may match multiple ──
            $variants = $this->lookUp($shopify, $matchingMode, $item->sku_detected);

            // The folder name did not name anything in Shopify, so try the SKU
            // written on the file itself. A folder named for the shipment rather
            // than the item ("Lancome Aug") holding SKU-named files matched
            // nothing at all before this fallback.
            $fallbackSku = $item->filename_sku !== $item->sku_detected ? $item->filename_sku : null;

            if (empty($variants) && $fallbackSku) {
                $variants = $this->lookUp($shopify, $matchingMode, $fallbackSku);

                if ($variants) {
                    // Adopt it as the item's identifier: everything downstream —
                    // the alt text written to Shopify, the skip decision, the SKU
                    // shown in the UI — should speak about what actually matched.
                    $item->update(['sku_detected' => $fallbackSku]);
                }
            }

            if (empty($variants)) {
                $label      = $matchingMode === 'style_code' ? 'style code' : 'SKU or barcode';
                $identifier = $fallbackSku
                    ? "{$item->sku_detected} or {$fallbackSku}"
                    : $item->sku_detected;

                $item->update([
                    'status'        => 'skipped',
                    'error_message' => "No Shopify product found for {$label}: {$identifier}",
                ]);
                $this->syncSessionCounts($item->upload_session_id);
                return;
            }

            $matchLabel = $matchingMode === 'style_code'
                ? 'style code'
                : (($variants[0]['matched_via'] ?? 'sku') === 'barcode' ? 'barcode' : 'SKU');

            // If the same SKU/barcode/style code exists on multiple different products — skip and warn
            $uniqueProductIds = array_unique(array_column($variants, 'product_id'));
            if (count($uniqueProductIds) > 1) {
                $item->update([
                    'status'        => 'skipped',
                    'error_message' => "Duplicate {$matchLabel}: found in " . count($uniqueProductIds) . " products in Shopify — upload skipped",
                ]);
                $this->syncSessionCounts($item->upload_session_id);
                return;
            }

            // Record the first match on the item (for display purposes).
            // error_message is cleared so a stale failure note from a prior
            // retried attempt doesn't linger next to a healthy status.
            $item->update([
                'status'        => 'matched',
                'error_message' => null,
                'product_id'    => $variants[0]['product_id'],
                'product_title' => $variants[0]['product_title'],
                'variant_id'    => $variants[0]['variant_id'] ?? null,
                'variant_sku'   => $variants[0]['variant_sku'] ?? null,
            ]);

            // ── 2. Decide which matches still need this photo ──
            // Settled BEFORE the download: on a folder of large images, pulling
            // and resizing megabytes only to discard them is the most expensive
            // possible way to learn that nothing needed uploading.
            $duplicateHandling = $session->duplicate_handling ?? 'skip';
            $scope             = $matchingMode === 'style_code'
                ? UploadBaselineResolver::SCOPE_PRODUCT
                : UploadBaselineResolver::SCOPE_VARIANT;

            $targets = [];

            foreach ($variants as $variant) {
                $scopeId = (string) ($scope === UploadBaselineResolver::SCOPE_PRODUCT
                    ? $variant['product_id']
                    : $variant['variant_id']);

                // "Did this SKU already have its photo?" is decided ONCE for the
                // whole SKU folder and reused by every file in it. Asking per
                // file raced against the folder's own uploads: the first file
                // assigns the variant image, so files 2 and 3 saw a photo that
                // was not there when the batch began and dropped themselves as
                // Already Has Image.
                $hadImageBefore = $baselines->resolve(
                    $item->upload_session_id,
                    $scope,
                    $scopeId,
                    fn () => $scope === UploadBaselineResolver::SCOPE_VARIANT
                        // throwOnFailure: an API blip must retry the job, never
                        // read as "no image" and add a duplicate.
                        ? $shopify->variantHasOwnImage($scopeId, true)
                        : $this->productHasImageForIdentifier($shopify, $variant['product_id'], $item->sku_detected),
                );

                if ($hadImageBefore && $duplicateHandling === 'skip') {
                    continue; // this SKU is already covered, try the next match
                }

                $targets[] = [
                    'variant'  => $variant,
                    'scope_id' => $scopeId,
                    'replace'  => $hadImageBefore && $duplicateHandling === 'replace',
                ];
            }

            // Every match already had its own photo before this batch started —
            // nothing to upload.
            if (!$targets) {
                $item->update([
                    'status'        => 'exists',
                    'error_message' => $matchingMode === 'style_code'
                        ? 'Product already has an image for this style code — upload skipped'
                        : 'This SKU already has its own image on Shopify — upload skipped',
                ]);
                $this->syncSessionCounts($item->upload_session_id);
                return;
            }

            // ── 3. Download from OneDrive using item ID (fresh — never expires) ──
            $rawContent = $oneDrive->downloadFileById(
                $item->onedrive_drive_id,
                $item->onedrive_item_id,
                $item->onedrive_download_url ?? ''
            );

            // ── 4. Resize + compress (or compress-only if no dimensions chosen) ──
            $processed = ($session->image_width && $session->image_height)
                ? $imageService->process($rawContent, (int) $session->image_width, (int) $session->image_height)
                : $imageService->compressOnly($rawContent);
            $outputName = $imageService->outputFilename($item->filename);

            unset($rawContent);

            // ── 5. Upload to every match that still needs the photo ──
            // (style_code mode: exactly one product by this point — ambiguous
            // matches were already skipped above.)
            $processedSizeKb = (int) round(strlen($processed) / 1024);
            $firstImageId    = null;

            foreach ($targets as $target) {
                $variant = $target['variant'];
                $scopeId = $target['scope_id'];

                if ($target['replace']) {
                    // Replace still works off alt text, so it only clears images
                    // this tool put there — deleting a supplier's photos on a
                    // filename match is not a call this job should make.
                    foreach ($shopify->getProductImages($variant['product_id']) as $img) {
                        if (($img['alt'] ?? '') === $item->sku_detected) {
                            $shopify->deleteProductImage($variant['product_id'], (string) $img['id']);
                        }
                    }
                }

                if ($matchingMode === 'style_code') {
                    // Gallery-only upload — never linked to a variant.
                    $shopifyImageId = $shopify->uploadImageToProduct(
                        $variant['product_id'],
                        $processed,
                        $outputName,
                        $item->sku_detected,
                        null,
                    );
                } else {
                    // Exactly one file of the folder becomes the variant's main
                    // image. Claiming it with a conditional UPDATE, rather than
                    // counting siblings already marked 'uploaded', is what makes
                    // that true under parallel workers: the 'uploaded' row is
                    // written only after the upload returns, so two files could
                    // both read themselves as first and both reassign the image.
                    $claimedVariantImage = $baselines->claimVariantImageSlot(
                        $item->upload_session_id,
                        $scopeId,
                    );

                    try {
                        $shopifyImageId = $shopify->uploadImageToProduct(
                            $variant['product_id'],
                            $processed,
                            $outputName,
                            $item->sku_detected,
                            $claimedVariantImage ? $variant['variant_id'] : null,
                        );
                    } catch (\Throwable $e) {
                        // Hand the slot back, or a failed first file would leave
                        // the variant with no main image at all.
                        if ($claimedVariantImage) {
                            $baselines->releaseVariantImageSlot($item->upload_session_id, $scopeId);
                        }
                        throw $e;
                    }

                    if ($claimedVariantImage && $shopifyImageId) {
                        $shopify->setVariantImage($variant['variant_id'], $shopifyImageId);
                    } elseif ($claimedVariantImage) {
                        $baselines->releaseVariantImageSlot($item->upload_session_id, $scopeId);
                    }
                }

                if (!$firstImageId) {
                    $firstImageId = $shopifyImageId;
                }
            }

            unset($processed);

            $item->update([
                'status'            => 'uploaded',
                'shopify_image_id'  => $firstImageId,
                'processed_size_kb' => $processedSizeKb,
            ]);

        } catch (\Throwable $e) {
            Log::error("ProcessUploadItemJob item {$this->itemId} failed: " . $e->getMessage());

            $item->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            // Shopify rejecting the request itself — an image past the pixel
            // limit, a product that no longer exists — gives the same answer
            // however often it is asked. Retrying only re-sends multiple
            // megabytes to earn the same refusal, so stop at the first one.
            if ($e instanceof ShopifyRequestException && $e->isPermanent()) {
                $this->syncSessionCounts($item->upload_session_id);
                return;
            }

            // Let the queue retry (up to $tries times)
            if ($this->attempts() < $this->tries) {
                throw $e;
            }
        }

        $this->syncSessionCounts($item->upload_session_id);
    }

    public function failed(\Throwable $e): void
    {
        UploadItem::where('id', $this->itemId)->update([
            'status'        => 'failed',
            'error_message' => 'Max retries reached: ' . $e->getMessage(),
        ]);

        $item = UploadItem::find($this->itemId);
        if ($item) {
            $this->syncSessionCounts($item->upload_session_id);
        }
    }

    // ──────────────────────────────────────────────────────────────────────

    /**
     * Ask Shopify what this identifier names, live.
     *
     * Never against the warm SKU cache: that snapshot is rebuilt four times a
     * day, so a product added since the last warm is absent from it and would
     * be recorded as No Match though the SKU is sitting right there in the admin.
     *
     * throwOnFailure: a transient API/network error (DNS blip, timeout) must
     * surface as a retryable failure, NOT be mistaken for "no match" and
     * permanently marked No Match.
     */
    private function lookUp(ShopifyService $shopify, string $matchingMode, string $identifier): array
    {
        return $matchingMode === 'style_code'
            ? $shopify->findProductsByStyleCode($identifier, true)
            : $shopify->findVariantsBySkuOrBarcode($identifier, true);
    }

    /**
     * Style-code matching has no variant to ask about, so "already covered"
     * stays what it always was for that mode: the product's gallery already
     * carries an image tagged with this style code. Folders are product-level
     * there, so treating any gallery image as coverage would skip everything.
     */
    private function productHasImageForIdentifier(
        ShopifyService $shopify,
        string $productId,
        string $identifier,
    ): bool {
        foreach ($shopify->getProductImages($productId) as $img) {
            if (($img['alt'] ?? '') === $identifier) {
                return true;
            }
        }

        return false;
    }

    private function syncSessionCounts(int $sessionId): void
    {
        // Only mark completed once ALL items are done — check with a single query
        $pending = UploadItem::where('upload_session_id', $sessionId)
            ->whereIn('status', ['pending', 'processing'])
            ->exists();

        UploadSession::where('id', $sessionId)->update([
            'uploaded_files' => UploadItem::where('upload_session_id', $sessionId)->where('status', 'uploaded')->count(),
            'failed_files'   => UploadItem::where('upload_session_id', $sessionId)->where('status', 'failed')->count(),
            'skipped_files'  => UploadItem::where('upload_session_id', $sessionId)->whereIn('status', ['skipped', 'exists'])->count(),
            'matched_files'  => UploadItem::where('upload_session_id', $sessionId)->whereIn('status', ['matched', 'uploaded'])->count(),
            'status'         => $pending ? 'processing' : 'completed',
        ]);
    }
}
