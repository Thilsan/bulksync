<?php

namespace App\Services;

use App\Models\ProductRequest;
use Illuminate\Support\Collection;

/**
 * Writes staged drafts out in Shopify's product import CSV format.
 *
 * One row per variant. The first row of a product carries the product-level
 * columns and every following row repeats only the handle — which is how
 * Shopify's own export looks, and what its importer expects.
 *
 * Status is always "draft": this file exists so the team can check the products
 * before anything reaches the storefront, and an import that published them
 * would defeat the point.
 */
class ProductRequestDraftCsv
{
    /**
     * The team's own Shopify template, column for column — no image columns and
     * no SEO, because images reach the product through the photo pipeline and
     * the copy is written afterwards. Shopify matches these header names exactly,
     * so the order and spelling here are not free to change.
     */
    public const COLUMNS = [
        'Handle', 'Title', 'Body (HTML)', 'Vendor', 'Product Category', 'Type', 'Tags', 'Published',
        'Option1 Name', 'Option1 Value', 'Option1 Linked To',
        'Option2 Name', 'Option2 Value', 'Option2 Linked To',
        'Option3 Name', 'Option3 Value', 'Option3 Linked To',
        'Variant SKU', 'Variant Grams', 'Variant Inventory Tracker', 'Variant Inventory Qty',
        'Variant Inventory Policy', 'Variant Fulfillment Service', 'Variant Price', 'Variant Compare At Price',
        'Variant Requires Shipping', 'Variant Taxable', 'Variant Barcode',
        'Gift Card', 'SEO Title', 'SEO Description', 'Variant Weight Unit', 'Status',
    ];

    /** @param resource $handle */
    public function write(ProductRequest $request, $handle): int
    {
        fputcsv($handle, self::COLUMNS);

        $rows = 0;

        $request->draftProducts()->with('variants')->orderBy('id')->chunk(100, function (Collection $products) use ($handle, &$rows) {
            foreach ($products as $product) {
                $first = true;

                foreach ($product->variants as $variant) {
                    fputcsv($handle, $this->row($product, $variant, $first));
                    $first = false;
                    $rows++;
                }
            }
        });

        return $rows;
    }

    private function row($product, $variant, bool $first): array
    {
        return [
            $product->handle,
            $first ? $product->title : '',
            $first ? $product->body_html : '',
            $first ? $product->vendor : '',
            '',                                           // Product Category — Shopify's taxonomy, set in admin
            $first ? $product->product_type : '',
            $first ? $product->tags : '',
            $first ? 'FALSE' : '',                        // never published by an import
            $first ? $product->option1_name : '',
            $variant->option1_value,
            '',                                           // Option1 Linked To — combined listings only
            $first ? $product->option2_name : '',
            $variant->option2_value,
            '',
            $first ? $product->option3_name : '',
            $variant->option3_value,
            '',
            $variant->sku,
            $variant->weight === null ? 0 : (int) round((float) $variant->weight * 1000),   // Shopify wants grams
            'shopify',
            $variant->inventory_qty,
            'deny',
            'manual',
            $variant->price,
            $variant->compare_at_price,
            'TRUE',
            'TRUE',
            $this->barcode($variant->barcode),
            $first ? 'FALSE' : '',                        // Gift Card
            '',                                           // SEO Title — written later, not guessed here
            '',                                           // SEO Description
            $variant->weight_unit,
            $first ? 'draft' : '',
        ];
    }

    /**
     * Excel writes long barcodes as '7613109523528 to stop itself turning them
     * into scientific notation. That apostrophe is Excel's, not the barcode's,
     * and importing it would put a stray character on every variant.
     */
    private function barcode(?string $barcode): ?string
    {
        return $barcode === null ? null : ltrim($barcode, "'");
    }
}
