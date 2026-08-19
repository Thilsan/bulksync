<?php

namespace Tests\Feature;

use App\Models\ProductRequest;
use App\Models\ProductRequestDraftProduct;
use App\Models\ProductRequestDraftVariant;
use App\Models\ProductRequestSku;
use App\Models\Store;
use App\Models\User;
use App\Services\OneDriveService;
use App\Services\ProductRequestDraftBuilder;
use App\Services\ProductRequestDraftCsv;
use App\Services\ProductRequestDraftPusher;
use App\Services\ProductRequestWorkflow;
use App\Services\ShopifyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Sheet → reviewable draft → Shopify draft product.
 *
 * SharePoint is stubbed at the OneDriveService boundary and Shopify at the
 * pusher's own seam, so nothing here reaches either service.
 */
class ProductRequestDraftTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'name'                 => 'Ahamed',
            'email'                => config('product_request_sync.sync_user_email'),
            'password'             => 'password',
            'is_active'            => true,
            'is_super_admin'       => true,
            'perm_product_request' => true,
        ]);
    }

    private function store(bool $cegid = false): Store
    {
        return Store::create([
            'name'                 => 'Other Website',
            'shopify_domain'       => 'other.myshopify.com',
            'is_active'            => true,
            'requires_sku_mapping' => $cegid,
        ]);
    }

    /** A request whose SKUs came back from the SKU check as not-in-Shopify. */
    private function request(Store $store, User $user, array $skus, array $alreadyInShopify = []): ProductRequest
    {
        $request = ProductRequest::create([
            'reference'    => ProductRequest::nextReference(),
            'user_id'      => $user->id,
            'store_id'     => $store->id,
            'request_type' => 'new_brand',
            'brand'        => 'ZIMMERLI',
            'category'     => "Men's Fashion",
            'status'       => ProductRequest::SKU_VERIFIED,
            'priority'     => 'medium',
            'total_skus'   => count($skus),
        ]);

        foreach ($skus as $sku) {
            ProductRequestSku::create([
                'product_request_id' => $request->id,
                'sku'                => $sku,
                'mapping_status'     => ProductRequest::MAP_PENDING,
                'in_shopify'         => in_array($sku, $alreadyInShopify, true),
            ]);
        }

        return $request;
    }

    /**
     * A category tab: two SKUs sharing a style code, one on its own, and a row
     * for a SKU this request never asked about.
     */
    private function fakeSheet(): void
    {
        $drive = Mockery::mock(OneDriveService::class);
        $drive->shouldReceive('setUser')->andReturnSelf();
        $drive->shouldReceive('resolveShareItem')->andReturn(['driveId' => 'd', 'itemId' => 'i']);
        $drive->shouldReceive('worksheetValues')
            ->with('d', 'i', 'Mens Fashion')
            ->andReturn([
                ['Item SKU', 'Style Code', 'Brand Name', 'Product Name', 'Description', 'Colour', 'Size', 'Retail Price', 'Barcode', 'Image URL'],
                ['ZIM-1-W-38', 'ZIM-252', 'ZIMMERLI', 'Cotton Shirt', '<p>Soft</p>', 'White', '38', 'QAR 1,250.00', '111', 'https://img.test/a.jpg'],
                ['ZIM-1-W-40', 'ZIM-252', 'ZIMMERLI', 'Cotton Shirt', '<p>Soft</p>', 'White', '40', '1250',           '222', 'https://img.test/a.jpg'],
                ['ZIM-9-SOLO', '',        'ZIMMERLI', 'Silk Tie',     '',            '',      '',   '400',            '333', ''],
                ['NOT-ASKED',  'OTHER',   'ZIMMERLI', 'Other',        '',            '',      '',   '99',             '444', ''],
            ]);

        $this->app->instance(OneDriveService::class, $drive);
    }

    /**
     * A tab that spells everything differently — "Price" not "Retail Price",
     * "Color" not "Colour" — plus two columns the mapping has no home for.
     */
    private function fakeSheetWithOtherHeaders(): void
    {
        $drive = Mockery::mock(OneDriveService::class);
        $drive->shouldReceive('setUser')->andReturnSelf();
        $drive->shouldReceive('resolveShareItem')->andReturn(['driveId' => 'd', 'itemId' => 'i']);
        $drive->shouldReceive('worksheetValues')
            ->with('d', 'i', 'Mens Fashion')
            ->andReturn([
                ['SKU', 'Style', 'Brand', 'Title', 'Color', 'Price', 'EAN', 'Country of Origin', 'Season'],
                ['ZIM-1-W-38', 'ZIM-252', 'ZIMMERLI', 'Cotton Shirt', 'White', 'QAR 1,250.00', "'7613109523528", 'Switzerland', 'SS26'],
            ]);

        $this->app->instance(OneDriveService::class, $drive);
    }

    private function build(ProductRequest $request): array
    {
        return app(ProductRequestDraftBuilder::class)->build($request);
    }

    public function test_skus_sharing_a_style_code_become_one_product_with_a_variant_each(): void
    {
        $user    = $this->user();
        $request = $this->request($this->store(), $user, ['ZIM-1-W-38', 'ZIM-1-W-40', 'ZIM-9-SOLO']);
        $this->fakeSheet();

        $result = $this->build($request);

        $this->assertSame(2, $result['built']);
        $this->assertSame(3, $result['variants']);

        $shirt = $request->draftProducts()->where('style_code', 'ZIM-252')->sole();
        $this->assertSame('Cotton Shirt', $shirt->title);
        $this->assertSame('ZIMMERLI', $shirt->vendor);
        $this->assertSame(['Color', 'Size'], $shirt->optionNames());
        $this->assertSame(2, $shirt->variants()->count());

        // "QAR 1,250.00" and "1250" have to reach Shopify as the same number.
        $this->assertEqualsWithDelta(1250.00, (float) $shirt->variants()->first()->price, 0.001);

        $tie = $request->draftProducts()->whereNull('style_code')->sole();
        $this->assertSame(1, $tie->variants()->count());
        $this->assertSame(['Title'], $tie->optionNames(), 'A product with no options still needs one.');
        $this->assertSame('Default Title', $tie->variants()->first()->option1_value);
    }

    /**
     * The point of the alias list: a tab naming its columns differently still
     * produces a complete product, instead of one with no price.
     */
    public function test_a_tab_with_different_column_names_still_maps(): void
    {
        $user    = $this->user();
        $request = $this->request($this->store(), $user, ['ZIM-1-W-38']);
        $this->fakeSheetWithOtherHeaders();

        $result = $this->build($request);

        $draft   = ProductRequestDraftProduct::sole();
        $variant = $draft->variants()->sole();

        $this->assertSame('Cotton Shirt', $draft->title);
        $this->assertSame('ZIM-252', $draft->style_code);
        $this->assertEqualsWithDelta(1250.00, (float) $variant->price, 0.001);
        $this->assertSame('White', $variant->option1_value);
        $this->assertSame('7613109523528', $variant->barcode, 'Excel\'s apostrophe is not part of the barcode.');

        // And it says which column it used, so a wrong guess is visible.
        $this->assertSame('Price', $result['columns']['used']['price']);
        $this->assertSame('EAN', $result['columns']['used']['barcode']);
    }

    /** No column is dropped: what could not be mapped stays on the variant. */
    public function test_columns_with_no_shopify_field_are_kept_and_reported(): void
    {
        $user    = $this->user();
        $request = $this->request($this->store(), $user, ['ZIM-1-W-38']);
        $this->fakeSheetWithOtherHeaders();

        $result = $this->build($request);

        $this->assertContains('Country of Origin', $result['columns']['ignored']);
        $this->assertContains('Season', $result['columns']['ignored']);

        $variant = ProductRequestDraftVariant::sole();
        $this->assertSame('Switzerland', $variant->sheet_row['Country of Origin']);
        $this->assertSame('SS26', $variant->sheet_row['Season']);

        // And they are the ones offered to the reviewer, not the mapped values.
        $unmapped = $variant->unmappedSheetColumns();
        $this->assertArrayHasKey('Country of Origin', $unmapped);
        $this->assertArrayNotHasKey('SKU', $unmapped);
        $this->assertArrayNotHasKey('Color', $unmapped);
    }

    /** A header the alias list never heard of is still found by substring. */
    public function test_an_unlisted_price_column_is_matched_loosely_and_flagged(): void
    {
        $user    = $this->user();
        $request = $this->request($this->store(), $user, ['ZIM-1-W-38']);

        $drive = Mockery::mock(OneDriveService::class);
        $drive->shouldReceive('setUser')->andReturnSelf();
        $drive->shouldReceive('resolveShareItem')->andReturn(['driveId' => 'd', 'itemId' => 'i']);
        $drive->shouldReceive('worksheetValues')
            ->with('d', 'i', 'Mens Fashion')
            ->andReturn([
                ['Item SKU', 'Brand Name', 'Product Name', 'Retail Price (QAR) incl VAT'],
                ['ZIM-1-W-38', 'ZIMMERLI', 'Cotton Shirt', '1250'],
            ]);

        $this->app->instance(OneDriveService::class, $drive);

        $result = $this->build($request);

        $this->assertEqualsWithDelta(1250.00, (float) ProductRequestDraftVariant::sole()->price, 0.001);
        $this->assertNotEmpty($result['columns']['loose'], 'A guessed column must be flagged as guessed.');
        $this->assertStringContainsString('Retail Price (QAR) incl VAT', implode(' ', $result['columns']['loose']));
    }

    /**
     * Their Shopify export writes the handle as code-title-colour, and a handle is
     * a product — so two colours of one style are two products, sizes as variants.
     */
    public function test_the_handle_follows_the_shopify_export_shape(): void
    {
        $user    = $this->user();
        $request = $this->request($this->store(), $user, ['ZIM-1-W-38']);
        $this->fakeSheet();

        $this->build($request);

        $this->assertSame(
            'zim-252-cotton-shirt-white',
            ProductRequestDraftProduct::where('style_code', 'ZIM-252')->value('handle'),
        );
    }

    public function test_two_colours_of_one_style_become_two_products(): void
    {
        $user    = $this->user();
        $request = $this->request($this->store(), $user, ['ZIM-1-W-38', 'ZIM-1-W-40', 'ZIM-1-N-38']);

        $drive = Mockery::mock(OneDriveService::class);
        $drive->shouldReceive('setUser')->andReturnSelf();
        $drive->shouldReceive('resolveShareItem')->andReturn(['driveId' => 'd', 'itemId' => 'i']);
        $drive->shouldReceive('worksheetValues')
            ->with('d', 'i', 'Mens Fashion')
            ->andReturn([
                ['Item SKU', 'Style Code', 'Brand Name', 'Product Name', 'Colour', 'Size', 'Retail Price'],
                ['ZIM-1-W-38', 'ZIM-252', 'ZIMMERLI', 'Cotton Shirt', 'White', '38', '1250'],
                ['ZIM-1-W-40', 'ZIM-252', 'ZIMMERLI', 'Cotton Shirt', 'White', '40', '1250'],
                ['ZIM-1-N-38', 'ZIM-252', 'ZIMMERLI', 'Cotton Shirt', 'Navy',  '38', '1250'],
            ]);

        $this->app->instance(OneDriveService::class, $drive);

        $result = $this->build($request);

        $this->assertSame(2, $result['built'], 'One product per colour.');
        $this->assertSame(3, $result['variants']);

        $white = ProductRequestDraftProduct::where('handle', 'zim-252-cotton-shirt-white')->sole();
        $navy  = ProductRequestDraftProduct::where('handle', 'zim-252-cotton-shirt-navy')->sole();

        $this->assertSame(2, $white->variants()->count(), 'Both sizes on the white product.');
        $this->assertSame(1, $navy->variants()->count());
    }

    /** Two products must never share a handle — that is one product in Shopify. */
    public function test_the_same_title_with_no_style_code_still_gets_distinct_handles(): void
    {
        $user    = $this->user();
        $request = $this->request($this->store(), $user, ['TIE-A', 'TIE-B']);

        $drive = Mockery::mock(OneDriveService::class);
        $drive->shouldReceive('setUser')->andReturnSelf();
        $drive->shouldReceive('resolveShareItem')->andReturn(['driveId' => 'd', 'itemId' => 'i']);
        $drive->shouldReceive('worksheetValues')
            ->with('d', 'i', 'Mens Fashion')
            ->andReturn([
                ['Item SKU', 'Brand Name', 'Product Name', 'Retail Price'],
                ['TIE-A', 'ZIMMERLI', 'Silk Tie', '400'],
                ['TIE-B', 'ZIMMERLI', 'Silk Tie', '400'],
            ]);

        $this->app->instance(OneDriveService::class, $drive);

        $result = $this->build($request);

        $this->assertSame(2, $result['built']);
        $this->assertCount(2, ProductRequestDraftProduct::pluck('handle')->unique());
    }

    public function test_only_skus_missing_from_shopify_are_staged(): void
    {
        $user    = $this->user();
        $request = $this->request($this->store(), $user, ['ZIM-1-W-38', 'ZIM-1-W-40'], alreadyInShopify: ['ZIM-1-W-40']);
        $this->fakeSheet();

        $this->build($request);

        $this->assertSame(1, ProductRequestDraftProduct::sole()->variants()->count());
        $this->assertSame('ZIM-1-W-38', ProductRequestDraftProduct::sole()->variants()->first()->sku);
    }

    public function test_a_sku_the_sheet_has_no_row_for_is_reported_not_invented(): void
    {
        $user    = $this->user();
        $request = $this->request($this->store(), $user, ['ZIM-1-W-38', 'GHOST-SKU']);
        $this->fakeSheet();

        $result = $this->build($request);

        $this->assertSame(['GHOST-SKU'], $result['missing_from_sheet']);
        $this->assertSame(1, $request->draftProducts()->count());
    }

    /** On a Cegid website an unmatched SKU is Supply Chain's, not a new product. */
    public function test_a_cegid_website_cannot_build_drafts(): void
    {
        $user    = $this->user();
        $request = $this->request($this->store(cegid: true), $user, ['ZIM-1-W-38']);
        $this->fakeSheet();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cegid/');

        $this->build($request);
    }

    /** Corrections made on the review screen survive a rebuild. */
    public function test_rebuilding_leaves_drafts_already_staged_alone(): void
    {
        $user    = $this->user();
        $request = $this->request($this->store(), $user, ['ZIM-1-W-38', 'ZIM-1-W-40']);
        $this->fakeSheet();

        $this->build($request);
        ProductRequestDraftProduct::sole()->update(['title' => 'Hand-corrected title']);

        $this->fakeSheet();
        $result = $this->build($request);

        $this->assertSame(0, $result['built']);
        $this->assertSame(1, $result['skipped_existing']);
        $this->assertSame('Hand-corrected title', ProductRequestDraftProduct::sole()->title);
    }

    public function test_the_csv_is_in_shopify_import_format_and_never_published(): void
    {
        $user    = $this->user();
        $request = $this->request($this->store(), $user, ['ZIM-1-W-38', 'ZIM-1-W-40']);
        $this->fakeSheet();
        $this->build($request);

        $handle = fopen('php://memory', 'r+');
        app(ProductRequestDraftCsv::class)->write($request, $handle);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        $header = $rows[0];
        $this->assertSame(ProductRequestDraftCsv::COLUMNS, $header);

        $column = fn (array $row, string $name) => $row[array_search($name, $header, true)];

        // First row of a product carries the product columns...
        $this->assertSame('Cotton Shirt', $column($rows[1], 'Title'));
        $this->assertSame('FALSE', $column($rows[1], 'Published'));
        $this->assertSame('draft', $column($rows[1], 'Status'));
        $this->assertSame('Color', $column($rows[1], 'Option1 Name'));
        $this->assertSame('White', $column($rows[1], 'Option1 Value'));
        $this->assertSame('38', $column($rows[1], 'Option2 Value'));

        // ...and every later variant row repeats only the handle.
        $this->assertSame($column($rows[1], 'Handle'), $column($rows[2], 'Handle'));
        $this->assertSame('', $column($rows[2], 'Title'));
        $this->assertSame('ZIM-1-W-40', $column($rows[2], 'Variant SKU'));

        // Columns the team's template carries that we deliberately leave to Shopify
        // or to the copywriter, rather than filling in with a guess.
        foreach (['Product Category', 'Option1 Linked To', 'SEO Title', 'SEO Description'] as $blank) {
            $this->assertSame('', $column($rows[1], $blank), "{$blank} should be left empty.");
        }

        $this->assertSame('FALSE', $column($rows[1], 'Gift Card'));
        $this->assertSame('shopify', $column($rows[1], 'Variant Inventory Tracker'));
        $this->assertSame('deny', $column($rows[1], 'Variant Inventory Policy'));
        $this->assertSame('kg', $column($rows[1], 'Variant Weight Unit'));
    }

    /** Excel writes long barcodes as '7613109523528 — the apostrophe is not the barcode. */
    public function test_an_excel_escaped_barcode_is_cleaned_before_export(): void
    {
        $user    = $this->user();
        $request = $this->request($this->store(), $user, ['ZIM-9-SOLO']);
        $this->fakeSheet();
        $this->build($request);

        ProductRequestDraftProduct::sole()->variants()->update(['barcode' => "'7613109523528"]);

        $handle = fopen('php://memory', 'r+');
        app(ProductRequestDraftCsv::class)->write($request, $handle);
        rewind($handle);

        $header = fgetcsv($handle);
        $row    = fgetcsv($handle);
        fclose($handle);

        $this->assertSame('7613109523528', $row[array_search('Variant Barcode', $header, true)]);
    }

    public function test_pushing_creates_draft_products_and_records_the_shopify_id(): void
    {
        $user    = $this->user();
        $store   = $this->store();
        $request = $this->request($store, $user, ['ZIM-1-W-38', 'ZIM-1-W-40', 'ZIM-9-SOLO']);
        $this->fakeSheet();
        $this->build($request);

        $payloads = [];
        $this->fakeShopify($payloads);

        $result = app(ProductRequestDraftPusher::class)->push($request, $store, $user);

        $this->assertSame(2, $result['pushed']);
        $this->assertSame(0, $result['failed']);

        foreach ($request->draftProducts as $draft) {
            $this->assertTrue($draft->isPushed());
            $this->assertNotNull($draft->shopify_product_id);
            $this->assertSame($store->id, $draft->pushed_to_store_id);
        }

        // Shopify itself is what forces the draft status; the payload has to carry
        // the variants and options it needs to build the product correctly.
        $shirt = collect($payloads)->firstWhere('title', 'Cotton Shirt');
        $this->assertSame([['name' => 'Color'], ['name' => 'Size']], $shirt['options']);
        $this->assertCount(2, $shirt['variants']);
        $this->assertSame('ZIM-1-W-38', $shirt['variants'][0]['sku']);
        $this->assertSame('White', $shirt['variants'][0]['option1']);
    }

    public function test_a_draft_missing_a_price_is_skipped_rather_than_half_created(): void
    {
        $user    = $this->user();
        $store   = $this->store();
        $request = $this->request($store, $user, ['ZIM-1-W-38', 'ZIM-1-W-40']);
        $this->fakeSheet();
        $this->build($request);

        ProductRequestDraftProduct::sole()->variants()->update(['price' => null]);

        $payloads = [];
        $this->fakeShopify($payloads);

        $result = app(ProductRequestDraftPusher::class)->push($request, $store, $user);

        $this->assertSame(0, $result['pushed']);
        $this->assertSame(1, $result['skipped']);
        $this->assertEmpty($payloads);
    }

    public function test_a_draft_is_never_pushed_twice(): void
    {
        $user    = $this->user();
        $store   = $this->store();
        $request = $this->request($store, $user, ['ZIM-9-SOLO']);
        $this->fakeSheet();
        $this->build($request);

        $payloads = [];
        $this->fakeShopify($payloads);

        app(ProductRequestDraftPusher::class)->push($request, $store, $user);
        $second = app(ProductRequestDraftPusher::class)->push($request, $store, $user);

        $this->assertSame(0, $second['pushed']);
        $this->assertSame(1, $second['skipped']);
        $this->assertCount(1, $payloads);
    }

    /** The push writes to a real storefront — the target must be one this user has. */
    public function test_pushing_to_a_website_the_user_has_no_access_to_is_refused(): void
    {
        $this->user();   // whose OneDrive token the sheet is read with
        $store   = $this->store();
        $user    = User::create([
            'name' => 'Limited', 'email' => 'limited@example.test', 'password' => 'password',
            'is_active' => true, 'perm_product_request' => true,
        ]);
        $user->stores()->attach($store->id);

        $offLimits = Store::create([
            'name' => 'Off Limits', 'shopify_domain' => 'off.myshopify.com', 'is_active' => true,
        ]);

        $request = $this->request($store, $user, ['ZIM-9-SOLO']);
        $this->fakeSheet();
        $this->build($request);

        $this->actingAs($user)
            ->post(route('product-requests.drafts.push', $request), ['store_id' => $offLimits->id])
            ->assertSessionHasErrors('store_id');

        $this->assertFalse(ProductRequestDraftProduct::sole()->isPushed());
    }

    /** Records every payload handed to Shopify, and hands back fake product ids. */
    private function fakeShopify(array &$payloads): void
    {
        $shopify = Mockery::mock(ShopifyService::class);
        $shopify->shouldReceive('createFullProduct')
            ->andReturnUsing(function (array $payload) use (&$payloads) {
                $payloads[] = $payload;
                return ['product_id' => (string) (7000 + count($payloads)), 'image_map' => []];
            });

        $this->app->bind(ProductRequestDraftPusher::class, fn ($app) => new class($app->make(ProductRequestWorkflow::class), $shopify) extends ProductRequestDraftPusher {
            public function __construct(ProductRequestWorkflow $workflow, private $fake)
            {
                parent::__construct($workflow);
            }

            protected function shopifyFor(Store $store): ShopifyService
            {
                return $this->fake;
            }
        });
    }
}
