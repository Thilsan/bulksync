<?php

namespace Tests\Unit;

use App\Services\ShopifyService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/**
 * A SKU check asks about the SKUs somebody pasted. These cover the batched live
 * lookup that answers exactly those, in place of warming the whole catalogue
 * into a cache and reading the answers back out of it — where an evicted entry
 * was indistinguishable from "no such SKU" and reported an existing product as
 * Not Available.
 */
class ShopifyBatchSkuLookupTest extends TestCase
{
    /** @var list<\Psr\Http\Message\RequestInterface> */
    private array $sent = [];

    /**
     * A service wired to canned responses. The constructor is skipped so no
     * store or token is needed — only the HTTP client matters here.
     *
     * @param  list<Response>  $responses
     */
    private function service(array $responses): ShopifyService
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->sent));

        $service = (new ReflectionClass(ShopifyService::class))->newInstanceWithoutConstructor();

        $http = new ReflectionClass(ShopifyService::class);
        $prop = $http->getProperty('http');
        $prop->setAccessible(true);
        $prop->setValue($service, new Client(['handler' => $stack]));

        return $service;
    }

    /** @param  list<array{sku: string, product: string, title?: string, status?: string}>  $variants */
    private function payload(array $variants): Response
    {
        $edges = array_map(fn ($v) => ['node' => [
            'id'      => 'gid://shopify/ProductVariant/' . crc32($v['sku']),
            'sku'     => $v['sku'],
            'product' => [
                'id'     => 'gid://shopify/Product/' . $v['product'],
                'title'  => $v['title']  ?? 'Product ' . $v['product'],
                'status' => $v['status'] ?? 'ACTIVE',
            ],
        ]], $variants);

        return new Response(200, [], json_encode(['data' => ['productVariants' => ['edges' => $edges]]]));
    }

    private function sentQuery(int $index): string
    {
        $body = json_decode((string) $this->sent[$index]['request']->getBody(), true);

        return $body['variables']['q'];
    }

    public function test_a_whole_batch_travels_in_one_call(): void
    {
        $service = $this->service([$this->payload([
            ['sku' => 'AAA', 'product' => '1'],
            ['sku' => 'BBB', 'product' => '2'],
        ])]);

        $found = $service->findVariantsBySkus(['AAA', 'BBB', 'CCC']);

        $this->assertCount(1, $this->sent, 'three SKUs should cost one call, not three');
        $this->assertSame("sku:'AAA' OR sku:'BBB' OR sku:'CCC'", $this->sentQuery(0));

        $this->assertSame('1', $found['AAA'][0]['product_id']);
        $this->assertSame('2', $found['BBB'][0]['product_id']);

        // Absent from the answer means absent from Shopify — the caller reports
        // Not Available off a missing key.
        $this->assertArrayNotHasKey('CCC', $found);
    }

    public function test_a_match_is_attributed_by_the_sku_shopify_returns(): void
    {
        // Shopify's search can return a variant nobody asked about. Attributing
        // by the returned sku keeps it from being reported under a SKU that does
        // not exist.
        $service = $this->service([$this->payload([
            ['sku' => 'WANTED',    'product' => '1'],
            ['sku' => 'NEIGHBOUR', 'product' => '9'],
        ])]);

        $found = $service->findVariantsBySkus(['WANTED', 'MISSING']);

        $this->assertArrayHasKey('WANTED', $found);
        $this->assertArrayNotHasKey('MISSING', $found);
        $this->assertArrayNotHasKey('NEIGHBOUR', $found);
    }

    public function test_case_is_ignored_when_matching_but_every_spelling_is_answered(): void
    {
        $service = $this->service([$this->payload([['sku' => 'abc-1', 'product' => '7']])]);

        $found = $service->findVariantsBySkus(['ABC-1', 'abc-1']);

        // One lookup key, but a list holding both spellings must not lose either.
        $this->assertSame('7', $found['ABC-1'][0]['product_id']);
        $this->assertSame('7', $found['abc-1'][0]['product_id']);
        $this->assertCount(1, $this->sent);
    }

    public function test_all_variants_of_one_sku_come_back(): void
    {
        $service = $this->service([$this->payload([
            ['sku' => 'MULTI', 'product' => '1'],
            ['sku' => 'MULTI', 'product' => '1'],
        ])]);

        $found = $service->findVariantsBySkus(['MULTI']);

        $this->assertCount(2, $found['MULTI']);
    }

    public function test_an_unpublished_product_is_reported_as_unpublished(): void
    {
        $service = $this->service([$this->payload([
            ['sku' => 'LIVE',  'product' => '1', 'status' => 'ACTIVE'],
            ['sku' => 'DRAFT', 'product' => '2', 'status' => 'DRAFT'],
        ])]);

        $found = $service->findVariantsBySkus(['LIVE', 'DRAFT']);

        $this->assertTrue($found['LIVE'][0]['published']);
        $this->assertFalse($found['DRAFT'][0]['published']);
    }

    public function test_a_long_list_is_split_into_batches(): void
    {
        $skus = array_map(fn ($n) => 'SKU-' . $n, range(1, 120));

        $service = $this->service([$this->payload([]), $this->payload([]), $this->payload([])]);

        $service->findVariantsBySkus($skus);

        // 50 per call, so 120 SKUs is three calls — not 120.
        $this->assertCount(3, $this->sent);
        $this->assertSame(50, substr_count($this->sentQuery(0), 'sku:'));
        $this->assertSame(20, substr_count($this->sentQuery(2), 'sku:'));
    }

    public function test_a_full_page_is_split_and_asked_again_rather_than_trusted(): void
    {
        // A page at the cap may have cut off variants belonging to later SKUs in
        // the batch, and a cut-off variant reads back as "not in Shopify".
        $capped = $this->payload(array_map(
            fn ($n) => ['sku' => 'CROWD', 'product' => (string) $n],
            range(1, 250)
        ));

        $service = $this->service([
            $capped,
            $this->payload([['sku' => 'FIRST', 'product' => '1']]),
            $this->payload([['sku' => 'SECOND', 'product' => '2']]),
        ]);

        $found = $service->findVariantsBySkus(['FIRST', 'SECOND']);

        $this->assertCount(3, $this->sent, 'the capped page should be halved and retried');
        $this->assertSame('1', $found['FIRST'][0]['product_id']);
        $this->assertSame('2', $found['SECOND'][0]['product_id']);
    }

    public function test_a_quote_in_a_sku_is_escaped_rather_than_ending_the_term(): void
    {
        $service = $this->service([$this->payload([])]);

        $service->findVariantsBySkus(["O'NEILL-1", 'BACK\\SLASH']);

        $query = $this->sentQuery(0);

        $this->assertStringContainsString("sku:'O\\'NEILL-1'", $query);
        $this->assertStringContainsString("sku:'BACK\\\\SLASH'", $query);
    }

    public function test_blank_and_duplicate_entries_are_dropped_before_asking(): void
    {
        $service = $this->service([$this->payload([])]);

        $service->findVariantsBySkus(['AAA', '  ', 'AAA', '']);

        $this->assertSame(1, substr_count($this->sentQuery(0), 'sku:'));
    }

    public function test_an_empty_list_asks_shopify_nothing(): void
    {
        $service = $this->service([]);

        $this->assertSame([], $service->findVariantsBySkus(['', '   ']));
        $this->assertCount(0, $this->sent);
    }

    public function test_a_throttled_reply_raises_instead_of_reading_as_no_matches(): void
    {
        // The whole point of throwOnFailure: an empty return here is
        // indistinguishable from "none of these exist", which would mark a
        // batch of real SKUs Not Available on a transport hiccup.
        $service = $this->service([new Response(200, [], json_encode([
            'data'   => null,
            'errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]],
        ]))]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Throttled/i');

        $service->findVariantsBySkus(['AAA'], true);
    }

    public function test_the_batch_query_asks_for_a_full_page_and_only_the_reported_fields(): void
    {
        $rc     = new ReflectionClass(ShopifyService::class);
        $method = $rc->getMethod('variantBatchQuery');
        $method->setAccessible(true);

        $query = $method->invoke($rc->newInstanceWithoutConstructor());

        $this->assertStringContainsString('first:250', $query);
        $this->assertSame(substr_count($query, '{'), substr_count($query, '}'));

        foreach (['id', 'sku', 'title', 'status'] as $needed) {
            $this->assertStringContainsString($needed, $query);
        }

        // The costly fields belong to the content generator, not a SKU check.
        $this->assertStringNotContainsString('collections', $query);
        $this->assertStringNotContainsString('descriptionHtml', $query);
    }
}
