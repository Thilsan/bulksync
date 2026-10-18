<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The easybits SEO engine: three webhooks, one per screen or action.
 *
 * Everything clever happens on their side — Search Console, scoring, the AI
 * rewrites, and remembering which findings were resolved. This only sends the
 * JSON the integration guide describes and hands back what comes out.
 *
 * No authentication: the endpoints are open, and the guide is explicit that a
 * credentialed request is incompatible with them, so nothing is attached.
 */
class AiSeoService
{
    /** @return array<string,string> property => store name */
    public function properties(): array
    {
        return (array) config('services.ai_seo.properties', []);
    }

    public function isKnownProperty(?string $property): bool
    {
        return filled($property) && array_key_exists($property, $this->properties());
    }

    /**
     * The site's host for a property, so a URL can be checked against it.
     *
     * "sc-domain:goldgourmet.qa" covers the domain and every subdomain, which
     * is how Search Console itself reads it.
     */
    public function domainOf(string $property): ?string
    {
        return str_starts_with($property, 'sc-domain:')
            ? strtolower(substr($property, strlen('sc-domain:')))
            : (parse_url($property, PHP_URL_HOST) ?: null);
    }

    public function urlBelongsTo(string $url, string $property): bool
    {
        $host   = strtolower((string) parse_url($url, PHP_URL_HOST));
        $domain = $this->domainOf($property);

        return $host !== '' && $domain !== null
            && ($host === $domain || str_ends_with($host, '.' . $domain));
    }

    /** Site-wide list of the pages with the most upside. A few seconds. */
    public function quickWins(string $property): array
    {
        return $this->post('seo-quickwins', ['property' => $property]);
    }

    /** One page, audited, with AI rewrites. Roughly 10 to 25 seconds. */
    public function analyze(string $url, string $property): array
    {
        return $this->post('seo-optimizer', [
            'url'      => $url,
            'property' => $property,
        ], (int) config('services.ai_seo.analyze_timeout', 90));
    }

    /**
     * Record a finding as actioned. One-way: there is no un-resolve, and a
     * finding marked by mistake simply comes back flagged on the next scan.
     */
    public function markResolved(array $finding): array
    {
        return $this->post('seo-mark-resolved', [
            'findingId' => $finding['findingId'],
            'url'       => $finding['url'],
            'property'  => $finding['property'],
            'issue'     => $finding['issue'],
            'category'  => $finding['category'],
        ]);
    }

    /**
     * @throws AiSeoException with a message fit to show the person who asked
     */
    private function post(string $endpoint, array $body, ?int $timeout = null): array
    {
        $url = rtrim((string) config('services.ai_seo.base_url'), '/') . '/' . $endpoint;

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout($timeout ?? (int) config('services.ai_seo.timeout', 20))
                ->post($url, $body);
        } catch (ConnectionException $e) {
            throw new AiSeoException(
                'The SEO engine did not answer in time. It is safe to try again.',
                previous: $e,
            );
        }

        if (!$response->successful()) {
            throw new AiSeoException($this->errorFrom($response));
        }

        /*
         * A 200 with nothing in it. Seen on the live engine for every endpoint
         * and both stores, answering in under a second — the workflow behind
         * the webhook accepted the call and produced no output. It is not the
         * guide's "no data yet" case, which is a JSON body with hasData false.
         */
        if (trim($response->body()) === '') {
            throw new AiSeoException(
                'The SEO engine answered but sent back nothing. This is on the easybits side, not this app — '
                . 'if it keeps happening, let easybits know.'
            );
        }

        $json = $response->json();

        if (!is_array($json)) {
            throw new AiSeoException('The SEO engine sent back something that was not JSON. It is safe to try again.');
        }

        return $json;
    }

    /** Errors carry a JSON body where possible; use its message when it has one. */
    private function errorFrom(Response $response): string
    {
        $detail = $response->json('message') ?? $response->json('error');

        return 'The SEO engine returned an error (' . $response->status() . ')'
            . (is_string($detail) && $detail !== '' ? ': ' . $detail : '')
            . '. It is safe to try again.';
    }
}
