<?php

namespace App\Jobs;

use App\Exceptions\GeminiQuotaException;
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

    /**
     * How long one job may spend generating before it hands the rest of the list
     * to a fresh job.
     *
     * A session's runtime is set by how many images its products have, not by how
     * many SKUs were typed in, so no SKU count is safely under the timeout: a
     * 220-SKU session finished in 19 minutes while a 116-SKU one was still going
     * at the hour mark and was killed, losing the session even though 68 SKUs had
     * already generated. Working to a time budget instead means the list length
     * stops mattering — each job stops well short of $timeout and the next one
     * picks up where it left off.
     */
    private const CHUNK_SECONDS = 2400;

    public function __construct(
        public readonly int $sessionId,
        public readonly int $offset = 0,
    ) {}

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

        // Every call to Gemini costs a 30-second timeout when the server cannot
        // reach Google, so a session of twenty SKUs spends twenty minutes dying
        // and the worker kills it before it can report anything. One cheap check
        // first turns that into an immediate, readable failure. Only worth doing
        // on the first chunk — later chunks have already proved the account works,
        // and if it stops working mid-run the quota exception handles it.
        $reachable = $this->offset === 0 ? $gemini->ping() : ['ok' => true, 'message' => ''];

        if (!$reachable['ok']) {
            Log::error('GenerateAiContentJob: Gemini unreachable', ['session' => $this->sessionId, 'error' => $reachable['message']]);
            $session->update(['status' => 'failed', 'error_message' => $reachable['message']]);

            return;
        }

        try {
            $nextOffset = $this->processSkus($session, $shopify, $gemini, $storeName, $availableCollections);

            // Still SKUs left when the time budget ran out. Hand the remainder to
            // a fresh job rather than pushing this one towards $timeout — the
            // session stays "processing" and the progress bar keeps moving.
            if ($nextOffset !== null) {
                Log::info('GenerateAiContentJob: chunk done, continuing', ['session' => $this->sessionId, 'next_offset' => $nextOffset]);
                self::dispatch($this->sessionId, $nextOffset)->onQueue('bulkupload');

                return;
            }

            $session->update(['status' => 'ready']);
        } catch (GeminiQuotaException $e) {
            // Out of quota mid-run. Whatever already generated is still good and
            // still worth pushing, so the session stays reviewable rather than
            // being hidden behind a failure screen — only the SKUs that were
            // in flight are marked failed, and the reason is kept on the session
            // so the page can say why it stopped short.
            Log::error('GenerateAiContentJob: Gemini quota exhausted', ['session' => $this->sessionId, 'error' => $e->getMessage()]);

            AiContentItem::where('session_id', $session->id)
                ->where('status', 'processing')
                ->update(['status' => 'failed', 'error_message' => $e->getMessage()]);

            $generated = AiContentItem::where('session_id', $session->id)->where('status', 'done')->exists();

            $session->update([
                'status'        => $generated ? 'ready' : 'failed',
                'error_message' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            Log::error('GenerateAiContentJob failed', ['session' => $this->sessionId, 'error' => $e->getMessage()]);
            $session->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        }
    }

    /**
     * Called when the job dies outright — including a queue-worker timeout,
     * which is how a slow session ends. Without this the session sits on
     * "processing" for ever and the page shows a progress bar that will never
     * move again.
     */
    public function failed(?\Throwable $e): void
    {
        AiContentSession::where('id', $this->sessionId)
            ->whereIn('status', ['pending', 'processing'])
            ->update([
                'status'        => 'failed',
                'error_message' => $e
                    ? \Illuminate\Support\Str::limit($e->getMessage(), 500)
                    : 'Generation stopped before it finished — most often the queue worker timeout.',
            ]);
    }

    /**
     * Process the raw SKU list, deduping by Shopify product so a product
     * with multiple variant SKUs only gets ONE description/meta title/meta
     * description, while every image in its gallery gets its own alt text.
     *
     * Walks the list from $this->offset and stops once CHUNK_SECONDS is up.
     *
     * @return int|null Offset for the next job, or null when the list is finished.
     */
    private function processSkus(AiContentSession $session, ShopifyService $shopify, GeminiService $gemini, string $storeName, array $availableCollections): ?int
    {
        $skus      = json_decode($session->skus_json ?? '[]', true) ?: [];
        $startedAt = microtime(true);

        // Re-running a session that stopped short should not pay for the SKUs it
        // already generated — those cost real money the first time. Anything with
        // a finished item is walked past for the price of an array lookup.
        $alreadyDone = $this->skusAlreadyGenerated($session);

        if ($this->offset === 0) {
            // A retry has to actually retry, so anything that did not finish is
            // cleared and will be generated again. Image rows cascade with the
            // item. Finished items are left alone — they are the whole point of
            // resuming rather than starting over.
            AiContentItem::where('session_id', $session->id)->where('status', '!=', 'done')->delete();

            // Counting restarts with the walk so a resume cannot inherit a stale
            // total; skipped SKUs still count, so the bar stays honest.
            $session->update(['processed_items' => 0]);
        }

        // Dedup has to survive a chunk boundary, so the map is seeded from what is
        // already in the database rather than built fresh per job. Without this a
        // product whose SKUs straddle two chunks would be generated twice — paying
        // Gemini twice for one description.
        /** @var array<string, AiContentItem> $itemsByProductId */
        $itemsByProductId = AiContentItem::where('session_id', $session->id)
            ->whereNotNull('shopify_product_id')
            ->get()
            ->keyBy('shopify_product_id')
            ->all();

        foreach ($skus as $index => $sku) {
            if ($index < $this->offset) {
                continue;
            }

            // Check before starting a SKU, never mid-product: an item is only
            // consistent once its images are written.
            if (microtime(true) - $startedAt >= self::CHUNK_SECONDS) {
                return $index;
            }

            if (isset($alreadyDone[$sku])) {
                $session->increment('processed_items');
                continue;
            }

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
            } catch (GeminiQuotaException $e) {
                // Every remaining SKU would fail the same way. Stop the session
                // instead of walking the rest of the list to prove it.
                throw $e;
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

        return null;
    }

    /**
     * Every SKU in this session that already has finished content, including the
     * sibling SKUs folded into an item's all_skus list.
     *
     * @return array<string, true>
     */
    private function skusAlreadyGenerated(AiContentSession $session): array
    {
        $done = [];

        foreach (AiContentItem::where('session_id', $session->id)->where('status', 'done')->get(['sku', 'all_skus']) as $item) {
            foreach (explode(',', (string) ($item->all_skus ?: $item->sku)) as $sku) {
                $sku = trim($sku);
                if ($sku !== '') {
                    $done[$sku] = true;
                }
            }
        }

        return $done;
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
        $existingMaterial    = $this->stripMaterialPercentage($materialAndFeatures['material']);
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
        // Pacing lives in GeminiService::throttle() now — one place, measured
        // from the last call rather than a flat wait on top of it.

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

                AiContentImage::create([
                    'item_id'          => $item->id,
                    'shopify_image_id' => $image['id'] ?? null,
                    'image_url'        => $image['src'],
                    'position'         => $index + 1,
                    'ai_alt_text'      => $altText,
                    'status'           => $altText ? 'done' : 'failed',
                    'error_message'    => $altText ? null : 'Gemini API failed to generate alt text',
                ]);
            } catch (GeminiQuotaException $e) {
                // An empty balance is not this image's problem and will not clear
                // by moving to the next one. Caught by the \Throwable arm below it
                // would be filed as "alt text failed" and the run would carry on
                // asking — which is exactly the grind this exception exists to
                // stop, and most calls in a session are alt text.
                throw $e;
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
        // Pacing lives in GeminiService::throttle() now — one place, measured
        // from the last call rather than a flat wait on top of it.

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
     * Strip composition percentages from the confirmed material metafield
     * (e.g. "50% cotton and 50% Tencel blend" -> "cotton and Tencel blend")
     * before it reaches the AI prompt — the Material bullet should just name
     * the material, not restate its composition breakdown.
     */
    private function stripMaterialPercentage(string $material): string
    {
        if ($material === '') {
            return $material;
        }

        $cleaned = preg_replace('/\d+(\.\d+)?\s*%\s*/u', '', $material);
        $cleaned = preg_replace('/\(\s*\)/u', '', $cleaned);
        $cleaned = preg_replace('/\s+,/u', ',', $cleaned);
        $cleaned = preg_replace('/\s*,\s*,/u', ',', $cleaned);
        $cleaned = preg_replace('/\s{2,}/u', ' ', $cleaned);
        $cleaned = trim($cleaned, " ,\t\n\r\0\x0B");

        return $cleaned !== '' ? ucfirst($cleaned) : $material;
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
