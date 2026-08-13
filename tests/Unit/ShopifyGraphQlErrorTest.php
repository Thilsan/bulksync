<?php

namespace Tests\Unit;

use App\Services\ShopifyService;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/**
 * Shopify answers a throttled GraphQL call with HTTP 200 and an `errors` array,
 * so nothing in the transport layer raises. These cover the guard that turns
 * that payload into a retryable failure instead of a silent "no such SKU" —
 * the failure mode that had the image upload skipping products that existed.
 */
class ShopifyGraphQlErrorTest extends TestCase
{
    private function assertNoErrors(?array $data): void
    {
        $rc     = new ReflectionClass(ShopifyService::class);
        $method = $rc->getMethod('assertNoGraphQlErrors');
        $method->setAccessible(true);

        $method->invoke($rc->newInstanceWithoutConstructor(), $data, 'test');
    }

    private function lookupQuery(string $field, bool $lean): string
    {
        $rc     = new ReflectionClass(ShopifyService::class);
        $method = $rc->getMethod('variantLookupQuery');
        $method->setAccessible(true);

        return $method->invoke($rc->newInstanceWithoutConstructor(), $field, $lean);
    }

    public function test_a_successful_payload_passes(): void
    {
        $this->assertNoErrors(['data' => ['productVariants' => ['edges' => []]]]);

        $this->expectNotToPerformAssertions();
    }

    public function test_an_empty_errors_key_passes(): void
    {
        $this->assertNoErrors(['errors' => [], 'data' => ['productVariants' => ['edges' => []]]]);

        $this->expectNotToPerformAssertions();
    }

    public function test_a_throttled_payload_raises_instead_of_reading_as_no_match(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Throttled/i');

        // Shopify's real shape: HTTP 200, data null, the reason only in `errors`.
        $this->assertNoErrors([
            'data'   => null,
            'errors' => [[
                'message'    => 'Throttled',
                'extensions' => ['code' => 'THROTTLED'],
            ]],
        ]);
    }

    public function test_a_query_error_raises_with_shopifys_reason(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Field .* doesn\'t exist/');

        $this->assertNoErrors([
            'errors' => [['message' => "Field 'nope' doesn't exist on type 'Product'"]],
        ]);
    }

    public function test_the_lean_query_drops_the_costly_fields(): void
    {
        $lean = $this->lookupQuery('sku', true);

        $this->assertStringNotContainsString('collections', $lean);
        $this->assertStringNotContainsString('descriptionHtml', $lean);
        $this->assertStringContainsString('first:50', $lean);

        // Still returns everything the upload path reads off a match.
        foreach (['id', 'sku', 'title', 'status'] as $needed) {
            $this->assertStringContainsString($needed, $lean);
        }
    }

    public function test_the_full_query_keeps_the_content_generator_fields(): void
    {
        $full = $this->lookupQuery('sku', false);

        $this->assertStringContainsString('collections(first:20)', $full);
        $this->assertStringContainsString('descriptionHtml', $full);
        $this->assertStringContainsString('first:250', $full);
    }

    public function test_the_barcode_query_asks_for_the_barcode(): void
    {
        $this->assertStringContainsString('barcode', $this->lookupQuery('barcode', true));
        $this->assertStringNotContainsString('barcode', $this->lookupQuery('sku', true));
    }

    public function test_every_generated_query_is_brace_balanced(): void
    {
        foreach ([['sku', true], ['sku', false], ['barcode', true], ['barcode', false]] as [$field, $lean]) {
            $query = $this->lookupQuery($field, $lean);

            $this->assertSame(
                substr_count($query, '{'),
                substr_count($query, '}'),
                "unbalanced braces in {$field} query (lean: " . var_export($lean, true) . ')'
            );
        }
    }
}
