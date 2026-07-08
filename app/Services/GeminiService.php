<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private string $apiKey = '';
    private string $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

    public function __construct(private readonly ImageProcessingService $imageProcessor)
    {
        $this->apiKey = config('services.gemini.api_key') ?? '';
    }

    /**
     * Download image from URL and generate product content using Gemini Vision.
     *
     * @return array{description: string, meta_title: string, meta_description: string, alt_text: string}|null
     */
    public function generateFromImageUrl(string $imageUrl, string $productTitle = '', string $vendor = '', string $productType = '', array $tags = [], array $collections = [], string $sku = '', string $storeName = ''): ?array
    {
        try {
            $imageContent = $this->downloadImage($imageUrl);
            if (!$imageContent) return null;

            return $this->generateFromImageBytes($imageContent, $productTitle, $vendor, $productType, $tags, $collections, $sku, $storeName);
        } catch (\Throwable $e) {
            Log::error('GeminiService::generateFromImageUrl failed', ['url' => $imageUrl, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Generate product content from raw image bytes.
     *
     * @return array{description: string, meta_title: string, meta_description: string, alt_text: string}|null
     */
    public function generateFromImageBytes(string $imageBytes, string $productTitle = '', string $vendor = '', string $productType = '', array $tags = [], array $collections = [], string $sku = '', string $storeName = ''): ?array
    {
        $imageBytes = $this->shrinkForApi($imageBytes);
        $mimeType   = $this->detectMimeType($imageBytes);
        $base64     = base64_encode($imageBytes);

        $context = [];
        if ($productTitle)   $context[] = "Product title: \"{$productTitle}\"";
        if ($sku)            $context[] = "SKU: \"{$sku}\"";
        if ($vendor)         $context[] = "Brand/vendor: \"{$vendor}\"";
        if ($productType)    $context[] = "Product type/category: \"{$productType}\"";
        if ($storeName)      $context[] = "Store name: \"{$storeName}\" (based in Qatar)";
        if (!empty($tags))        $context[] = "Store tags on this product: " . implode(', ', $tags);
        if (!empty($collections)) $context[] = "Collections this product belongs to: " . implode(', ', $collections);
        $contextBlock = $context ? implode("\n", $context) . "\n\n" : '';

        $prompt = "Look carefully at this image and describe ONLY the product itself — not the photo.

{$contextBlock}Product-only focus rules (critical):
- This image may show the product on a model, mannequin, or in a lifestyle setting. Describe ONLY the product (the garment/item itself) — completely ignore and never mention the model/person, their pose, face, expression, hairstyle, body, skin, or any body part.
- Ignore and never mention the background, setting, studio backdrop, lighting, or photo composition.
- Ignore and never mention any other item in the photo that is not the product itself (e.g. if this is a top or shorts, do not mention shoes, socks, jewelry, or other garments the model happens to be wearing, unless they are explicitly part of the same product/set being sold).
- Write as if describing the product laid flat or on a hanger, purely from a product-catalog perspective.

Writing style rules (apply to every field below):
- Use British English spelling throughout (e.g. colour, favourite, personalise, grey, fibre, moisturiser) — never American spelling.
- Do NOT use generic marketing clichés or vague filler phrases such as \"a true embodiment of\", \"timeless sophistication\", \"perfect for every occasion\", \"elevate your style\", \"must-have\", \"effortlessly chic\", \"the epitome of\", or similar stock phrases. Every sentence must state a specific, concrete visual fact about the product instead of a vague compliment.
- Vary how paragraphs open — do not default to \"This product...\" or \"These [item]...\" every time. Use different natural sentence structures.
- NEVER reference the image, photo, or picture itself (e.g. never write \"as shown in the image\", \"this photo displays\", \"pictured here\"). Write as a direct product description, not as a description of a photograph.
- NEVER use HTML entities (e.g. \"&nbsp;\", \"&amp;\", \"&#39;\"). Use plain characters only (a normal space, the word \"and\", a normal apostrophe). Only HTML tags allowed are <p>, <strong>, <ul>, <li>.

Strict accuracy rules:
- Base every statement strictly on visible evidence of the product: color, shape, visible material/texture, visible parts (e.g. wheels, straps, zippers, handles, buttons, stitching, pockets, logos, patterns).
- Do NOT invent specifications, materials, capacity, technology, or features that cannot be visually confirmed (e.g. don't say \"waterproof\" or \"lightweight\" unless it's visibly obvious).
- The Brand/vendor, Product title, tags, and collections above (if given) are confirmed real data from the store — use them to determine facts like Gender or Category, but do not invent a brand, gender, or category that isn't supported by that context or clearly visible.
- If unsure about a detail, omit it rather than guessing.
- Do not repeat the same claim in different words to pad length.

Return a JSON object with exactly these fields:

- \"description\": HTML content structured EXACTLY like this. Remember: describe the PRODUCT only — never the model, pose, background, or unrelated items in the photo.
  HARD LIMIT: the entire description, including all HTML tags, must not exceed 1000 characters total. Prioritize the most distinctive, important details and keep sentences concise so it fits — but the HTML must always be well-formed and complete (never cut off a tag or sentence mid-way to hit the limit; shorten the content itself instead).
  1. 2-3 concise narrative paragraphs (<p> tags) in flowing marketing prose, each focused on a different visual aspect of the product:
     - Paragraph 1: overall silhouette, shape, and first impression of the product's style.
     - Paragraph 2: color, finish, and visible material/texture of the product (e.g. gradient tones, sheen, grain, weave).
     - Paragraph 3 (only if space allows within the 1000-character limit): distinctive visible design details of the product — hardware, stitching, embellishments, patterns, closures, trims, logos, or construction techniques you can actually see on it.
     Every claim must stay strictly grounded in what's visible on the product or in the confirmed context above — never invent a detail you cannot see, and never describe the model/background/other items.
  2. A heading: <p><strong>SPECIFICATIONS</strong></p>
  3. A bullet list <ul><li>...</li></ul> containing:
     - \"Brand: {{vendor}}\" as the first bullet, only if brand/vendor was given above.
     - \"SKU: {{sku}}\" as the next bullet, only if a SKU was given above — use it exactly as given, do not modify it.
     - \"Gender: {{Women/Men/Unisex/Kids}}\" as the next bullet, only if clearly indicated by the product title, type, tags, or collections above (e.g. a tag or collection name containing \"Women\", \"Men\", \"Kids\") — omit entirely if not determinable.
     - 3-5 more bullets, each a short factual highlight restating a visible detail or craftsmanship point already covered in the paragraphs above (color, material, construction technique, notable visible feature). Do not introduce new unverified facts in the bullets.
  Stay within the 1000-character total limit — trim paragraph count, sentence length, or bullet count as needed to fit, always keeping valid, complete HTML.
- \"meta_title\": An SEO page title (max 60 characters) that accurately reflects the product type and its main visible attribute (e.g. color or style) — no mention of model/background.
- \"meta_description\": An SEO meta description (max 160 characters) summarizing only the product's visible/confirmed attributes — no mention of model/background. If a store name was given above, naturally work the store name and \"Qatar\" into the sentence (e.g. \"...available at {{store name}} in Qatar.\") while staying within 160 characters — shorten the product details if needed to fit both in.
- \"alt_text\": A concise, literal description of the PRODUCT itself for accessibility (max 125 characters) — e.g. \"Light blue relaxed-fit shorts with side pockets and elasticated waistband\". Do NOT describe a person/model wearing it, their pose, or the background — describe the garment/item as if on its own.

Return only valid JSON. No markdown, no code blocks, no extra text.";

        $payload = [
            'contents' => [[
                'parts' => [
                    ['text' => $prompt],
                    ['inline_data' => ['mime_type' => $mimeType, 'data' => $base64]],
                ],
            ]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature'      => 0.15,
            ],
        ];

        $response = $this->postWithRetry($payload);
        if (!$response) return null;

        $text = $response->json('candidates.0.content.parts.0.text') ?? '';
        if (!$text) return null;

        $data = json_decode($text, true);
        if (!is_array($data)) return null;

        return [
            'description'      => trim($data['description'] ?? ''),
            'meta_title'       => mb_substr(trim($data['meta_title'] ?? ''), 0, 60),
            'meta_description' => mb_substr(trim($data['meta_description'] ?? ''), 0, 160),
            'alt_text'         => mb_substr(trim($data['alt_text'] ?? ''), 0, 125),
        ];
    }

    /**
     * Lightweight alt-text-only generation for additional gallery images
     * (used when a product has more than one image — the hero image's alt
     * text already comes from generateFromImageBytes/Url).
     */
    public function generateAltTextFromUrl(string $imageUrl, string $productTitle = ''): ?string
    {
        try {
            $imageContent = $this->downloadImage($imageUrl);
            if (!$imageContent) return null;

            return $this->generateAltTextFromBytes($imageContent, $productTitle);
        } catch (\Throwable $e) {
            Log::error('GeminiService::generateAltTextFromUrl failed', ['url' => $imageUrl, 'error' => $e->getMessage()]);
            return null;
        }
    }

    public function generateAltTextFromBytes(string $imageBytes, string $productTitle = ''): ?string
    {
        $imageBytes = $this->shrinkForApi($imageBytes);
        $mimeType   = $this->detectMimeType($imageBytes);
        $base64     = base64_encode($imageBytes);

        $titleHint = $productTitle ? " Product title for context: \"{$productTitle}\"." : '';

        $prompt = "Look at this image and write a concise, literal accessibility alt text describing ONLY the product itself (max 125 characters).{$titleHint}
This image may show the product on a model, mannequin, or lifestyle setting — describe ONLY the product/garment (its color, style, visible material, and design details). Do NOT mention the model/person, their pose, face, body, or the background/setting. Do not invent details that aren't visible on the product. Never reference the image/photo itself (e.g. never write \"shown in the image\" or \"pictured\") — describe the product directly.
Return a JSON object: {\"alt_text\": \"...\"}. No markdown, no code blocks, no extra text.";

        $payload = [
            'contents' => [[
                'parts' => [
                    ['text' => $prompt],
                    ['inline_data' => ['mime_type' => $mimeType, 'data' => $base64]],
                ],
            ]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature'      => 0.15,
            ],
        ];

        $response = $this->postWithRetry($payload);
        if (!$response) return null;

        $text = $response->json('candidates.0.content.parts.0.text') ?? '';
        if (!$text) return null;

        $data = json_decode($text, true);
        if (!is_array($data)) return null;

        return mb_substr(trim($data['alt_text'] ?? ''), 0, 125);
    }

    private function downloadImage(string $url): ?string
    {
        try {
            $response = Http::timeout(20)->get($url);
            if (!$response->successful()) return null;
            $body = $response->body();
            return strlen($body) > 1000 ? $body : null;
        } catch (\Throwable $e) {
            Log::warning('GeminiService: image download failed', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function detectMimeType(string $bytes): string
    {
        $header = substr($bytes, 0, 4);
        if (str_starts_with($header, "\xFF\xD8")) return 'image/jpeg';
        if (str_starts_with($header, "\x89PNG"))  return 'image/png';
        if (str_starts_with($header, 'GIF8'))     return 'image/gif';
        if (str_starts_with($header, 'RIFF'))     return 'image/webp';
        return 'image/jpeg';
    }

    /**
     * Shrink the image before sending to Gemini — vision models don't need full
     * resolution to identify colors/textures/details, and a smaller image means
     * fewer tokens and a faster upload. Only affects the copy sent to the API;
     * never touches the original Shopify image.
     */
    private function shrinkForApi(string $imageBytes): string
    {
        try {
            return $this->imageProcessor->scaleDownForAnalysis($imageBytes);
        } catch (\Throwable $e) {
            Log::warning('GeminiService: image resize failed, sending original', ['error' => $e->getMessage()]);
            return $imageBytes;
        }
    }

    /**
     * POST to Gemini with one automatic retry on failure (network error, 429,
     * or 5xx) — a single transient hiccup shouldn't permanently fail an item.
     */
    private function postWithRetry(array $payload, int $retries = 1, int $retryDelaySeconds = 3)
    {
        $attempt = 0;

        do {
            $attempt++;
            try {
                $response = Http::timeout(30)->post("{$this->endpoint}?key={$this->apiKey}", $payload);

                if ($response->successful()) {
                    return $response;
                }

                Log::warning('Gemini API error', ['attempt' => $attempt, 'status' => $response->status(), 'body' => $response->body()]);
            } catch (\Throwable $e) {
                Log::warning('Gemini API exception', ['attempt' => $attempt, 'error' => $e->getMessage()]);
            }

            if ($attempt <= $retries) {
                sleep($retryDelaySeconds);
            }
        } while ($attempt <= $retries);

        return null;
    }
}
