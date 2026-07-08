<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FanarService
{
    private string $apiKey = '';
    private string $endpoint = 'https://api.fanar.qa/v1/chat/completions';

    public function __construct()
    {
        $this->apiKey = config('services.fanar.api_key') ?? '';
    }

    /**
     * Translate English e-commerce content to natural Arabic using Fanar's
     * chat model (Fanar-C-2-27B) rather than the literal MT model
     * (Fanar-Shaheen-MT-1) — the MT model translates word-for-word and
     * mangles fashion/marketing terms; the chat LLM produces natural
     * marketing Arabic.
     *
     * @param string $preprocessing "default" for plain text, "preserve_html" when the text contains HTML tags
     */
    public function translateToArabic(string $text, string $preprocessing = 'default'): ?string
    {
        if (!$text) return null;

        $htmlRule = $preprocessing === 'preserve_html'
            ? "\n- The text contains HTML tags (<p>, <strong>, <ul>, <li>). Keep every tag exactly as it is, in the same positions — translate only the text between the tags."
            : '';

        $prompt = "Translate the following English e-commerce product content into natural, fluent Modern Standard Arabic for a Qatar-based online store.

Rules:
- Write as a native Arabic marketing copywriter would — natural and idiomatic, never a literal word-for-word translation.
- Use the correct established Arabic terminology for fashion, jewellery, and retail terms (e.g. \"cushion-cut\" is a gem cut style, \"silhouette\" refers to the garment's overall shape, \"clear crystal\" means transparent crystal).
- When English uses two different adjectives, use two distinct Arabic adjectives — never repeat the same word twice.
- Keep brand names, SKUs, product codes, and technical values (e.g. 18K, 100ml) in Latin script exactly as written.{$htmlRule}
- Output ONLY the Arabic translation — no explanations, no preamble, no quotation marks around it, no notes.

English text:
{$text}";

        $response = $this->postWithRetry([
            'model'       => 'Fanar-C-2-27B',
            'messages'    => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.2,
        ]);

        if (!$response) return null;

        $translated = trim($response->json('choices.0.message.content') ?? '');

        // Strip markdown code fences or wrapping quotes the model may add despite instructions
        $translated = preg_replace('/^```[a-z]*\s*|\s*```$/', '', $translated);
        $translated = trim($translated, "\"'« »\u{201C}\u{201D} \t\n\r");

        return $translated ?: null;
    }

    /**
     * POST to Fanar with automatic retries. Network errors and 5xx retry
     * after a short delay; 429 rate limits wait much longer before retrying,
     * since retrying a rate limit within seconds just burns the attempt.
     */
    private function postWithRetry(array $payload, int $retries = 2, int $retryDelaySeconds = 3)
    {
        $attempt = 0;

        do {
            $attempt++;
            $isRateLimited = false;

            try {
                $response = Http::withHeaders([
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type'  => 'application/json',
                ])->timeout(60)->post($this->endpoint, $payload);

                if ($response->successful()) {
                    return $response;
                }

                $isRateLimited = $response->status() === 429;
                Log::warning('Fanar API error', ['attempt' => $attempt, 'status' => $response->status(), 'body' => $response->body()]);
            } catch (\Throwable $e) {
                Log::warning('Fanar API exception', ['attempt' => $attempt, 'error' => $e->getMessage()]);
            }

            if ($attempt <= $retries) {
                sleep($isRateLimited ? 65 : $retryDelaySeconds); // 65s clears a full per-minute rate window
            }
        } while ($attempt <= $retries);

        return null;
    }
}
