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
 * What overwriteProduct() sends decides what a live product loses: Shopify
 * deletes any variant or image the update leaves out, and overwrites any
 * field it names.
 */
class ShopifyOverwriteProductTest extends TestCase
{
    /** @var list<\Psr\Http\Message\RequestInterface> */
    private array $sent = [];

    private function service(array $responses): ShopifyService
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sent));

        $service = (new ReflectionClass(ShopifyService::class))->newInstanceWithoutConstructor();
        $prop    = (new ReflectionClass(ShopifyService::class))->getProperty('http');
        $prop->setAccessible(true);
        $prop->setValue($service, new Client(['handler' => $stack]));

        return $service;
    }

    public function test_the_update_mirrors_the_source_without_touching_stock_or_status(): void
    {
        $service = $this->service([new Response(200, [], json_encode(['product' => ['id' => 20, 'images' => [], 'variants' => []]]))]);

        $service->overwriteProduct('20', [
            'title'    => 'Giantto Watch',
            'body_html'=> '<p>Copy</p>',
            'status'   => 'draft',
            'options'  => [['name' => 'Colour', 'values' => ['Grey', 'Black'], 'id' => 5]],
            'variants' => [
                ['id' => 101, 'sku' => 'GREY', 'price' => '10.00', 'inventory_quantity' => 7, 'inventory_management' => 'shopify', 'option1' => 'Grey'],
                ['id' => 102, 'sku' => 'BLACK', 'price' => '12.00', 'inventory_quantity' => 3, 'inventory_management' => 'shopify', 'option1' => 'Black'],
            ],
            'images'   => [['id' => 1, 'src' => 'https://cdn.test/a.jpg', 'alt' => 'Grey watch']],
        ], ['GREY' => '201'], ['title' => 'Giantto Watch | Qatar', 'description' => null]);

        $request = $this->sent[0]['request'];
        $product = json_decode((string) $request->getBody(), true)['product'];

        $this->assertSame('PUT', $request->getMethod());
        $this->assertStringEndsWith('/products/20.json', $request->getUri()->getPath());

        // Matched variant updated in place; new one created; stock never sent.
        $this->assertSame(201, $product['variants'][0]['id']);
        $this->assertArrayNotHasKey('inventory_management', $product['variants'][0]);
        $this->assertArrayNotHasKey('id', $product['variants'][1]);
        $this->assertSame('shopify', $product['variants'][1]['inventory_management']);
        $this->assertArrayNotHasKey('inventory_quantity', $product['variants'][0]);
        $this->assertArrayNotHasKey('inventory_quantity', $product['variants'][1]);

        $this->assertArrayNotHasKey('status', $product);
        $this->assertSame([['name' => 'Colour', 'values' => ['Grey', 'Black']]], $product['options']);
        $this->assertSame([['src' => 'https://cdn.test/a.jpg', 'alt' => 'Grey watch']], $product['images']);

        // An empty source SEO field leaves the target's own in place.
        $this->assertSame('Giantto Watch | Qatar', $product['metafields_global_title_tag']);
        $this->assertArrayNotHasKey('metafields_global_description_tag', $product);
    }
}
