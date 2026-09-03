<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads the cross-platform orders endpoint on the ecommerce server.
 *
 * That endpoint pre-aggregates everything server-side, so one call answers a
 * whole screen — totals, six breakdowns and a time series — for any date range
 * in about a second. There is nothing to paginate and nothing to cache.
 *
 * The token is the reason this class exists rather than the browser calling
 * the endpoint directly. It is a single shared secret with no per-user
 * identity and no rate limiting, and it unlocks revenue for every storefront
 * the company runs, so it stays in this server's environment and is sent from
 * here. CORS on the endpoint is wide open, which makes shipping the token to
 * the browser easy and wrong.
 */
class OrdersSummaryService
{
    /** What each date_basis actually filters on, in the words the business uses. */
    public const BASES = [
        'created'   => 'Order date',
        'delivered' => 'Delivery date',
        'updated'   => 'Last updated',
    ];

    /**
     * The endpoint answers success as 100, not 200 — a legacy convention it
     * shares with its sibling scripts. HTTP status and this disagree on
     * purpose, so the body is what decides.
     */
    private const OK = 100;

    /**
     * One range.
     *
     * @param  array{from:string,to:string,date_basis:string,platform?:string}  $params
     * @return array{ok:bool,status:int,message:string,data:?array}
     */
    public function fetch(array $params): array
    {
        try {
            return $this->interpret($this->configure(Http::createPendingRequest())->get($this->url(), $params));
        } catch (ConnectionException $e) {
            return $this->unreachable($e);
        }
    }

    /**
     * A range and the one immediately before it, in parallel.
     *
     * Every KPI is shown against the preceding period, which is a second call
     * with shifted dates. Run in sequence it doubles the page's wait for a
     * number nobody reads first, so both go out at once and the page waits for
     * the slower of the two rather than the sum.
     *
     * @return array{current:array,previous:array}
     */
    public function fetchPair(array $current, array $previous): array
    {
        try {
            $responses = Http::pool(fn (Pool $pool) => [
                $this->configure($pool->as('current'))->get($this->url(), $current),
                $this->configure($pool->as('previous'))->get($this->url(), $previous),
            ]);
        } catch (ConnectionException $e) {
            $failed = $this->unreachable($e);

            return ['current' => $failed, 'previous' => $failed];
        }

        return [
            // A pooled request hands back the exception itself rather than
            // throwing, so a host that is down arrives here as a value.
            'current'  => $this->interpret($responses['current'] ?? null),
            'previous' => $this->interpret($responses['previous'] ?? null),
        ];
    }

    /** Is the endpoint configured at all? Answered before any request is made. */
    public function configured(): bool
    {
        return (bool) config('services.orders_api.token') && (bool) config('services.orders_api.url');
    }

    private function url(): string
    {
        return (string) config('services.orders_api.url');
    }

    /**
     * The token travels as a header rather than in the query string, so it
     * stays out of access logs, proxy logs and anything that records URLs.
     */
    private function configure(PendingRequest $request): PendingRequest
    {
        return $request
            ->withHeaders(['X-Api-Token' => (string) config('services.orders_api.token')])
            ->timeout((int) config('services.orders_api.timeout', 20))
            ->acceptJson();
    }

    /**
     * Turn whatever came back into the one shape the page renders.
     *
     * Three things can arrive and only one of them is a dashboard:
     *
     *   - a success envelope, status_code 100, with data
     *   - an error envelope, which carries a message written for a human and
     *     is shown verbatim rather than replaced with "something went wrong"
     *   - no envelope at all
     *
     * The last one is not hypothetical. Ranges reaching back past 2023 answer
     * HTTP 200 with an empty body — the endpoint's json_encode gives up on a
     * handful of old rows and echoes nothing — so an unparseable body is
     * treated as the failure it is instead of rendering as a blank screen.
     */
    private function interpret(mixed $response): array
    {
        if ($response instanceof \Throwable) {
            return $this->unreachable($response);
        }

        if (! $response instanceof Response) {
            return $this->failure(0, 'The orders service did not respond.');
        }

        $body = $response->json();

        if (! \is_array($body) || ! isset($body['status_code'])) {
            Log::warning('Orders summary returned an unreadable body', [
                'http'  => $response->status(),
                'bytes' => \strlen($response->body()),
            ]);

            return $this->failure(
                $response->status(),
                'The orders service answered with nothing readable. This happens on ranges reaching back before 2024 — narrow the dates and try again.'
            );
        }

        $status  = (int) $body['status_code'];
        $message = (string) ($body['message'] ?? '');

        if ($status !== self::OK) {
            return $this->failure($status, $message ?: 'The orders service rejected the request.');
        }

        return [
            'ok'      => true,
            'status'  => $status,
            'message' => $message,
            'data'    => \is_array($body['data'] ?? null) ? $body['data'] : null,
        ];
    }

    private function unreachable(\Throwable $e): array
    {
        Log::warning('Orders summary unreachable', ['error' => $e->getMessage()]);

        return $this->failure(0, 'Could not reach the orders service. It may be down, or this server cannot see it.');
    }

    private function failure(int $status, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $message, 'data' => null];
    }
}
