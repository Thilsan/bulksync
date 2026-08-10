<?php

namespace Tests\Unit;

use App\Exceptions\ShopifyRequestException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ShopifyRequestExceptionTest extends TestCase
{
    public function test_a_rejected_request_is_permanent_and_not_worth_retrying(): void
    {
        // The exact rejection that failed the style-code uploads.
        $e = $this->fromStatus(422, '{"errors":{"image":["The pixel limit is 20 megapixels."]}}');

        $this->assertTrue($e->isPermanent());
    }

    public function test_a_missing_product_is_permanent(): void
    {
        $this->assertTrue($this->fromStatus(404, '{"errors":"Not Found"}')->isPermanent());
    }

    public function test_rate_limiting_and_timeouts_stay_retryable(): void
    {
        $this->assertFalse($this->fromStatus(429, '')->isPermanent());
        $this->assertFalse($this->fromStatus(408, '')->isPermanent());
    }

    public function test_it_keeps_the_message_and_status_it_was_given(): void
    {
        $e = $this->fromStatus(422, '');

        $this->assertSame('Shopify image upload failed: HTTP 422', $e->getMessage());
        $this->assertSame(422, $e->status());
        $this->assertInstanceOf(ClientException::class, $e->getPrevious());
    }

    private function fromStatus(int $status, string $body): ShopifyRequestException
    {
        $clientException = new ClientException(
            'Client error',
            new Request('POST', 'https://example.myshopify.com/images.json'),
            new Response($status, [], $body),
        );

        return ShopifyRequestException::fromClientException(
            $clientException,
            'Shopify image upload failed: HTTP ' . $status,
        );
    }
}
