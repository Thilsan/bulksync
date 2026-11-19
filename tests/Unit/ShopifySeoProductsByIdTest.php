<?php

namespace Tests\Unit;

use App\Services\ShopifyService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use ReflectionClass;
use Tests\TestCase;

/**
 * getProductsForSeoAudit() must return products in exactly the shape the full
 * audit streams them in, or the re-check after a push would be grading
 * different data from the audit it is compared against.
 */
class ShopifySeoProductsByIdTest extends TestCase
{
    public function test_products_come_back_keyed_by_id_in_the_audit_shape(): void
    {
        $sent  = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['data' => ['nodes' => [
                [
                    'id' => 'gid://shopify/Product/42', 'title' => 'Linen Shirt', 'handle' => 'linen-shirt',
                    'status' => 'ACTIVE', 'tags' => ['shirts'], 'description' => 'Copy',
                    'seo' => ['title' => 'Meta', 'description' => 'Meta desc'],
                    'images' => ['edges' => [['node' => ['altText' => 'Blue shirt']], ['node' => ['altText' => null]]]],
                    'variants' => ['edges' => [['node' => ['sku' => 'SKU-42']]]],
                ],
                // A deleted product comes back as null and is left out.
                null,
            ]]])),
        ]));
        $stack->push(Middleware::history($sent));

        $service = (new ReflectionClass(ShopifyService::class))->newInstanceWithoutConstructor();
        $prop    = (new ReflectionClass(ShopifyService::class))->getProperty('http');
        $prop->setAccessible(true);
        $prop->setValue($service, new Client(['handler' => $stack]));

        $products = $service->getProductsForSeoAudit(['42', '43']);

        $this->assertCount(1, $products);
        $this->assertArrayHasKey('42', $products);
        $this->assertSame([
            'id' => '42', 'title' => 'Linen Shirt', 'handle' => 'linen-shirt', 'status' => 'active',
            'tags' => ['shirts'], 'description' => 'Copy', 'meta_title' => 'Meta', 'meta_description' => 'Meta desc',
            'images' => [['alt' => 'Blue shirt'], ['alt' => null]], 'sku' => 'SKU-42',
        ], $products['42']);

        $body = json_decode((string) $sent[0]['request']->getBody(), true);
        $this->assertStringContainsString('nodes(ids: $ids)', $body['query']);
        $this->assertSame(['gid://shopify/Product/42', 'gid://shopify/Product/43'], $body['variables']['ids']);
    }
}
