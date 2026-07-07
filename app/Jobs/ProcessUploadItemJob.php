<?php

namespace App\Jobs;

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

        // Skip if already processed by a previous attempt or another worker
        if (!$item || !in_array($item->status, ['pending', 'failed'])) {
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

        try {
            // ── 1. Look up Shopify SKU, falling back to barcode — may match multiple products ──
            $variants = $shopify->findVariantsBySkuOrBarcodeCached($item->sku_detected);

            if (empty($variants)) {
                $item->update([
                    'status'        => 'skipped',
                    'error_message' => "No Shopify variant found for SKU or barcode: {$item->sku_detected}",
                ]);
                $this->syncSessionCounts($item->upload_session_id);
                return;
            }

            $matchLabel = ($variants[0]['matched_via'] ?? 'sku') === 'barcode' ? 'barcode' : 'SKU';

            // If the same SKU/barcode exists on multiple different products — skip and warn
            $uniqueProductIds = array_unique(array_column($variants, 'product_id'));
            if (count($uniqueProductIds) > 1) {
                $item->update([
                    'status'        => 'skipped',
                    'error_message' => "Duplicate {$matchLabel}: found in " . count($uniqueProductIds) . " products in Shopify — upload skipped",
                ]);
                $this->syncSessionCounts($item->upload_session_id);
                return;
            }

            // Record the first match on the item (for display purposes)
            $item->update([
                'status'        => 'matched',
                'product_id'    => $variants[0]['product_id'],
                'product_title' => $variants[0]['product_title'],
                'variant_id'    => $variants[0]['variant_id'],
                'variant_sku'   => $variants[0]['variant_sku'],
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
            $processedSizeKb   = (int) round(strlen($processed) / 1024);
            $duplicateHandling = $session->duplicate_handling ?? 'skip';
            $firstImageId      = null;
            $allSkipped        = true;

            foreach ($variants as $variant) {
                $existingImages = $shopify->getProductImages($variant['product_id']);
                $matchingImages = array_values(array_filter(
                    $existingImages,
                    fn ($img) => ($img['alt'] ?? '') === $item->sku_detected
                ));

                if ($matchingImages && $duplicateHandling === 'skip') {
                    continue; // skip this product, try next
                }

                if ($matchingImages && $duplicateHandling === 'replace') {
                    foreach ($matchingImages as $img) {
                        $shopify->deleteProductImage($variant['product_id'], (string) $img['id']);
                    }
                }

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

                if (!$firstImageId) {
                    $firstImageId = $shopifyImageId;
                }
                $allSkipped = false;
            }

            // Every product was skipped due to duplicate handling
            if ($allSkipped) {
                $item->update([
                    'status'        => 'skipped',
                    'error_message' => 'Image already exists in Shopify (duplicate handling: skip)',
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
            'skipped_files'  => UploadItem::where('upload_session_id', $sessionId)->where('status', 'skipped')->count(),
            'matched_files'  => UploadItem::where('upload_session_id', $sessionId)->whereIn('status', ['matched', 'uploaded'])->count(),
            'status'         => $pending ? 'processing' : 'completed',
        ]);
    }
}
