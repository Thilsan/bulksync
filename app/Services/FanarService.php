<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FanarService
{
    private string $apiKey = '';
    private string $endpoint = 'https://api.fanar.qa/v1/translations';

    public function __construct()
    {
        $this->apiKey = config('services.fanar.api_key') ?? '';
    }

    /**
     * Translate English text to Arabic.
     *
     * @param string $preprocessing "default" for plain text, "preserve_html" for HTML content (keeps tags intact)
     */
    public function translateToArabic(string $text, string $preprocessing = 'default'): ?string
    {
        if (!$text) return null;

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type'  => 'application/json',
            ])->timeout(30)->post($this->endpoint, [
                'model'         => 'Fanar-Shaheen-MT-1',
                'text'          => $text,
                'langpair'      => 'en-ar',
                'preprocessing' => $preprocessing,
            ]);

            if (!$response->successful()) {
                Log::error('Fanar API error', ['status' => $response->status(), 'body' => $response->body()]);
                return null;
            }

            return trim($response->json('text') ?? '') ?: null;
        } catch (\Throwable $e) {
            Log::error('FanarService::translateToArabic failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
