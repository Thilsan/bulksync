<?php

namespace App\Jobs;

use App\Exceptions\ShopifyRequestException;
use App\Models\UploadItem;
use App\Models\UploadSession;
use App\Services\ImageProcessingService;
use App\Services\OneDriveService;
use App\Services\ShopifyService;
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
            // throwOnFailure: a transient API/network error (DNS blip, timeout) must
            // surface as a retryable failure, NOT be mistaken for "no match"
            // and permanently marked No Match.
            $variants = $matchingMode === 'style_code'
                ? $shopify->findProductsByStyleCode($item->sku_detected, true)
                : $shopify->findVariantsBySkuOrBarcodeCached($item->sku_detected, true);

            if (empty($variants)) {
                $label = $matchingMode === 'style_code' ? 'style code' : 'SKU or barcode';
                $item->update([
                    'status'        => 'skipped',
                    'error_message' => "No Shopify product found for {$label}: {$item->sku_detected}",
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

            // ── 2. Download from OneDrive using item ID (fresh — never expires) ──
            $rawContent = $oneDrive->downloadFileById(
                $item->onedrive_drive_id,
                $item->onedrive_item_id,
                $item->onedrive_download_url ?? ''
            );

            // ── 3. Resize + compress (or compress-only if no dimensions chosen) ──
            $processed = ($session->image_width && $session->image_height)
                ? $imageService->process($rawContent, (int) $session->image_width, (int) $session->image_height)
                : $imageService->compressOnly($rawContent);
            $outputName = $imageService->outputFilename($item->filename);

            unset($rawContent);

            // ── 4. Upload to every product that shares this SKU ──
            // (style_code mode: exactly one product by this point — ambiguous
            // matches were already skipped above.)
            $processedSizeKb   = (int) round(strlen($processed) / 1024);
            $duplicateHandling = $session->duplicate_handling ?? 'skip';
            $firstImageId      = null;
            $allSkipped        = true;

            foreach ($variants as $variant) {
                $existingImages = $shopify->getProductImages($variant['product_id']);

                // Sibling files for this same identifier in this batch (e.g. _0, _1, _2)
                // already carry this identifier's alt text once uploaded — exclude those
                // from the duplicate check, or every image after the first would
                // look like "already has image" and get skipped. Style-code matches have
                // no variant, so dedupe against the product instead.
                $ownUploadedQuery = UploadItem::where('upload_session_id', $item->upload_session_id)
                    ->where('status', 'uploaded')
                    ->whereNotNull('shopify_image_id');
                $ownUploadedQuery = $matchingMode === 'style_code'
                    ? $ownUploadedQuery->where('product_id', $variant['product_id'])
                    : $ownUploadedQuery->where('variant_id', $variant['variant_id']);
                $ownUploadedImageIds = $ownUploadedQuery
                    ->pluck('shopify_image_id')
                    ->map(fn ($id) => (string) $id)
                    ->all();

                $matchingImages = array_values(array_filter(
                    $existingImages,
                    fn ($img) => ($img['alt'] ?? '') === $item->sku_detected
                        && !in_array((string) ($img['id'] ?? ''), $ownUploadedImageIds, true)
                ));

                if ($matchingImages && $duplicateHandling === 'skip') {
                    continue; // skip this product, try next
                }

                if ($matchingImages && $duplicateHandling === 'replace') {
                    foreach ($matchingImages as $img) {
                        $shopify->deleteProductImage($variant['product_id'], (string) $img['id']);
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
                    $isFirstForVariant = !UploadItem::where('upload_session_id', $item->upload_session_id)
                        ->where('variant_id', $variant['variant_id'])
                        ->where('status', 'uploaded')
                        ->exists();

                    $shopifyImageId = $shopify->uploadImageToProduct(
                        $variant['product_id'],
                        $processed,
                        $outputName,
                        $item->sku_detected,
                        $isFirstForVariant ? $variant['variant_id'] : null,
                    );

                    if ($isFirstForVariant && $shopifyImageId) {
                        $shopify->setVariantImage($variant['variant_id'], $shopifyImageId);
                    }
                }

                if (!$firstImageId) {
                    $firstImageId = $shopifyImageId;
                }
                $allSkipped = false;
            }

            // Every product already has an image for this identifier — nothing to upload
            if ($allSkipped) {
                $item->update([
                    'status'        => 'exists',
                    'error_message' => 'Already has image on Shopify — upload skipped',
                ]);
                $this->syncSessionCounts($item->upload_session_id);
                return;
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
