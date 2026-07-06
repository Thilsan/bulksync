<?php

namespace App\Jobs;

use App\Models\AiContentImage;
use App\Models\AiContentItem;
use App\Models\AiContentSession;
use App\Models\Store;
use App\Services\GeminiService;
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
            $this->processSkus($session, $shopify, $gemini);

            $session->update(['status' => 'ready']);
        } catch (\Throwable $e) {
            Log::error('GenerateAiContentJob failed', ['session' => $this->sessionId, 'error' => $e->getMessage()]);
            $session->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        }
    }

    /**
     * Process the raw SKU list, deduping by Shopify product so a product
     * with multiple variant SKUs only gets ONE description/meta title/meta
     * description, while every image in its gallery gets its own alt text.
     */
    private function processSkus(AiContentSession $session, ShopifyService $shopify, GeminiService $gemini): void
    {
        $skus = json_decode($session->skus_json ?? '[]', true) ?: [];

        /** @var array<string, AiContentItem> $itemsByProductId */
        $itemsByProductId = [];

        foreach ($skus as $sku) {
            try {
                $variants = $shopify->findVariantsBySku($sku);

                if (empty($variants)) {
                    AiContentItem::create([
                        'session_id'    => $session->id,
                        'sku'           => $sku,
                        'all_skus'      => $sku,
                        'status'        => 'failed',
                        'error_message' => 'SKU not found in Shopify',
                    ]);
                    $session->increment('processed_items');
                    continue;
                }

                $variant   = $variants[0];
                $productId = $variant['product_id'];

                if (isset($itemsByProductId[$productId])) {
                    $item = $itemsByProductId[$productId];
                    $allSkus = array_filter(array_map('trim', explode(',', $item->all_skus ?? '')));
                    $allSkus[] = $sku;
                    $item->update(['all_skus' => implode(', ', array_unique($allSkus))]);
                    $session->increment('processed_items');
                    continue;
                }

                $item = $this->generateForProduct($session, $shopify, $gemini, $sku, $variant, $productId);
                $itemsByProductId[$productId] = $item;
            } catch (\Throwable $e) {
                Log::warning('AiContent SKU failed', ['sku' => $sku, 'error' => $e->getMessage()]);
                AiContentItem::create([
                    'session_id'    => $session->id,
                    'sku'           => $sku,
                    'all_skus'      => $sku,
                    'status'        => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }

            $session->increment('processed_items');
        }
    }

    private function generateForProduct(
        AiContentSession $session,
        ShopifyService $shopify,
        GeminiService $gemini,
        string $sku,
        array $variant,
        string $productId,
    ): AiContentItem {
        $productTitle = $variant['product_title'] ?? '';
        $vendor       = $variant['vendor'] ?? '';
        $productType  = $variant['product_type'] ?? '';
        $tags         = $variant['tags'] ?? [];
        $collections  = $variant['collections'] ?? [];

        $item = AiContentItem::create([
            'session_id'         => $session->id,
            'sku'                => $sku,
            'all_skus'           => $sku,
            'shopify_product_id' => $productId,
            'product_title'      => $productTitle,
            'status'             => 'processing',
        ]);

        $images = $shopify->getProductImages($productId);

        if (empty($images)) {
            $item->update(['status' => 'failed', 'error_message' => 'No images found for this product']);
            return $item;
        }

        $hero = $images[0];

        $content = $gemini->generateFromImageUrl($hero['src'], $productTitle, $vendor, $productType, $tags, $collections, $sku);
        sleep(4); // respect Gemini free tier: 15 req/min

        if (!$content) {
            $item->update(['status' => 'failed', 'error_message' => 'Gemini API failed to generate content']);
            return $item;
        }

        $item->update([
            'status'              => 'done',
            'image_url'           => $hero['src'],
            'shopify_image_id'    => $hero['id'] ?? null,
            'ai_description'      => $content['description'],
            'ai_meta_title'       => $content['meta_title'],
            'ai_meta_description' => $content['meta_description'],
        ]);

        AiContentImage::create([
            'item_id'          => $item->id,
            'shopify_image_id' => $hero['id'] ?? null,
            'image_url'        => $hero['src'],
            'position'         => 0,
            'ai_alt_text'      => $content['alt_text'],
            'status'           => 'done',
        ]);

        foreach (array_slice($images, 1) as $index => $image) {
            try {
                $altText = $gemini->generateAltTextFromUrl($image['src'], $productTitle);
                sleep(4);

                AiContentImage::create([
                    'item_id'          => $item->id,
                    'shopify_image_id' => $image['id'] ?? null,
                    'image_url'        => $image['src'],
                    'position'         => $index + 1,
                    'ai_alt_text'      => $altText,
                    'status'           => $altText ? 'done' : 'failed',
                    'error_message'    => $altText ? null : 'Gemini API failed to generate alt text',
                ]);
            } catch (\Throwable $e) {
                Log::warning('AiContent image alt text failed', ['item' => $item->id, 'image' => $image['id'] ?? null, 'error' => $e->getMessage()]);
                AiContentImage::create([
                    'item_id'          => $item->id,
                    'shopify_image_id' => $image['id'] ?? null,
                    'image_url'        => $image['src'],
                    'position'         => $index + 1,
                    'status'           => 'failed',
                    'error_message'    => $e->getMessage(),
                ]);
            }
        }

        return $item;
    }
}
