<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Category tab → Shopify product fields
    |--------------------------------------------------------------------------
    |
    | The per-category tabs on the tracking sheet ("Mens Fashion", "Lingerie",
    | ...) hold one row per SKU. This maps those column headers onto the fields
    | a Shopify product needs.
    |
    | Set a value to null when the sheet has no such column — the field is then
    | left blank on the draft for the team to fill in on the review screen. Only
    | 'sku' is required; without it a row cannot be matched to the request.
    |
    | Header matching is case-insensitive and ignores surrounding spaces, since
    | the sheet is hand-maintained and inconsistent tab to tab. A header listed
    | here that does not exist on the tab is simply ignored.
    |
    */
    'column_map' => [
        'sku'              => 'Item SKU',
        'style_code'       => 'Style Code',
        'brand'            => 'Brand Name',
        'title'            => 'Product Name',
        'body_html'        => 'Description',
        'product_type'     => 'Product Type',
        'tags'             => 'Tags',
        'option1_value'    => 'Colour',
        'option2_value'    => 'Size',
        'option3_value'    => null,          // e.g. "Cup Size" on lingerie tabs
        'price'            => 'Retail Price',
        'compare_at_price' => null,
        'barcode'          => 'Barcode',
        'weight'           => null,
        'inventory_qty'    => null,
        'image_src'        => 'Image URL',
    ],

    /*
    |--------------------------------------------------------------------------
    | Option names
    |--------------------------------------------------------------------------
    |
    | What Shopify calls the option columns above — "Colour" / "Size" become
    | Option1 Name / Option2 Name on every product built from this sheet. An
    | option whose value column is null above is dropped from the product.
    |
    */
    // Spelled the way this Shopify store spells them — its own export writes
    // "Color", not "Colour", and the importer matches the value it is given.
    'option_names' => ['Color', 'Size', 'Cup Size'],

    /*
    |--------------------------------------------------------------------------
    | Grouping
    |--------------------------------------------------------------------------
    |
    | SKUs sharing a style code become one product with a variant each. Where
    | the sheet has no style code column, or the cell is empty, the SKU stands
    | alone as its own single-variant product rather than being guessed into
    | someone else's.
    |
    */
    'weight_unit' => 'kg',

];
