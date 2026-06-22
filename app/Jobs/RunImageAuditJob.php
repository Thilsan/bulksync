<?php

namespace App\Jobs;

use App\Models\ImageAuditItem;
use App\Models\ImageAuditSession;
use App\Services\ShopifyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunImageAuditJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries   = 1;

    public function __construct(public readonly int $auditSessionId) {}

    public function handle(): void
    {
        $auditSession = ImageAuditSession::findOrFail($this->auditSessionId);
        $store        = $auditSession->store_id
            ? \App\Models\Store::find($auditSession->store_id)
            : \App\Models\Store::getActive($auditSession->user_id);

        $shopify = new ShopifyService($store);

        $auditSession->update([
            'status'         => 'running',
            'total_products' => $shopify->getProductCount(),
        ]);

        Log::info("RunImageAuditJob: starting audit for session {$this->auditSessionId}");

        try {
            $buffer          = [];
            $scanned         = 0;
            $totalSkus       = 0;
            $withImages      = 0;
            $withoutImages   = 0;

            $shopify->streamProductsForAudit(function (array $products) use (
                $auditSession, &$buffer, &$scanned, &$totalSkus, &$withImages, &$withoutImages
            ) {
                foreach ($products as $product) {
                    $imageCount = count($product['images'] ?? []);

                    foreach ($product['variants'] ?? [] as $variant) {
                        $sku = trim($variant['sku'] ?? '');
                        if (!$sku) continue;

                        $variantHasImage = !empty($variant['image_id']);
                        $hasImage        = $imageCount > 0 || $variantHasImage;

                        $buffer[] = [
                            'image_audit_session_id' => $auditSession->id,
                            'sku'                    => $sku,
                            'product_id'             => (string) $product['id'],
                            'product_title'          => $product['title'] ?? '',
                            'variant_id'             => (string) $variant['id'],
                            'image_count'            => $imageCount,
                            'has_image'              => $hasImage,
                            'created_at'             => now(),
                            'updated_at'             => now(),
                        ];

                        $totalSkus++;
                        $hasImage ? $withImages++ : $withoutImages++;
                    }

                    $scanned++;
                }

                // Flush every 500 rows
                if (count($buffer) >= 500) {
                    ImageAuditItem::insert($buffer);
                    $buffer = [];
                }

                $auditSession->update(['scanned_products' => $scanned]);
            });

            if (!empty($buffer)) {
                ImageAuditItem::insert($buffer);
            }

            $auditSession->update([
                'status'           => 'completed',
                'scanned_products' => $scanned,
                'total_skus'       => $totalSkus,
                'with_images'      => $withImages,
                'without_images'   => $withoutImages,
            ]);

            Log::info("RunImageAuditJob: completed — {$totalSkus} SKUs, {$withImages} with images, {$withoutImages} without.");

        } catch (\Throwable $e) {
            Log::error("RunImageAuditJob failed: " . $e->getMessage());
            $auditSession->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        }
    }

    public function failed(\Throwable $e): void
    {
        ImageAuditSession::where('id', $this->auditSessionId)->update([
            'status'        => 'failed',
            'error_message' => $e->getMessage(),
        ]);
    }
}
