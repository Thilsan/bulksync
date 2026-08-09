<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tells the Product Creation Request module whether a SKU exists in Cegid.
 *
 * The "none" driver is the shipping default: there is no Cegid API in this app
 * yet, so the answer is "unknown" and Supply Chain records mapping by hand from
 * the request screen. Point config/cegid.php at a real endpoint and every
 * consumer — validation job, hourly re-check, status badges — starts resolving
 * automatically with no other change.
 */
class CegidService
{
    /** True when an automatic lookup is possible; false means manual entry only. */
    public function isConfigured(): bool
    {
        return config('cegid.driver') === 'http' && filled(config('cegid.http.endpoint'));
    }

    /**
     * @param  string[]  $skus
     * @return array<string, bool|null>  sku => true (in Cegid) | false (absent) | null (unknown)
     */
    public function lookup(array $skus): array
    {
        $skus = array_values(array_unique(array_filter(array_map('trim', $skus))));

        if (empty($skus)) {
            return [];
        }

        if (!$this->isConfigured()) {
            // Unknown, not "absent" — the difference matters: an absent answer
            // would wrongly flag every SKU red before Supply Chain has looked.
            return array_fill_keys($skus, null);
        }

        $results = [];
        $missing = [];
        $ttl     = (int) config('cegid.cache_ttl', 900);

        foreach ($skus as $sku) {
            $cached = Cache::get($this->cacheKey($sku));
            if ($cached !== null) {
                $results[$sku] = (bool) $cached;
            } else {
                $missing[] = $sku;
            }
        }

        foreach (array_chunk($missing, max(1, (int) config('cegid.http.batch_size', 100))) as $chunk) {
            $fetched = $this->viaHttp($chunk);

            foreach ($chunk as $sku) {
                $value = $fetched[$sku] ?? null;
                $results[$sku] = $value;

                // Never cache "unknown" — that is a transport failure, not an answer.
                if ($value !== null) {
                    Cache::put($this->cacheKey($sku), $value, $ttl);
                }
            }
        }

        return $results;
    }

    /** Convenience for single-SKU callers. */
    public function has(string $sku): ?bool
    {
        return $this->lookup([$sku])[$sku] ?? null;
    }

    public function forget(string $sku): void
    {
        Cache::forget($this->cacheKey($sku));
    }

    /**
     * @param  string[]  $skus
     * @return array<string, bool|null>
     */
    private function viaHttp(array $skus): array
    {
        try {
            $request = Http::timeout((int) config('cegid.http.timeout', 30))
                ->acceptJson();

            if ($token = config('cegid.http.token')) {
                $request = $request->withToken($token);
            }

            $response = $request->post(config('cegid.http.endpoint'), ['skus' => $skus]);

            if (!$response->successful()) {
                Log::warning('CegidService: lookup failed', [
                    'status' => $response->status(),
                    'count'  => count($skus),
                ]);

                return array_fill_keys($skus, null);
            }

            $payload = $response->json();
            $map     = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

            if (!is_array($map)) {
                return array_fill_keys($skus, null);
            }

            $out = [];
            foreach ($skus as $sku) {
                $out[$sku] = array_key_exists($sku, $map) ? (bool) $map[$sku] : false;
            }

            return $out;

        } catch (\Throwable $e) {
            Log::warning('CegidService: lookup threw — treating as unknown', [
                'error' => $e->getMessage(),
                'count' => count($skus),
            ]);

            return array_fill_keys($skus, null);
        }
    }

    private function cacheKey(string $sku): string
    {
        return 'cegid:sku:' . md5($sku);
    }
}
