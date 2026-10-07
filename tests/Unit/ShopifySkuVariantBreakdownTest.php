<?php

namespace Tests\Unit;

use App\Services\ShopifyService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use ReflectionClass;
use Tests\TestCase;

/**
 * The colour/size breakdown behind one SKU.
 *
 * "Has an image" means the variant owns a photo — the legacy image link or the
 * newer variant media attachment, either one — not that its product's gallery
 * holds one. A gallery full of pictures none of which is attached to the blue
 * variant is exactly the case this screen exists to surface.
 */
class ShopifySkuVariantBreakdownTest extends TestCase
{
    private function service(Response ...$responses): ShopifyService
    {
        $service = (new ReflectionClass(ShopifyService::class))->newInstanceWithoutConstructor();

        $prop = (new ReflectionClass(ShopifyService::class))->getProperty('http');
        $prop->setAccessible(true);
        $prop->setValue($service, new Client([
            'handler' => HandlerStack::create(new MockHandler($responses)),
        ]));

        return $service;
    }

    /** @param  list<array{sku: string, colour: string, size: string, image?: string, media?: list<string>, stock?: int}>  $variants */
    private function payload(array $variants, int $galleryCount = 3, string $matchSku = ''): Response
    {
        $edges = array_map(fn ($v) => ['node' => [
            'id'                => 'gid://shopify/ProductVariant/' . crc32($v['sku']),
            'sku'               => $v['sku'],
            'title'             => $v['colour'] . ' / ' . $v['size'],
            'inventoryQuantity' => $v['stock'] ?? 0,
            'price'             => $v['price'] ?? null,
            'compareAtPrice'    => $v['compare'] ?? null,
            'selectedOptions'   => [
                ['name' => 'Color', 'value' => $v['colour']],
                ['name' => 'Size',  'value' => $v['size']],
            ],
            'image' => isset($v['image']) ? ['url' => $v['image']] : null,
            'media' => ['edges' => array_map(
                fn ($url) => ['node' => ['image' => ['url' => $url]]],
                $v['media'] ?? []
            )],
        ]], $variants);

        $product = [
            'id'       => 'gid://shopify/Product/99',
            'title'    => 'A Dress',
            'status'   => 'ACTIVE',
            'options'  => [['name' => 'Color'], ['name' => 'Size']],
            'media'    => ['edges' => array_fill(0, $galleryCount, ['node' => ['id' => 'gid://shopify/MediaImage/1']])],
            'variants' => ['edges' => $edges],
        ];

        $matched = $matchSku !== '' ? $matchSku : $variants[0]['sku'];

        return new Response(200, [], json_encode(['data' => ['shop' => ['currencyCode' => 'QAR'], 'productVariants' => ['edges' => [
            ['node' => ['id' => 'gid://shopify/ProductVariant/' . crc32($matched), 'sku' => $matched, 'product' => $product]],
        ]]]]));
    }

    /** The price people check sits beside each size, in the shop's currency. */
    public function test_each_size_carries_its_price_and_the_shop_currency(): void
    {
        $breakdown = $this->service($this->payload([
            ['sku' => 'P-M', 'colour' => 'Red', 'size' => 'M', 'price' => '450.00', 'compare' => '600.00'],
            ['sku' => 'P-L', 'colour' => 'Red', 'size' => 'L', 'price' => '450.00'],
        ]))->getSkuVariantBreakdown('P-M');

        $this->assertSame('QAR', $breakdown['currency']);
        $this->assertSame('450.00', $breakdown['colours'][0]['sizes'][0]['price']);
        $this->assertSame('600.00', $breakdown['colours'][0]['sizes'][0]['compare_at_price']);
        $this->assertNull($breakdown['colours'][0]['sizes'][1]['compare_at_price']);
    }

    public function test_variants_are_grouped_by_colour_with_the_sizes_under_each(): void
    {
        $service = $this->service($this->payload([
            ['sku' => 'RED-M', 'colour' => 'Red',  'size' => 'M', 'image' => 'red.jpg'],
            ['sku' => 'RED-L', 'colour' => 'Red',  'size' => 'L', 'media' => ['red-2.jpg', 'red-3.jpg']],
            ['sku' => 'BLU-M', 'colour' => 'Blue', 'size' => 'M'],
        ]));

        $breakdown = $service->getSkuVariantBreakdown('RED-M');

        $this->assertSame('A Dress', $breakdown['product_title']);
        $this->assertSame('99', $breakdown['product_id']);
        $this->assertSame(3, $breakdown['variant_count']);
        $this->assertSame(2, $breakdown['with_image_count']);
        $this->assertSame(3, $breakdown['gallery_count']);

        $this->assertSame(['Red', 'Blue'], array_column($breakdown['colours'], 'colour'));

        [$red, $blue] = $breakdown['colours'];

        $this->assertSame(['M', 'L'], array_column($red['sizes'], 'size'));
        $this->assertSame(2, $red['with_image_count']);
        $this->assertSame('red.jpg', $red['preview']);

        // The size the user searched for is marked, so a row opened from a long
        // list still says which variant the SKU itself was.
        $this->assertTrue($red['sizes'][0]['is_match']);
        $this->assertFalse($red['sizes'][1]['is_match']);

        // Two media images on one variant count as two photos, not two variants.
        $this->assertSame(2, $red['sizes'][1]['image_count']);


        // A gallery of three pictures does not make the blue variant covered.
        $this->assertSame(0, $blue['with_image_count']);
        $this->assertNull($blue['preview']);
        $this->assertFalse($blue['sizes'][0]['has_image']);
    }

    public function test_the_asked_for_spelling_wins_when_the_search_returns_several(): void
    {
        $response = new Response(200, [], json_encode(['data' => ['productVariants' => ['edges' => [
            ['node' => ['id' => 'gid://shopify/ProductVariant/1', 'sku' => 'OTHER', 'product' => [
                'id' => 'gid://shopify/Product/1', 'title' => 'Wrong Product', 'status' => 'ACTIVE',
                'options' => [['name' => 'Color']], 'media' => ['edges' => []], 'variants' => ['edges' => []],
            ]]],
            ['node' => ['id' => 'gid://shopify/ProductVariant/2', 'sku' => 'Red-M', 'product' => [
                'id' => 'gid://shopify/Product/2', 'title' => 'Right Product', 'status' => 'ACTIVE',
                'options' => [['name' => 'Color']], 'media' => ['edges' => []], 'variants' => ['edges' => []],
            ]]],
        ]]]]));

        $breakdown = $this->service($response)->getSkuVariantBreakdown('red-m');

        $this->assertSame('Right Product', $breakdown['product_title']);
    }

    public function test_the_query_stays_inside_shopifys_cost_ceiling(): void
    {
        // Shopify refuses a query costing more than 1000 points, and refuses
        // all of it — so a stock field nested under the variant list takes the
        // colours down with it. Per-location availability brought this query to
        // 1306 and every row came back Lookup Failed until it came out. Stock
        // is the variant's own inventoryQuantity, a scalar Shopify does not
        // charge for, and never the inventoryLevels connection.
        $query = (new ReflectionClass(ShopifyService::class))->getMethod('skuBreakdownQuery');
        $query->setAccessible(true);

        $service = (new ReflectionClass(ShopifyService::class))->newInstanceWithoutConstructor();

        $this->assertStringNotContainsString('inventoryLevels', $query->invoke($service, true));
        $this->assertStringNotContainsString('inventoryItem', $query->invoke($service, true));
        $this->assertStringContainsString('inventoryQuantity', $query->invoke($service, true));
        $this->assertStringNotContainsString('inventoryQuantity', $query->invoke($service, false));
    }

    public function test_stock_is_reported_per_size_per_colour_and_for_the_product(): void
    {
        $service = $this->service($this->payload([
            ['sku' => 'RED-M', 'colour' => 'Red',  'size' => 'M', 'stock' => 4],
            ['sku' => 'RED-L', 'colour' => 'Red',  'size' => 'L', 'stock' => 0],
            ['sku' => 'BLU-M', 'colour' => 'Blue', 'size' => 'M', 'stock' => 7],
        ]));

        $breakdown = $service->getSkuVariantBreakdown('RED-M');

        [$red, $blue] = $breakdown['colours'];

        $this->assertSame([4, 0], array_column($red['sizes'], 'stock'));
        $this->assertSame(4, $red['stock']);
        $this->assertSame(7, $blue['stock']);
        $this->assertSame(11, $breakdown['stock']);
    }

    public function test_a_refused_stock_field_still_returns_the_colours_with_stock_unknown(): void
    {
        $refused = new Response(200, [], json_encode(['errors' => [[
            'message' => 'Access denied for inventoryQuantity field. Required access: `read_inventory` access scope.',
        ]]]));

        $service = $this->service($refused, $this->payload([
            ['sku' => 'RED-M', 'colour' => 'Red', 'size' => 'M', 'image' => 'red.jpg'],
        ]));

        $breakdown = $service->getSkuVariantBreakdown('RED-M', true);

        // Unknown, not zero: 0 would read as "out of stock".
        $this->assertNull($breakdown['stock']);
        $this->assertNull($breakdown['colours'][0]['stock']);
        $this->assertNull($breakdown['colours'][0]['sizes'][0]['stock']);
        $this->assertSame(1, $breakdown['with_image_count']);
    }

    public function test_a_sku_no_variant_carries_comes_back_as_nothing(): void
    {
        $response = new Response(200, [], json_encode(['data' => ['productVariants' => ['edges' => []]]]));

        $this->assertNull($this->service($response)->getSkuVariantBreakdown('GONE'));
    }

    public function test_a_failed_lookup_can_be_made_to_throw_rather_than_read_as_no_colours(): void
    {
        $response = new Response(200, [], json_encode(['errors' => [['message' => 'Throttled']]]));

        $this->expectExceptionMessage('Shopify variant breakdown failed for AAA');

        $this->service($response)->getSkuVariantBreakdown('AAA', true);
    }
}
