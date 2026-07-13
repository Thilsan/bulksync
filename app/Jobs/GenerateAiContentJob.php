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

        $storeName           = $store->name ?? '';
        $availableCollections = $shopify->getAllCollectionTitles();

        $session->update(['status' => 'processing']);

        try {
            $this->processSkus($session, $shopify, $gemini, $storeName, $availableCollections);

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
    private function processSkus(AiContentSession $session, ShopifyService $shopify, GeminiService $gemini, string $storeName, array $availableCollections): void
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

                $item = $this->generateForProduct($session, $shopify, $gemini, $sku, $variant, $productId, $storeName, $availableCollections);
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
        string $storeName,
        array $availableCollections,
    ): AiContentItem {
        $productTitle        = $variant['product_title'] ?? '';
        $vendor              = $variant['vendor'] ?? '';
        $productType         = $variant['product_type'] ?? '';
        $tags                = $variant['tags'] ?? [];
        $collections         = $variant['collections'] ?? [];
        $existingDescription = $variant['existing_description'] ?? '';
        $collectionTitles    = array_column($availableCollections, 'title');

        $materialAndFeatures = $shopify->getProductMaterialAndFeatures($productId);
        $existingMaterial    = $materialAndFeatures['material'];
        $existingFeatures    = $materialAndFeatures['features'];

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
            return $this->generateForProductWithoutImage($item, $gemini, $productTitle, $vendor, $productType, $tags, $collections, $sku, $storeName, $existingDescription, $existingMaterial, $existingFeatures, $collectionTitles);
        }

        $hero = $images[0];

        $content = $gemini->generateFromImageUrl($hero['src'], $productTitle, $vendor, $productType, $tags, $collections, $sku, $storeName, $existingDescription, $existingMaterial, $existingFeatures, $collectionTitles);
        sleep(4); // respect Gemini free tier: 15 req/min

        if (!$content) {
            $item->update(['status' => 'failed', 'error_message' => 'Gemini API failed to generate content']);
            return $item;
        }

        $content['description']      = $this->sanitizeDescriptionHtml($this->sanitizeText($content['description']));
        $content['meta_title']       = $this->sanitizeText($content['meta_title']);
        $content['meta_description'] = $this->sanitizeText($content['meta_description']);
        $content['alt_text']         = $this->sanitizeText($content['alt_text']);
        $content['title']            = $this->sanitizeText($content['title'] ?? '');

        $item->update([
            'status'              => 'done',
            'image_url'           => $hero['src'],
            'shopify_image_id'    => $hero['id'] ?? null,
            'ai_description'      => $content['description'],
            'ai_meta_title'       => $content['meta_title'],
            'ai_meta_description' => $content['meta_description'],
            'ai_title'            => $content['title'],
            'ai_new_tags'         => $content['new_tags'] ?? [],
            'ai_new_collections'  => $content['new_collections'] ?? [],
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
                $altText = $this->sanitizeText($gemini->generateAltTextFromUrl($image['src'], $productTitle) ?? '') ?: null;
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

    /**
     * Fallback when the product has no images in Shopify at all: generate
     * description/meta content from confirmed store data alone (title, vendor,
     * type, tags, collections, existing description). No alt text or image
     * rows are created — there's no image to attach alt text to.
     */
    private function generateForProductWithoutImage(
        AiContentItem $item,
        GeminiService $gemini,
        string $productTitle,
        string $vendor,
        string $productType,
        array $tags,
        array $collections,
        string $sku,
        string $storeName,
        string $existingDescription,
        string $existingMaterial,
        array $existingFeatures,
        array $collectionTitles,
    ): AiContentItem {
        $content = $gemini->generateFromTextOnly($productTitle, $vendor, $productType, $tags, $collections, $sku, $storeName, $existingDescription, $existingMaterial, $existingFeatures, $collectionTitles);
        sleep(4); // respect Gemini free tier: 15 req/min

        if (!$content) {
            $item->update(['status' => 'failed', 'error_message' => 'No images found for this product, and text-only generation failed']);
            return $item;
        }

        $item->update([
            'status'              => 'done',
            'ai_description'      => $this->sanitizeDescriptionHtml($this->sanitizeText($content['description'])),
            'ai_meta_title'       => $this->sanitizeText($content['meta_title']),
            'ai_meta_description' => $this->sanitizeText($content['meta_description']),
            'ai_title'            => $this->sanitizeText($content['title'] ?? ''),
            'ai_new_tags'         => $content['new_tags'] ?? [],
            'ai_new_collections'  => $content['new_collections'] ?? [],
        ]);

        return $item;
    }

    /**
     * Decode any stray HTML entities (e.g. &nbsp;, &amp;) that Gemini sometimes
     * slips into its output — left as literal text, the Fanar translator tries
     * to "translate" them into garbled text instead of treating them as markup.
     */
    private function sanitizeText(string $text): string
    {
        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return str_replace("\xC2\xA0", ' ', $decoded); // non-breaking space → normal space
    }

    /**
     * Defensive backstop for the description HTML: the prompt already targets
     * a 1000-character limit and the allowed tag set (<p>, <strong>, <ul>, <li>),
     * but if Gemini ever produces unbalanced tags or a runaway length, this
     * catches it before broken HTML reaches the live Shopify product page.
     */
    private function sanitizeDescriptionHtml(string $html): string
    {
        $allowedTags = ['p', 'strong', 'ul', 'li'];

        foreach ($allowedTags as $tag) {
            $opens  = preg_match_all("/<{$tag}>/i", $html);
            $closes = preg_match_all("/<\/{$tag}>/i", $html);
            if ($opens !== $closes) {
                Log::warning('AI description had unbalanced HTML tags, falling back to plain text', ['tag' => $tag, 'opens' => $opens, 'closes' => $closes]);
                return '<p>' . e(trim(strip_tags($html))) . '</p>';
            }
        }

        $hardCap = 2000; // generous ceiling well above the prompt's 1000-char target — only catches runaway cases
        if (mb_strlen($html) <= $hardCap) {
            return $html;
        }

        Log::warning('AI description exceeded hard length cap, truncating safely', ['length' => mb_strlen($html)]);

        $window  = mb_substr($html, 0, $hardCap);
        $safeCut = 0;
        foreach (['</p>', '</li>', '</ul>'] as $closingTag) {
            $pos = mb_strrpos($window, $closingTag);
            if ($pos !== false) {
                $endPos = $pos + mb_strlen($closingTag);
                $safeCut = max($safeCut, $endPos);
            }
        }

        if ($safeCut === 0) {
            return '<p>' . e(trim(mb_substr(strip_tags($html), 0, $hardCap))) . '</p>';
        }

        $truncated = mb_substr($html, 0, $safeCut);

        if (substr_count($truncated, '<ul>') > substr_count($truncated, '</ul>')) {
            $truncated .= '</ul>';
        }

        return $truncated;
    }
}
