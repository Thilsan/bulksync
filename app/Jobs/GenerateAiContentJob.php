<?php

namespace App\Jobs;

use App\Models\AiContentItem;
use App\Models\AiContentSession;
use App\Models\Store;
use App\Services\GeminiService;
use App\Services\OneDriveService;
use App\Services\ShopifyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateAiContentJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;
    public int $tries   = 1;

    public function __construct(public readonly int $sessionId) {}

    public function handle(GeminiService $gemini, ShopifyService $shopify): void
    {
        $session = AiContentSession::find($this->sessionId);
        if (!$session) return;

        $store = Store::find($session->store_id);
        if ($store) {
            $shopify = new ShopifyService($store);
        }

        $session->update(['status' => 'processing']);

        try {
            if ($session->input_type === 'sku_list') {
                $this->processSkuList($session, $shopify, $gemini);
            } else {
                $this->processOneDrive($session, $gemini, $shopify);
            }

            $session->update(['status' => 'ready']);
        } catch (\Throwable $e) {
            Log::error('GenerateAiContentJob failed', ['session' => $this->sessionId, 'error' => $e->getMessage()]);
            $session->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        }
    }

    private function processSkuList(AiContentSession $session, ShopifyService $shopify, GeminiService $gemini): void
    {
        $items = AiContentItem::where('session_id', $session->id)
            ->where('status', 'pending')
            ->get();

        $session->update(['total_items' => $items->count()]);

        foreach ($items as $item) {
            $item->update(['status' => 'processing']);

            try {
                $variants = $shopify->findVariantsBySku($item->sku);

                if (empty($variants)) {
                    $item->update(['status' => 'failed', 'error_message' => 'SKU not found in Shopify']);
                    $session->increment('processed_items');
                    continue;
                }

                $variant      = $variants[0];
                $productId    = $variant['product_id'];
                $productTitle = $variant['product_title'] ?? '';

                $images = $shopify->getProductImages($productId);
                $imageUrl = $images[0]['src'] ?? null;

                if (!$imageUrl) {
                    $item->update([
                        'status'             => 'failed',
                        'shopify_product_id' => $productId,
                        'product_title'      => $productTitle,
                        'error_message'      => 'No images found for this product',
                    ]);
                    $session->increment('processed_items');
                    continue;
                }

                $content = $gemini->generateFromImageUrl($imageUrl, $productTitle);

                if (!$content) {
                    $item->update([
                        'status'             => 'failed',
                        'shopify_product_id' => $productId,
                        'product_title'      => $productTitle,
                        'image_url'          => $imageUrl,
                        'error_message'      => 'Gemini API failed to generate content',
                    ]);
                    $session->increment('processed_items');
                    continue;
                }

                $item->update([
                    'status'             => 'done',
                    'shopify_product_id' => $productId,
                    'product_title'      => $productTitle,
                    'image_url'          => $imageUrl,
                    'ai_description'     => $content['description'],
                    'ai_meta_title'      => $content['meta_title'],
                    'ai_meta_description' => $content['meta_description'],
                ]);
            } catch (\Throwable $e) {
                Log::warning('AiContent item failed', ['item' => $item->id, 'sku' => $item->sku, 'error' => $e->getMessage()]);
                $item->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            }

            $session->increment('processed_items');

            // Respect Gemini free tier: 15 req/min → wait 4 seconds between requests
            sleep(4);
        }
    }

    private function processOneDrive(AiContentSession $session, GeminiService $gemini, ShopifyService $shopify): void
    {
        $user     = $session->user;
        $onedrive = new OneDriveService();
        $onedrive->setUser($user);

        $count = 0;

        $onedrive->streamFolderImages($session->onedrive_link, function (array $file) use ($session, $gemini, $shopify, &$count) {
            $filename = pathinfo($file['name'] ?? '', PATHINFO_FILENAME);
            $sku      = strtoupper(trim($filename));

            if (!$sku) return;

            $item = AiContentItem::create([
                'session_id' => $session->id,
                'sku'        => $sku,
                'status'     => 'processing',
            ]);

            $count++;
            $session->update(['total_items' => $count]);

            try {
                $imageBytes = app(OneDriveService::class)
                    ->setUser($session->user)
                    ->downloadFileById($file['drive_id'] ?? '', $file['item_id'] ?? '', $file['download_url'] ?? '');

                // Try to find matching Shopify product
                $productId    = null;
                $productTitle = null;
                try {
                    $variants = $shopify->findVariantsBySku($sku);
                    if (!empty($variants)) {
                        $productId    = $variants[0]['product_id'];
                        $productTitle = $variants[0]['product_title'] ?? null;
                    }
                } catch (\Throwable) {}

                $content = $gemini->generateFromImageBytes($imageBytes, $productTitle ?? $sku);

                if (!$content) {
                    $item->update(['status' => 'failed', 'error_message' => 'Gemini API failed']);
                } else {
                    $item->update([
                        'status'              => 'done',
                        'shopify_product_id'  => $productId,
                        'product_title'       => $productTitle,
                        'ai_description'      => $content['description'],
                        'ai_meta_title'       => $content['meta_title'],
                        'ai_meta_description' => $content['meta_description'],
                    ]);
                }
            } catch (\Throwable $e) {
                $item->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            }

            $session->increment('processed_items');
            sleep(4);
        });
    }
}
