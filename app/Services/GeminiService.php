<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private string $apiKey = '';
    private string $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key', '');
    }

    /**
     * Download image from URL and generate product content using Gemini Vision.
     *
     * @return array{description: string, meta_title: string, meta_description: string, alt_text: string}|null
     */
    public function generateFromImageUrl(string $imageUrl, string $productTitle = '', string $vendor = '', string $productType = '', array $tags = [], array $collections = [], string $sku = ''): ?array
    {
        try {
            $imageContent = $this->downloadImage($imageUrl);
            if (!$imageContent) return null;

            return $this->generateFromImageBytes($imageContent, $productTitle, $vendor, $productType, $tags, $collections, $sku);
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
    public function generateFromImageBytes(string $imageBytes, string $productTitle = '', string $vendor = '', string $productType = '', array $tags = [], array $collections = [], string $sku = ''): ?array
    {
        $mimeType = $this->detectMimeType($imageBytes);
        $base64   = base64_encode($imageBytes);

        $context = [];
        if ($productTitle)   $context[] = "Product title: \"{$productTitle}\"";
        if ($sku)            $context[] = "SKU: \"{$sku}\"";
        if ($vendor)         $context[] = "Brand/vendor: \"{$vendor}\"";
        if ($productType)    $context[] = "Product type/category: \"{$productType}\"";
        if (!empty($tags))        $context[] = "Store tags on this product: " . implode(', ', $tags);
        if (!empty($collections)) $context[] = "Collections this product belongs to: " . implode(', ', $collections);
        $contextBlock = $context ? implode("\n", $context) . "\n\n" : '';

        $prompt = "Look carefully at this product image and describe ONLY what you can actually see.

{$contextBlock}Strict accuracy rules:
- Base every statement strictly on visible evidence in the image: color, shape, visible material/texture, visible parts (e.g. wheels, straps, zippers, handles, buttons, stitching, pockets, logos, patterns).
- Do NOT invent specifications, materials, capacity, technology, or features that cannot be visually confirmed (e.g. don't say \"waterproof\" or \"lightweight\" unless it's visibly obvious).
- The Brand/vendor, Product title, tags, and collections above (if given) are confirmed real data from the store — use them to determine facts like Gender or Category, but do not invent a brand, gender, or category that isn't supported by that context or clearly visible.
- If unsure about a detail, omit it rather than guessing.
- Do not repeat the same claim in different words to pad length.

Return a JSON object with exactly these fields:

- \"description\": HTML content structured EXACTLY like this:
  1. 3-4 rich narrative paragraphs (<p> tags) in flowing marketing prose, each focused on a different visual aspect so the description is thorough and detailed:
     - Paragraph 1: overall silhouette, shape, and first impression of style.
     - Paragraph 2: color, finish, and visible material/texture in detail (e.g. gradient tones, sheen, grain, weave).
     - Paragraph 3: distinctive visible design details — hardware, stitching, embellishments, patterns, closures, trims, logos, or construction techniques you can actually see.
     - Paragraph 4 (if there is enough visible detail to justify it): how the visible design elements come together / suggested styling or use, staying grounded in what's shown.
     Every claim must stay strictly grounded in what's visible or in the confirmed context above — describe thoroughly and vividly, but never invent a detail you cannot see.
  2. A heading: <p><strong>SPECIFICATIONS</strong></p>
  3. A bullet list <ul><li>...</li></ul> containing:
     - \"Brand: {{vendor}}\" as the first bullet, only if brand/vendor was given above.
     - \"SKU: {{sku}}\" as the next bullet, only if a SKU was given above — use it exactly as given, do not modify it.
     - \"Gender: {{Women/Men/Unisex/Kids}}\" as the next bullet, only if clearly indicated by the product title, type, tags, or collections above (e.g. a tag or collection name containing \"Women\", \"Men\", \"Kids\") — omit entirely if not determinable.
     - 4-7 more bullets, each a short factual highlight restating a visible detail or craftsmanship point already covered in the paragraphs above (color, material, construction technique, notable visible feature). Do not introduce new unverified facts in the bullets.
  Be as thorough and detailed as the image genuinely supports — but never pad with repeated, vague, or invented claims.
- \"meta_title\": An SEO page title (max 60 characters) that accurately reflects the visible product type and its main visible attribute (e.g. color or style).
- \"meta_description\": An SEO meta description (max 160 characters) summarizing only the visible/confirmed attributes.
- \"alt_text\": A concise, literal description of exactly what is shown in the image for accessibility (max 125 characters) — e.g. \"Navy blue hardshell suitcase with beige trim and spinner wheels\".

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

        $response = Http::timeout(30)
            ->post("{$this->endpoint}?key={$this->apiKey}", $payload);

        if (!$response->successful()) {
            Log::error('Gemini API error', ['status' => $response->status(), 'body' => $response->body()]);
            return null;
        }

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
}
