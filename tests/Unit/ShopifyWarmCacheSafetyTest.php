<?php

namespace Tests\Unit;

use App\Services\ShopifyService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use ReflectionClass;
use Tests\TestCase;

/**
 * The warm cache is allowed to be slow or absent. It is not allowed to be wrong.
 *
 * Production ran a 416k-variant store into a 196 MB Redis with an allkeys
 * eviction policy: the keys evicted each other while the sentinel still claimed
 * a complete generation, so a lookup missed and was read as "this SKU is not in
 * Shopify" — reporting real products as missing and silently skipping their
 * image uploads. 13.5 million evictions, and the cache holding four keys.
 *
 * These cover the two guards that make that impossible: a miss now asks Shopify,
 * and a catalogue too big to fit is never warmed in the first place.
 */
class ShopifyWarmCacheSafetyTest extends TestCase
{
    /** @var list<\Psr\Http\Message\RequestInterface> */
    private array $sent = [];

    /** @param  list<Response>  $responses */
    private function service(array $responses, string $shop = 'test-shop.myshopify.com'): ShopifyService
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sent));

        $rc      = new ReflectionClass(ShopifyService::class);
        $service = $rc->newInstanceWithoutConstructor();

        foreach (['http' => new Client(['handler' => $stack]), 'shop' => $shop, 'token' => 'tok'] as $name => $value) {
            $prop = $rc->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($service, $value);
        }

        return $service;
    }

    private function sentinelKey(ShopifyService $service): string
    {
        $rc     = new ReflectionClass(ShopifyService::class);
        $method = $rc->getMethod('skuWarmSentinel');
        $method->setAccessible(true);

        return $method->invoke($service);
    }

    private function variantPayload(string $sku, string $productId): Response
    {
        return new Response(200, [], json_encode(['data' => ['productVariants' => ['edges' => [
            ['node' => [
                'id'      => 'gid://shopify/ProductVariant/1',
                'sku'     => $sku,
                'product' => ['id' => 'gid://shopify/Product/' . $productId, 'title' => 'A Thing', 'status' => 'ACTIVE'],
            ]],
        ]]]]));
    }

    public function test_an_evicted_key_is_confirmed_against_shopify_not_read_as_absent(): void
    {
        $service = $this->service([$this->variantPayload('EVICTED-1', '55')]);

        // The exact production state: sentinel intact, the SKU's own key gone.
        Cache::put($this->sentinelKey($service), 1234567890, 60);

        $variants = $service->findVariantsBySkuCached('EVICTED-1');

        $this->assertCount(1, $variants, 'an evicted key must not read as "not in Shopify"');
        $this->assertSame('55', $variants[0]['product_id']);
        $this->assertCount(1, $this->sent, 'the miss should have gone to Shopify');
    }

    public function test_a_cached_hit_still_answers_without_calling_shopify(): void
    {
        $service = $this->service([]);

        Cache::put($this->sentinelKey($service), 999, 60);

        $rc  = new ReflectionClass(ShopifyService::class);
        $key = $rc->getMethod('skuEntryKey');
        $key->setAccessible(true);

        Cache::put($key->invoke($service, 'CACHED-1', 999), [['product_id' => '7']], 60);

        $this->assertSame('7', $service->findVariantsBySkuCached('CACHED-1')[0]['product_id']);
        $this->assertCount(0, $this->sent, 'a hit must still be served from cache');
    }

    public function test_a_cached_empty_answer_is_still_trusted(): void
    {
        // An empty array is a recorded answer, unlike a missing key: the warm
        // saw this SKU and found nothing. No call needed.
        $service = $this->service([]);

        Cache::put($this->sentinelKey($service), 999, 60);

        $rc  = new ReflectionClass(ShopifyService::class);
        $key = $rc->getMethod('skuEntryKey');
        $key->setAccessible(true);
        Cache::put($key->invoke($service, 'KNOWN-EMPTY', 999), [], 60);

        $this->assertSame([], $service->findVariantsBySkuCached('KNOWN-EMPTY'));
        $this->assertCount(0, $this->sent);
    }

    public function test_an_evicted_barcode_key_is_confirmed_too(): void
    {
        $service = $this->service([new Response(200, [], json_encode(['data' => ['productVariants' => ['edges' => [
            ['node' => [
                'id'      => 'gid://shopify/ProductVariant/2',
                'sku'     => 'BY-BARCODE',
                'barcode' => '5000000000',
                'product' => ['id' => 'gid://shopify/Product/88', 'title' => 'A Thing', 'status' => 'ACTIVE'],
            ]],
        ]]]]))]);

        Cache::put($this->sentinelKey($service), 1234567890, 60);

        $this->assertCount(1, $service->findVariantsByBarcodeCached('5000000000'));
        $this->assertCount(1, $this->sent);
    }

    public function test_a_catalogue_over_the_cap_is_not_warmed_at_all(): void
    {
        config(['services.shopify.warm_max_variants' => 1000]);

        // One cheap count call settles it: products can only be fewer than
        // variants, so 5000 products is already past a 1000-variant cap.
        $service = $this->service([new Response(200, [], json_encode(['count' => 5000]))]);

        $this->assertSame(0, $service->warmSkuCache());

        $this->assertCount(1, $this->sent, 'it should stop after the count, not read any pages');
        $this->assertNull(
            Cache::get($this->sentinelKey($service)),
            'no sentinel means lookups go live, which is the safe answer'
        );
    }

    public function test_a_variant_heavy_catalogue_is_abandoned_mid_warm_without_a_sentinel(): void
    {
        config(['services.shopify.warm_max_variants' => 3]);

        // Few products, many variants each — under the pre-flight, over the cap.
        $product = [
            'id'           => 1,
            'title'        => 'Many Variants',
            'published_at' => '2026-01-01',
            'variants'     => array_map(fn ($n) => ['id' => $n, 'sku' => 'SKU-' . $n], range(1, 10)),
        ];

        $service = $this->service([
            new Response(200, [], json_encode(['count' => 1])),
            new Response(200, [], json_encode(['products' => [$product]])),
        ]);

        $this->assertSame(10, $service->warmSkuCache());

        $this->assertNull(
            Cache::get($this->sentinelKey($service)),
            'a half-filled cache must never be published as a complete generation'
        );
    }

    public function test_a_catalogue_within_the_cap_warms_normally(): void
    {
        config(['services.shopify.warm_max_variants' => 1000]);

        $service = $this->service([
            new Response(200, [], json_encode(['count' => 1])),
            new Response(200, [], json_encode(['products' => [[
                'id'           => 1,
                'title'        => 'A Thing',
                'published_at' => '2026-01-01',
                'variants'     => [['id' => 10, 'sku' => 'FITS-1', 'barcode' => '123']],
            ]]])),
        ]);

        $this->assertSame(1, $service->warmSkuCache());

        $this->assertNotNull(Cache::get($this->sentinelKey($service)), 'a catalogue that fits should still be warmed');
        $this->assertSame('FITS-1', $service->findVariantsBySkuCached('FITS-1')[0]['variant_sku']);
    }

    public function test_the_cap_can_be_switched_off(): void
    {
        config(['services.shopify.warm_max_variants' => 0]);

        $service = $this->service([
            new Response(200, [], json_encode(['products' => [[
                'id'           => 1,
                'title'        => 'A Thing',
                'published_at' => '2026-01-01',
                'variants'     => [['id' => 10, 'sku' => 'NOCAP-1']],
            ]]])),
        ]);

        $this->assertSame(1, $service->warmSkuCache());

        // No pre-flight count call when the cap is off.
        $this->assertCount(1, $this->sent);
        $this->assertNotNull(Cache::get($this->sentinelKey($service)));
    }
}
