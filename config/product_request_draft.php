<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Category tab → Shopify product fields
    |--------------------------------------------------------------------------
    |
    | The per-category tabs on the tracking sheet ("Mens Fashion", "Lingerie",
    | ...) hold one row per SKU, and they are not consistent with each other —
    | the same value is "Retail Price" on one tab and "Price" on the next.
    |
    | So each field lists the header names it may appear under, in order of
    | preference. The first one the tab actually has wins. A field that matches
    | nothing is left blank on the draft for the team to fill in, and the build
    | reports which column it used for each field so a wrong guess is visible
    | rather than silent.
    |
    | Whatever is NOT listed here is still captured: the full row is kept against
    | each variant and shown on the review screen, so no column is ever lost.
    |
    | Matching ignores case and surrounding spaces. Only 'sku' is essential —
    | without it a row cannot be tied to the request.
    |
    */
    'column_map' => [
        'sku'              => ['Item SKU', 'SKU', 'Item Code', 'Article', 'Article Code'],
        'style_code'       => ['Style Code', 'Style', 'Style No', 'Style Number', 'Model', 'Model No', 'Reference'],
        'brand'            => ['Brand Name', 'Brand', 'Vendor'],
        'title'            => ['Product Name', 'Title', 'Item Name', 'Item Description', 'Product Title'],
        'body_html'        => ['Description', 'Product Description', 'Long Description', 'Details'],
        'product_type'     => ['Product Type', 'Type', 'Sub Category', 'Subcategory', 'Product Category'],
        'tags'             => ['Tags', 'Keywords'],
        'option1_value'    => ['Colour', 'Color', 'Shade', 'Colour Name', 'Color Name'],
        'option2_value'    => ['Size', 'Volume', 'Capacity', 'Size Name'],
        'option3_value'    => ['Cup Size', 'Length', 'Width'],
        'price'            => ['Retail Price', 'Price', 'RRP', 'Selling Price', 'Retail', 'MRP', 'Unit Price', 'Retail Price (QAR)', 'Price QAR'],
        'compare_at_price' => ['Compare At Price', 'Was Price', 'Original Price', 'Old Price', 'Strike Price'],
        'barcode'          => ['Barcode', 'EAN', 'UPC', 'EAN Code', 'Barcode/EAN'],
        'weight'           => ['Weight', 'Weight (kg)', 'Gross Weight'],
        'inventory_qty'    => ['Qty', 'Quantity', 'Stock', 'Stock Qty', 'On Hand'],
        'image_src'        => ['Image URL', 'Image', 'Image Link', 'Photo URL'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Loose matching
    |--------------------------------------------------------------------------
    |
    | When none of a field's names above is on the tab, these substrings are
    | tried against the headers as a last resort — enough to find "Retail Price
    | (QAR) incl. VAT" without listing every variation somebody might type.
    |
    | A field found this way is reported as a loose match, because a loose match
    | is a guess and the team should see which column it landed on before the
    | products go to Shopify. Fields not listed here are never guessed at.
    |
    */
    'column_contains' => [
        'price'            => ['price', 'rrp'],
        'compare_at_price' => ['compare'],
        'barcode'          => ['barcode', 'ean'],
        'option1_value'    => ['colour', 'color'],
        'option2_value'    => ['size'],
        'style_code'       => ['style'],
        'image_src'        => ['image', 'photo'],
        'body_html'        => ['description'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Option names
    |--------------------------------------------------------------------------
    |
    | What Shopify calls the option columns — spelled the way this store spells
    | them, since its own export writes "Color", not "Colour". An option whose
    | value column matched nothing is dropped from the product entirely.
    |
    */
    'option_names' => ['Color', 'Size', 'Cup Size'],

    /*
    |--------------------------------------------------------------------------
    | Grouping
    |--------------------------------------------------------------------------
    |
    | SKUs sharing a style code become one product with a variant each. Where the
    | sheet has no style code column, or the cell is empty, the SKU stands alone
    | as its own single-variant product rather than being guessed into someone
    | else's product.
    |
    */
    'weight_unit' => 'kg',

];
