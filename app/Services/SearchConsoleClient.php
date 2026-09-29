<?php

namespace App\Services;

use Google\Auth\Credentials\ServiceAccountCredentials;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;

/**
 * The transport half of Google Search Console: credentials, an access token,
 * and one search-analytics query against a verified site.
 *
 * Kept apart from Ga4Client because the two answer different questions.
 * Analytics reports what happened after somebody arrived; Search Console
 * reports the search itself — what was typed, how often it was shown, and
 * whether anyone clicked. For judging a rewritten meta description that
 * distinction is the whole point: a better description moves the click-through
 * rate first, and sessions cannot tell that apart from a ranking change.
 *
 * The same service-account key serves both, but it has to be granted access
 * separately on each side — Analytics by property, Search Console by site.
 */
class SearchConsoleClient
{
    private const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    /**
     * Google issues these for an hour. Ten minutes short of that leaves room
     * for a slow request to finish with a token that was valid when it began.
     */
    private const TOKEN_CACHE_MINUTES = 50;

    private const TOKEN_CACHE_KEY = 'search_console.access_token';

    public function __construct(private ?Client $http = null)
    {
        $this->http = $http ?? new Client([
            'timeout'     => (int) config('services.search_console.timeout', 30),
            'http_errors' => false,
        ]);
    }

    /** Whether a key is on disk at all. Answered before any request is made. */
    public function configured(): bool
    {
        $path = (string) config('services.search_console.credentials');

        return $path !== '' && is_readable($path);
    }

    /**
     * One search-analytics query.
     *
     * @param  string  $siteUrl  as Search Console holds it — "sc-domain:example.com"
     *                           for a domain property, or the full URL with its
     *                           trailing slash for a URL-prefix one
     * @return list<array<string, mixed>>  the rows, or none
     *
     * @throws \RuntimeException on anything that is not a complete answer
     */
    public function query(string $siteUrl, array $request): array
    {
        $response = $this->http->post(
            'https://searchconsole.googleapis.com/webmasters/v3/sites/'
                . rawurlencode($siteUrl) . '/searchAnalytics/query',
            [
                'headers' => ['Authorization' => 'Bearer ' . $this->token()],
                'json'    => $request,
            ],
        );

        $status = $response->getStatusCode();
        $body   = json_decode((string) $response->getBody(), true);

        if ($status !== 200) {
            // Carried through verbatim: a 403 here almost always means the
            // service account was never added as a user on the property, and
            // the caller tells that apart for the reader.
            throw new \RuntimeException(
                'Search Console query failed (HTTP ' . $status . '): '
                    . ($body['error']['message'] ?? 'no message'),
                $status,
            );
        }

        return $body['rows'] ?? [];
    }

    /**
     * A bearer token, kept between requests.
     *
     * Without this every website on the screen would pay for its own token
     * exchange before it could ask its first question.
     */
    private function token(): string
    {
        $path = (string) config('services.search_console.credentials');

        if (! $this->configured()) {
            throw new \RuntimeException("No Search Console credentials at {$path}.");
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
