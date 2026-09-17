<?php

namespace App\Services;

use Google\Auth\Credentials\ServiceAccountCredentials;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;

/**
 * The transport half of the Google Analytics reporting: credentials, an
 * access token, and one batched request against a property.
 *
 * It knows nothing about stores or what the figures mean — Ga4AnalyticsService
 * decides that — in the same way ShopifyService carries the Shopify calls and
 * ShopifyAnalyticsService shapes them per store.
 *
 * Reports travel in batches because the API takes up to five at once and
 * charges the round trip, not the report: totals, devices, landing pages and
 * channels for one website cost a single call rather than four.
 */
class Ga4Client
{
    private const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

    /**
     * Google issues these for an hour. Ten minutes short of that leaves room
     * for a slow request to finish with a token that was valid when it began.
     */
    private const TOKEN_CACHE_MINUTES = 50;

    private const TOKEN_CACHE_KEY = 'ga4.access_token';

    public function __construct(private ?Client $http = null)
    {
        $this->http = $http ?? new Client([
            'timeout'     => (int) config('services.ga4.timeout', 30),
            'http_errors' => false,
        ]);
    }

    /** Whether a key is on disk at all. Answered before any request is made. */
    public function configured(): bool
    {
        $path = (string) config('services.ga4.credentials');

        return $path !== '' && is_readable($path);
    }

    /**
     * Run several reports against one property in a single call.
     *
     * @param  list<array<string, mixed>>  $requests  at most five
     * @return list<array<string, mixed>>  one report per request, in order
     *
     * @throws \RuntimeException on anything that is not a complete answer
     */
    public function batchReport(string $propertyId, array $requests): array
    {
        $response = $this->http->post(
            "https://analyticsdata.googleapis.com/v1beta/properties/{$propertyId}:batchRunReports",
            [
                'headers' => ['Authorization' => 'Bearer ' . $this->token()],
                'json'    => ['requests' => $requests],
            ],
        );

        $status = $response->getStatusCode();
        $body   = json_decode((string) $response->getBody(), true);

        if ($status !== 200) {
            // Carried through verbatim: a 403 here means either the service
            // account was never added to the property or the id belongs to
            // something else, and the caller tells those apart for the reader.
            throw new \RuntimeException(
                'GA4 report failed (HTTP ' . $status . '): ' . ($body['error']['message'] ?? 'no message'),
                $status,
            );
        }

        return $body['reports'] ?? [];
    }

    /**
     * A bearer token, kept between requests.
     *
     * Without this every website on the screen would pay for its own token
     * exchange before it could ask its first question.
     */
    private function token(): string
    {
        $path = (string) config('services.ga4.credentials');

        if (! $this->configured()) {
            throw new \RuntimeException("No Google Analytics credentials at {$path}.");
        }

        return Cache::remember(self::TOKEN_CACHE_KEY, now()->addMinutes(self::TOKEN_CACHE_MINUTES), function () use ($path) {
            $token = (new ServiceAccountCredentials(self::SCOPE, $path))->fetchAuthToken();

            if (empty($token['access_token'])) {
                throw new \RuntimeException('Google refused the service account key.');
            }

            return $token['access_token'];
        });
    }
}
