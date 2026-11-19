<?php

namespace Tests\Unit;

use App\Services\ShopifyService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

/**
 * A 429 from Shopify is a "wait", not a failure. Treated as final, it cost a
 * 499-SKU AI content push 75 products in one run.
 */
class ShopifyThrottleRetryTest extends TestCase
{
    private function client(MockHandler $mock, int $maxRetries = 5): Client
    {
        $stack = HandlerStack::create($mock);
        $stack->push(ShopifyService::retryWhenThrottled($maxRetries));

        return new Client(['handler' => $stack]);
    }

    public function test_a_throttled_call_is_retried_until_shopify_accepts_it(): void
    {
        $mock = new MockHandler([
            new Response(429, ['Retry-After' => '0']),
            new Response(200, [], '{"product":{"id":1}}'),
        ]);

        $response = $this->client($mock)->put('admin/api/2024-01/products/1.json');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $mock->count(), 'Both responses should have been used.');
    }

    public function test_it_gives_up_after_the_retry_limit(): void
    {
        $mock = new MockHandler([
            new Response(429, ['Retry-After' => '0']),
            new Response(429, ['Retry-After' => '0']),
        ]);

        $this->expectException(ClientException::class);

        $this->client($mock, maxRetries: 1)->put('admin/api/2024-01/products/1.json');
    }

    public function test_other_client_errors_are_not_retried(): void
    {
        $mock = new MockHandler([
            new Response(422, [], '{"errors":"bad"}'),
            new Response(200),
        ]);

        try {
            $this->client($mock)->put('admin/api/2024-01/products/1.json');
            $this->fail('A 422 should surface, not be retried.');
        } catch (ClientException $e) {
            $this->assertSame(422, $e->getResponse()->getStatusCode());
        }

        $this->assertSame(1, $mock->count());
    }
}
