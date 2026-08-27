<?php

namespace App\Services;

use App\Exceptions\GeminiQuotaException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private string $apiKey = '';
    private string $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

    /**
     * When the last request went out, as a float timestamp. Static so the pacing
     * holds across every instance in one queue worker — the throttle is about the
     * API's limit, not about one object's lifetime.
     */
    private static float $lastRequestAt = 0.0;

    public function __construct(private readonly ImageProcessingService $imageProcessor)
    {
        $this->apiKey = config('services.gemini.api_key') ?? '';
    }

    /**
     * Download image from URL and generate product content using Gemini Vision.
     *
     * @return array{description: string, meta_title: string, meta_description: string, alt_text: string}|null
     */
    public function generateFromImageUrl(string $imageUrl, string $productTitle = '', string $vendor = '', string $productType = '', array $tags = [], array $collections = [], string $sku = '', string $storeName = '', string $existingDescription = '', string $existingMaterial = '', array $existingFeatures = [], array $availableCollections = []): ?array
    {
        try {
            $imageContent = $this->downloadImage($imageUrl);
            if (!$imageContent) return null;

            return $this->generateFromImageBytes($imageContent, $productTitle, $vendor, $productType, $tags, $collections, $sku, $storeName, $existingDescription, $existingMaterial, $existingFeatures, $availableCollections);
        } catch (GeminiQuotaException $e) {
            // Not this image's problem — the account is out. Let it stop the run.
            throw $e;
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
    public function generateFromImageBytes(string $imageBytes, string $productTitle = '', string $vendor = '', string $productType = '', array $tags = [], array $collections = [], string $sku = '', string $storeName = '', string $existingDescription = '', string $existingMaterial = '', array $existingFeatures = [], array $availableCollections = []): ?array
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
        if (!empty($tags))        $context[] = "Tags ALREADY on this product (do not repeat these as new suggestions): " . implode(', ', $tags);
        if (!empty($collections)) $context[] = "Collections this product ALREADY belongs to (do not repeat these as new suggestions): " . implode(', ', $collections);
        if ($existingMaterial)    $context[] = "CONFIRMED material (from store data, not a guess): \"{$existingMaterial}\" — use this exact material, do not visually guess a different one.";
        if (!empty($existingFeatures)) $context[] = "CONFIRMED features already on file (from store data): " . implode(', ', $existingFeatures);
        if (!empty($availableCollections)) $context[] = "Collections that EXIST in this store and could potentially apply (choose only from this exact list, never invent a new one): " . implode(', ', $availableCollections);
        if ($existingDescription) {
            $plainExisting = trim(strip_tags($existingDescription));
            if ($plainExisting) $context[] = "Existing product description already on the store (for reference only — may be outdated or inaccurate, do not copy blindly, but stay consistent with any facts here that you can also visually confirm):\n\"{$plainExisting}\"";
        }
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

Color-neutral rule (critical — this product likely comes in multiple color options, and this photo shows only one of them):
- The \"description\", \"meta_title\", \"meta_description\", and \"title\" fields below are shared across EVERY color variant of this product, not just the one shown in this photo. NEVER name or imply a specific color in those four fields (no \"red\", \"navy\", \"the elegant white...\", etc.) — describe silhouette, pattern, finish, texture, and material instead.
- The ONLY exception is \"alt_text\": that field describes this exact photo for accessibility, so it SHOULD name the actual color visible in this image.

Strict accuracy rules:
- Base every statement strictly on visible evidence of the product: shape, visible material/texture, visible parts (e.g. wheels, straps, zippers, handles, buttons, stitching, pockets, logos, patterns) — never its color, per the color-neutral rule above.
- Do NOT invent specifications, materials, capacity, technology, or features that cannot be visually confirmed (e.g. don't say \"waterproof\" or \"lightweight\" unless it's visibly obvious).
- The Brand/vendor, Product title, tags, and collections above (if given) are confirmed real data from the store — use them to determine facts like Gender or Category, but do not invent a brand, gender, or category that isn't supported by that context or clearly visible.
- If a CONFIRMED material is given above, use that exact material in the description and specifications — do NOT visually guess a different material, even if the photo looks like it could be something else. If NO CONFIRMED material was given above, visually identify the material yourself from the photo instead (e.g. \"Cotton\", \"Leather\", \"Denim\", \"Knit Fabric\") based strictly on visible texture, weave, or sheen — only state it if reasonably confident, otherwise omit it rather than guessing. If a CONFIRMED features list is given above, treat those as real facts already established — you can restate them and may add more visible features on top, but never contradict them.
- This store does NOT sell genuine/solid precious metals. Many products merely have a gold-, silver-, or rose-gold-toned finish or plating over a base metal. NEVER describe a product as \"solid gold\", \"pure gold\", \"genuine gold\", or similar — even if it looks shiny and gold-colored in the photo. If the CONFIRMED material or product title says something like \"18K Gold Finished\" or \"Gold-Plated\", use that exact wording (it means a finish/plating, not solid metal). If no material is confirmed and the item merely looks gold-coloured, describe it as \"gold-toned\" or \"gold-finished\" — never imply it is the solid precious metal itself.
- If an existing product description was given above, treat it only as a hint, never as ground truth — it may be outdated, generic, or wrong. Write your own fresh description grounded in the image, but don't contradict a fact from it that you can also visually confirm.
- If unsure about a detail, omit it rather than guessing.
- Do not repeat the same claim in different words to pad length.

Return a JSON object with exactly these fields:

- \"description\": HTML content structured EXACTLY like this. Remember: describe the PRODUCT only — never the model, pose, background, or unrelated items in the photo.
  HARD LIMIT: the entire description, including all HTML tags, must not exceed 1000 characters total. Prioritize the most distinctive, important details and keep sentences concise so it fits — but the HTML must always be well-formed and complete (never cut off a tag or sentence mid-way to hit the limit; shorten the content itself instead).
  1. 2-3 concise narrative paragraphs (<p> tags) in flowing marketing prose, each focused on a different visual aspect of the product:
     - Paragraph 1: overall silhouette, shape, and first impression of the product's style.
     - Paragraph 2: finish and visible material/texture of the product (e.g. sheen, grain, weave) — do not name the specific color, per the color-neutral rule above.
     - Paragraph 3 (only if space allows within the 1000-character limit): distinctive visible design details of the product — hardware, stitching, embellishments, patterns, closures, trims, logos, or construction techniques you can actually see on it.
     Every claim must stay strictly grounded in what's visible on the product or in the confirmed context above — never invent a detail you cannot see, and never describe the model/background/other items.
  2. A heading: <p><strong>SPECIFICATIONS</strong></p>
  3. A bullet list <ul><li>...</li></ul> containing:
     - \"Brand: {{vendor}}\" as the first bullet, only if brand/vendor was given above.
     - \"Material: {{material}}\" as the next bullet: if a CONFIRMED material was given above, use that exact value verbatim, never a visually-guessed one. If no CONFIRMED material was given, include this bullet only if you can visually identify the material with reasonable confidence from the photo (e.g. \"Cotton\", \"Leather\", \"Knit Fabric\") — omit the bullet entirely if it cannot be confidently determined visually. Never include a percentage composition breakdown either way — state the material name(s) only.
     - \"Gender: {{Women/Men/Unisex/Kids}}\" as the next bullet, only if clearly indicated by the product title, type, tags, or collections above (e.g. a tag or collection name containing \"Women\", \"Men\", \"Kids\") — omit entirely if not determinable.
     - 3-5 more bullets, each a short factual highlight restating a visible detail or craftsmanship point already covered in the paragraphs above (construction technique, notable visible feature, finish — plus any CONFIRMED features given above; never a color, per the color-neutral rule above). Do not introduce new unverified facts in the bullets.
  Stay within the 1000-character total limit — trim paragraph count, sentence length, or bullet count as needed to fit, always keeping valid, complete HTML.
- \"meta_title\": An SEO page title (max 60 characters) that accurately reflects the product type and its main visible style/design attribute (e.g. silhouette, pattern, material) — no mention of model/background, and never a specific color, per the color-neutral rule above.
- \"meta_description\": An SEO meta description (max 160 characters) summarizing only the product's visible/confirmed attributes — no mention of model/background, and never a specific color, per the color-neutral rule above. If a store name was given above, naturally work the store name and \"Qatar\" into the sentence (e.g. \"...available at {{store name}} in Qatar.\") while staying within 160 characters — shorten the product details if needed to fit both in.
- \"alt_text\": A concise, literal description of the PRODUCT itself for accessibility (max 125 characters) — e.g. \"Light blue relaxed-fit shorts with side pockets and elasticated waistband\". Unlike the fields above, this one SHOULD name the actual color shown in THIS photo, since it describes this specific image, not the whole product across all its color variants. Do NOT describe a person/model wearing it, their pose, or the background — describe the garment/item as if on its own. If the product has two or more similar parts (e.g. two ends of a bracelet, a pair of earrings), do NOT assume they look the same — describe only what THIS specific image actually shows, which may be a back/reverse angle where the parts genuinely differ.
- \"title\": A clear, accurate SEO-friendly product title (max 80 characters) reflecting brand, product type, and main visible style/design attribute — grounded in the same accuracy rules as everything else, and never a specific color, per the color-neutral rule above. This is a SUGGESTION for the merchant to review, not automatically applied.
- \"new_tags\": An array of 3-8 short, genuinely NEW descriptive tags for this product (e.g. colour, material, style, occasion) that are NOT already in the \"Tags ALREADY on this product\" list above. Do not repeat existing tags. Return an empty array if you have nothing confident to add.
- \"new_collections\": An array of collection names this product should ALSO belong to, chosen ONLY from the \"Collections that EXIST in this store\" list given above (if one was given) — copy the name exactly as listed. Only include a collection if the product clearly, confidently fits it based on visible/confirmed facts. Never invent a collection name not in that list. Return an empty array if unsure or if no list was given.

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
            'title'            => mb_substr(trim($data['title'] ?? ''), 0, 80),
            'new_tags'         => is_array($data['new_tags'] ?? null) ? array_values(array_filter(array_map('trim', $data['new_tags']))) : [],
            'new_collections'  => is_array($data['new_collections'] ?? null) ? array_values(array_filter(array_map('trim', $data['new_collections']))) : [],
        ];
    }

    /**
     * Generate description/meta content with NO image at all — used when a
     * product has zero images in Shopify. Grounded strictly in confirmed store
     * data (title, vendor, type, tags, collections, existing description);
     * never invents visual details since there's nothing to look at. No alt
     * text is produced here — alt text belongs to an image, and there isn't one.
     *
     * @return array{description: string, meta_title: string, meta_description: string}|null
     */
    public function generateFromTextOnly(string $productTitle = '', string $vendor = '', string $productType = '', array $tags = [], array $collections = [], string $sku = '', string $storeName = '', string $existingDescription = '', string $existingMaterial = '', array $existingFeatures = [], array $availableCollections = []): ?array
    {
        $context = [];
        if ($productTitle)   $context[] = "Product title: \"{$productTitle}\"";
        if ($sku)            $context[] = "SKU: \"{$sku}\"";
        if ($vendor)         $context[] = "Brand/vendor: \"{$vendor}\"";
        if ($productType)    $context[] = "Product type/category: \"{$productType}\"";
        if ($storeName)      $context[] = "Store name: \"{$storeName}\" (based in Qatar)";
        if (!empty($tags))        $context[] = "Tags ALREADY on this product (do not repeat these as new suggestions): " . implode(', ', $tags);
        if (!empty($collections)) $context[] = "Collections this product ALREADY belongs to (do not repeat these as new suggestions): " . implode(', ', $collections);
        if ($existingMaterial)    $context[] = "CONFIRMED material (from store data): \"{$existingMaterial}\"";
        if (!empty($existingFeatures)) $context[] = "CONFIRMED features already on file (from store data): " . implode(', ', $existingFeatures);
        if (!empty($availableCollections)) $context[] = "Collections that EXIST in this store and could potentially apply (choose only from this exact list, never invent a new one): " . implode(', ', $availableCollections);
        if ($existingDescription) {
            $plainExisting = trim(strip_tags($existingDescription));
            if ($plainExisting) $context[] = "Existing product description already on the store (you may draw on its facts, but rephrase and improve rather than copy verbatim):\n\"{$plainExisting}\"";
        }

        if (empty($context)) return null; // nothing at all to work with

        $contextBlock = implode("\n", $context) . "\n\n";

        $prompt = "No product image is available for this item. Write e-commerce content based ONLY on the confirmed store data below.

{$contextBlock}Writing style rules (apply to every field below):
- Use British English spelling throughout (e.g. colour, favourite, personalise, grey, fibre, moisturiser) — never American spelling.
- Do NOT use generic marketing clichés or vague filler phrases such as \"a true embodiment of\", \"timeless sophistication\", \"perfect for every occasion\", \"elevate your style\", \"must-have\", \"effortlessly chic\", \"the epitome of\", or similar stock phrases.
- Vary how paragraphs open — do not default to \"This product...\" or \"These [item]...\" every time.
- NEVER reference an image, photo, or picture (there isn't one for this product) — write as a direct product description.
- NEVER use HTML entities (e.g. \"&nbsp;\", \"&amp;\", \"&#39;\"). Use plain characters only. Only HTML tags allowed are <p>, <strong>, <ul>, <li>.

Color-neutral rule (critical — this product likely comes in multiple color options, and none is confirmed here): the \"description\", \"meta_title\", \"meta_description\", and \"title\" fields are shared across EVERY color variant of this product. Do not name or invent any color, even one that seems implied by the title or existing description, unless it is the product's own name for a finish (e.g. \"Gold-Plated\" as a material, not a color) — describe silhouette, material, and category instead.

Strict accuracy rules (critical — there is no image, so extra caution applies):
- Base every statement ONLY on the confirmed data given above (title, brand, product type, tags, collections, existing description). Do NOT invent colour, material, texture, pattern, shape, or any other visual specific you have no evidence for — you cannot see this product.
- If the title or existing description implies a fact (e.g. the title says \"Leather Jacket\", so material is leather), you may state it, but do not add further unverified detail on top of it.
- This store does NOT sell genuine/solid precious metals. NEVER describe a product as \"solid gold\", \"pure gold\", \"genuine gold\", or similar. If the CONFIRMED material or product title says something like \"18K Gold Finished\" or \"Gold-Plated\", use that exact wording (it means a finish/plating, not solid metal) — never upgrade it to imply solid precious metal.
- If there is not enough information to write something concrete, keep that sentence general rather than guessing specifics.

Return a JSON object with exactly these fields:

- \"description\": HTML content structured EXACTLY like this:
  HARD LIMIT: the entire description, including all HTML tags, must not exceed 1000 characters total.
  1. 2-3 concise narrative paragraphs (<p> tags) — general, honest copy grounded only in the confirmed data above, never claiming specific visual details you don't have evidence for.
  2. A heading: <p><strong>SPECIFICATIONS</strong></p>
  3. A bullet list <ul><li>...</li></ul> containing:
     - \"Brand: {{vendor}}\" as the first bullet, only if brand/vendor was given above.
     - \"Material: {{material}}\" as the next bullet, only if a CONFIRMED material was given above.
     - \"Gender: {{Women/Men/Unisex/Kids}}\" as the next bullet, only if clearly indicated by the product title, type, tags, or collections above — omit entirely if not determinable.
     - Additional bullets only for facts clearly supported by the confirmed data above (e.g. product type, category, CONFIRMED features) — do not invent visual specifics as bullets.
  Always keep valid, complete HTML.
- \"meta_title\": An SEO page title (max 60 characters) based on the confirmed product title/type/brand.
- \"meta_description\": An SEO meta description (max 160 characters) summarizing only the confirmed attributes. If a store name was given above, naturally work the store name and \"Qatar\" into the sentence while staying within 160 characters.
- \"title\": A clear, accurate SEO-friendly product title (max 80 characters) based only on the confirmed data above. This is a SUGGESTION for the merchant to review, not automatically applied.
- \"new_tags\": An array of 3-8 short, genuinely NEW descriptive tags for this product that are NOT already in the \"Tags ALREADY on this product\" list above and are clearly supported by the confirmed data. Return an empty array if you have nothing confident to add.
- \"new_collections\": An array of collection names this product should ALSO belong to, chosen ONLY from the \"Collections that EXIST in this store\" list given above (if one was given) — copy the name exactly as listed. Only include if clearly, confidently supported by the confirmed data. Never invent a collection name. Return an empty array if unsure or if no list was given.

Return only valid JSON. No markdown, no code blocks, no extra text.";

        $payload = [
            'contents' => [[
                'parts' => [['text' => $prompt]],
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
            'title'            => mb_substr(trim($data['title'] ?? ''), 0, 80),
            'new_tags'         => is_array($data['new_tags'] ?? null) ? array_values(array_filter(array_map('trim', $data['new_tags']))) : [],
            'new_collections'  => is_array($data['new_collections'] ?? null) ? array_values(array_filter(array_map('trim', $data['new_collections']))) : [],
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
        } catch (GeminiQuotaException $e) {
            throw $e;
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
If the product has two or more similar parts (e.g. two ends of a bracelet, a pair of earrings, two straps), do NOT assume they look the same — describe each part only as it actually appears in THIS photo. This image may show a different angle (e.g. a back/reverse view) where the parts genuinely look different from other photos of the same product — describe what you actually see here, not what would be typical or symmetric.
This store does NOT sell genuine/solid precious metals — if something looks gold/silver/rose-gold coloured, describe it as \"gold-toned\" or \"gold-finished\", never as \"gold\", \"solid gold\", or \"genuine gold\".
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

    /**
     * Look at a product photo and say which side of the garment is showing,
     * so the caller can decide whether a generative apparel mode (which only
     * knows how to build a front view) is appropriate for this specific shot.
     *
     * Never returns null — an unreadable response degrades to the same
     * 'unknown'/false sentinel as a low-confidence read, so a Gemini hiccup
     * fails open (caller keeps its original behaviour) instead of failing
     * the whole item edit.
     *
     * @return array{view_type: string, mannequin_visible: bool}
     */
    public function classifyGarmentView(string $imageBytes): array
    {
        $fallback = ['view_type' => 'unknown', 'mannequin_visible' => false];

        $imageBytes = $this->shrinkForApi($imageBytes);
        $mimeType   = $this->detectMimeType($imageBytes);
        $base64     = base64_encode($imageBytes);

        $prompt = "Look at this product photo and classify which side of the garment/item is facing the camera.

Return a JSON object with exactly these fields:
- \"view_type\": one of \"front\", \"back\", \"side\", \"flat_lay\", \"unknown\".
  - \"front\": the front of the garment is visible (buttons, collar front, logo placement, the side you'd normally photograph for a catalog).
  - \"back\": the reverse/back of the garment is visible (back of collar, no buttons/placket, back seams).
  - \"side\": a profile or three-quarter angle — neither clearly front nor back.
  - \"flat_lay\": the item is laid flat or hung against a plain background with nothing holding it up in frame at all.
  - \"unknown\": you cannot confidently tell from this image.
- \"mannequin_visible\": true if ANY support the item is displayed on is visible in the frame — a mannequin, dress form, bust, headless body, clothes rail, garment rack, hanger, hook, or stand. False only when the item is alone in the frame.

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
                'temperature'      => 0.1,
            ],
        ];

        $response = $this->postWithRetry($payload);
        if (!$response) return $fallback;

        $text = $response->json('candidates.0.content.parts.0.text') ?? '';
        if (!$text) return $fallback;

        $data = json_decode($text, true);
        if (!is_array($data)) return $fallback;

        $viewType = $data['view_type'] ?? 'unknown';

        return [
            'view_type'         => in_array($viewType, ['front', 'back', 'side', 'flat_lay', 'unknown'], true) ? $viewType : 'unknown',
            'mannequin_visible' => (bool) ($data['mannequin_visible'] ?? false),
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

            $this->throttle();

            try {
                // The key goes in a header, not the query string. As a query
                // param it ended up inside every Guzzle exception message and
                // from there into the log, in plain text, on every timeout.
                $response = Http::timeout(30)
                    ->withHeaders(['x-goog-api-key' => $this->apiKey])
                    ->post($this->endpoint, $payload);

                if ($response->successful()) {
                    return $response;
                }

                Log::warning('Gemini API error', ['attempt' => $attempt, 'status' => $response->status(), 'body' => self::scrub($response->body())]);

                // A 429 carries two very different meanings. "Slow down" is
                // worth waiting out; "your balance is empty" is not, and
                // retrying it only spends the session's time to be told the
                // same thing again.
                if ($response->status() === 429 && GeminiQuotaException::matches($response->body())) {
                    throw GeminiQuotaException::fromResponseBody($response->body());
                }

                // Over the rate limit. Google says how long to wait; if it does
                // not, back off further each time. Worth more attempts than a
                // normal error — the request was fine, we were just early.
                if ($response->status() === 429) {
                    $wait = (int) ($response->header('Retry-After') ?: $retryDelaySeconds * $attempt * 2);
                    sleep(min($wait, 60));
                    $retries = max($retries, 3);
                    continue;
                }
            } catch (GeminiQuotaException $e) {
                throw $e;
            } catch (\Throwable $e) {
                Log::warning('Gemini API exception', ['attempt' => $attempt, 'error' => self::scrub($e->getMessage())]);
            }

            if ($attempt <= $retries) {
                sleep($retryDelaySeconds);
            }
        } while ($attempt <= $retries);

        return null;
    }

    /**
     * Wait only as long as the rate limit actually requires.
     *
     * The job used to sleep a flat 4 seconds after every call, on top of however
     * long the call itself took — so a 3-second generation became 7, and the real
     * rate sat well under even the free tier's allowance. This measures from the
     * last request instead: the API's own latency counts towards the interval, and
     * nothing is spent waiting that does not have to be.
     */
    private function throttle(): void
    {
        $rpm = max(1, (int) config('services.gemini.rpm', 10));
        $gap = 60 / $rpm;

        $since = microtime(true) - self::$lastRequestAt;

        if (self::$lastRequestAt > 0.0 && $since < $gap) {
            usleep((int) (($gap - $since) * 1_000_000));
        }

        self::$lastRequestAt = microtime(true);
    }

    /**
     * Belt and braces: never let a key reach the log even if some other call
     * still puts one in a URL. A leaked key is not recoverable by editing the
     * log afterwards — it has to be rotated.
     */
    private static function scrub(string $message): string
    {
        return (string) preg_replace('/([?&](?:key|api_key)=)[^&\s"\']+/i', '$1[redacted]', $message);
    }

    /**
     * Can this server actually generate with Gemini right now?
     *
     * Two different things can stop a session before it starts, and this has to
     * catch both. Outbound access to Google being blocked makes every call die on
     * a 30-second timeout, so a whole session grinds to the worker's kill. An
     * empty prepaid balance is worse: the request goes through fine and comes
     * back 429 RESOURCE_EXHAUSTED, which the retry ladder then spends ~38 seconds
     * per SKU rediscovering.
     *
     * This deliberately calls generateContent rather than listing models. Listing
     * models works perfectly well with a depleted balance, so it reported "the key
     * works" while every real call was being refused — a false all-clear that let
     * a doomed session burn an hour. Only a call that actually spends can prove
     * the account can spend.
     *
     * @return array{ok: bool, message: string}
     */
    public function ping(): array
    {
        if ($this->apiKey === '') {
            return ['ok' => false, 'message' => 'No Gemini API key is configured.'];
        }

        try {
            // The smallest generation that still bills: a one-token reply. Cheap
            // enough to run before every session, real enough to prove spend.
            $response = Http::timeout(15)
                ->withHeaders(['x-goog-api-key' => $this->apiKey])
                ->post($this->endpoint, [
                    'contents'         => [['parts' => [['text' => 'hi']]]],
                    'generationConfig' => ['maxOutputTokens' => 1],
                ]);

            if ($response->successful()) {
                return ['ok' => true, 'message' => 'Gemini is reachable and the account can generate.'];
            }

            // An exhausted balance or daily cap will not clear by waiting, so say
            // so in the words the operator needs rather than a raw status code.
            if ($response->status() === 429 && GeminiQuotaException::matches($response->body())) {
                return ['ok' => false, 'message' => GeminiQuotaException::fromResponseBody($response->body())->getMessage()];
            }

            return [
                'ok'      => false,
                'message' => 'Google answered ' . $response->status() . ': ' . self::scrub(\Illuminate\Support\Str::limit($response->body(), 200)),
            ];
        } catch (\Throwable $e) {
            return [
                'ok'      => false,
                'message' => 'Could not reach generativelanguage.googleapis.com — ' . self::scrub($e->getMessage())
                           . ' This is outbound network access from the server, not the key.',
            ];
        }
    }
}
