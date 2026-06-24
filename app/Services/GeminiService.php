<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    private string $apiKey = '';
    private string $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent';

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key', '');
    }

    /**
     * Download image from URL and generate product content using Gemini Vision.
     *
     * @return array{description: string, meta_title: string, meta_description: string}|null
     */
    public function generateFromImageUrl(string $imageUrl, string $productTitle = ''): ?array
    {
        try {
            $imageContent = $this->downloadImage($imageUrl);
            if (!$imageContent) return null;

            return $this->generateFromImageBytes($imageContent, $productTitle);
        } catch (\Throwable $e) {
            Log::error('GeminiService::generateFromImageUrl failed', ['url' => $imageUrl, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Generate product content from raw image bytes.
     *
     * @return array{description: string, meta_title: string, meta_description: string}|null
     */
    public function generateFromImageBytes(string $imageBytes, string $productTitle = ''): ?array
    {
        $mimeType = $this->detectMimeType($imageBytes);
        $base64   = base64_encode($imageBytes);

        $titleHint = $productTitle ? " The product is called \"{$productTitle}\"." : '';

        $prompt = "Analyze this product image and generate e-commerce content.{$titleHint}
Return a JSON object with exactly these fields:
- \"description\": A detailed product description (2-3 paragraphs, 100-200 words, HTML allowed)
- \"meta_title\": An SEO page title (max 60 characters, no brand name needed)
- \"meta_description\": An SEO meta description (max 160 characters)

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
                'temperature'      => 0.4,
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
