<?php

namespace App\Jobs;

use App\Models\UploadItem;
use App\Models\UploadSession;
use App\Services\ImageProcessingService;
use App\Services\GoogleDriveService;
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
        OneDriveService     $oneDrive,
        ShopifyService      $shopify,
        ImageProcessingService $imageService,
    ): void {
        $item = UploadItem::find($this->itemId);

        // Skip if already processed by a previous attempt or another worker
        if (!$item || !in_array($item->status, ['pending', 'failed'])) {
            return;
        }

        $item->update(['status' => 'processing']);

        try {
            // ── 1. Look up Shopify SKU (uses cache — avoids extra API call per image) ──
            $variant = $shopify->findVariantBySkuCached($item->sku_detected);

            if (!$variant) {
                $item->update([
                    'status'        => 'skipped',
                    'error_message' => "No Shopify variant found for SKU: {$item->sku_detected}",
                ]);
                $this->syncSessionCounts($item->upload_session_id);
                return;
            }

            $item->update([
                'status'        => 'matched',
                'product_id'    => $variant['product_id'],
                'product_title' => $variant['product_title'],
                'variant_id'    => $variant['variant_id'],
                'variant_sku'   => $variant['variant_sku'],
            ]);

            // ── 2. Download from OneDrive using item ID (fresh — never expires) ──
            $rawContent = $oneDrive->downloadFileById(
                $item->onedrive_drive_id,
                $item->onedrive_item_id
            );

            // ── 3. Resize + compress (or compress-only if no dimensions chosen) ──
            $session   = UploadSession::find($item->upload_session_id);
            $processed = ($session->image_width && $session->image_height)
                ? $imageService->process($rawContent, (int) $session->image_width, (int) $session->image_height)
                : $imageService->compressOnly($rawContent);
            $outputName = $imageService->outputFilename($item->filename);

            // Explicitly free the raw content from memory
            unset($rawContent);

            // ── 4. Upload to Shopify ──
            $processedSizeKb = (int) round(strlen($processed) / 1024);

            $shopifyImageId = $shopify->uploadImageToProduct(
                $variant['product_id'],
                $processed,
                $outputName,
                $item->sku_detected,
            );

            unset($processed);

            $item->update([
                'status'            => 'uploaded',
                'shopify_image_id'  => $shopifyImageId,
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
