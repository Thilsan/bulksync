<?php

/*
|--------------------------------------------------------------------------
| Product tag taxonomy, per store
|--------------------------------------------------------------------------
|
| The fixed tag sets the AI Content review screen offers, keyed first by the
| store's shopify_domain (the same key config/product_request_sync.php uses),
| then by category, then by product type. These replace the free-form tags
| Gemini used to invent: a store's tag vocabulary is a merchandising
| decision, not something a model should guess at.
|
| Every store runs its own vocabulary — Bluesalon's categories and tags mean
| nothing on Paris Gallery — so a store absent from this file simply gets no
| tag picker, and the review screen says so rather than offering an empty
| dropdown. Adding a store is adding a key here; no code changes.
|
| Within a store, each category has a "base" set applied to every type under
| it, plus the per-type tags. The review screen offers base + type,
| de-duplicated, with every tag pre-checked — unchecking is how you opt out.
|
| Tags are written exactly as they appear in Shopify (casing included), so
| adding one here never creates a near-duplicate of an existing tag.
|
*/

return [

    // Bluesalon
    'qatarbluesalon.myshopify.com' => [

        'Women Clothing' => [

            'base' => [
                'All Clothing',
                'Clothing',
                'ClothingWomen',
                'Collection: Women Fashion',
                'GCC',
                'mcat: Women Clothing',
                'Scat: New In Women Clothing',
                'Women',
                'Women All Clothing',
                'Womens',
                'Womens Fashion',
            ],

            'types' => [

                'Dresses' => [
                    'Dress',
                    'Dresses',
                    'scat: Dresses',
                    'Women All Clothing Dresses',
                    'Women Dresses',
                ],

                'Long Dresses' => [
                    'Dress',
                    'Dresses',
                    'Long Dress',
                    'scat: Dresses',
                    'Women All Clothing Dresses',
                    'Women Dresses',
                ],

                'Occasion Dresses & Gowns' => [
                    'Dress',
                    'Dresses',
                    'Gown',
                    'Long Dress',
                    'Occasion Dresses',
                    'scat: Dresses',
                    'scat: Gowns',
                    'Women All Clothing Dresses',
                    'Women Dresses',
                ],

                'Abayas & Kaftans' => [
                    'Abaya',
                    'Kaftans & Abayas',
                    'scat: Abayas And Kaftans',
                    'Scat: New In Abayas and Kaftans',
                ],

                'Coats & Jackets' => [
                    'Jacket',
                    'Jackets',
                    'scat: Coats & Jackets',
                    'Women All Clothing Coats & Jackets',
                    'Women All Clothing Tailoring Coats & Jackets',
                    'women jacket',
                    'Women Jackets',
                ],

                'Blazers' => [
                    'Blazer',
                    'Blazers',
                    'scat: Blazers',
                ],

                'Cardigans' => [
                    'Cardigan',
                    'scat: Cardigan And Coverups',
                    'Sweaters and Cardigans',
                ],

                'Knitwear & Sweaters' => [
                    'scat: Knitwear & Sweaters',
                    'Women Knitwear',
                    'Women Knitwear & sweater',
                ],

                'Shirts' => [
                    'Shirt',
                    'Shirts',
                    'scat: Shirts & Blouses',
                    'Women All Clothing Shirts & Blouses',
                ],

                'Blouses' => [
                    'Blouse',
                    'scat: Shirts & Blouses',
                    'Women All Clothing Shirts & Blouses',
                ],

                'Trousers' => [
                    'Trouser',
                    'Trousers',
                    'scat: Trousers',
                    'Women All Clothing Trousers',
                    'Women All Clothing Trousers Tailoring',
                ],

                'Skirts' => [
                    'Skirt',
                    'scat: Skirts',
                    'Women All Clothing Skirts',
                ],

            ],
        ],

        'Men Clothing' => [

            'base' => [
                'All Clothing',
                'Men',
                'Mens',
                'Scat: New In Men Clothing',
            ],

            'types' => [

                'Polo Shirts' => [
                    "Men's Polo Shirt",
                    'Polo shirt',
                    'scat: Polo shirts',
                ],

                'Shirts' => [
                    'scat: Shirts',
                    'Shirts & T-Shirts',
                ],

                'T-Shirts' => [
                    'scat: T-Shirts',
                    'Tops & T-Shirts',
                ],

                'Sweaters & Cardigans' => [
                    'Men sweater',
                    'scat: sweaters and cardigans',
                    'Sweaters and Cardigans',
                ],

                'Sweatshirts & Hoodies' => [
                    'Men Sweatshirt & Hoodies',
                    'scat: Sweatshirts & hoodies',
                ],

                'Coats & Jackets' => [
                    'Coats & Jackets',
                    'scat: Jackets & coats',
                ],

                'Trousers' => [
                    'Men Trousers',
                    'scat: Trousers',
                ],

                'Jeans' => [
                    'Denim',
                    'scat: Jeans',
                ],

                'Sportswear' => [
                    'scat: Sportswear',
                    'Sportswear',
                ],

            ],
        ],

        /*
        | Accessories and shoes sit outside "All Clothing", so they are separate
        | categories rather than types under Men Clothing.
        */
        'Men Accessories' => [

            'base' => [
                'All Accessories',
                'Men',
                'Mens',
            ],

            'types' => [

                'Caps & Hats' => [
                    'Cap',
                    'Men Caps and Hats',
                    'scat: Caps & hats',
                ],

                'Scarves' => [
                    'Men Scarf',
                    'scat: Scarves',
                ],

                'Ties & Bowties' => [
                    'scat: Ties & bowties',
                    'Ties',
                ],

            ],
        ],

        'Men Shoes' => [

            'base' => [
                'All Shoes',
                'Men',
                'Mens',
            ],

            'types' => [

                'Sneakers' => [
                    'Men Sneaker',
                    'scat: Sneakers',
                    'Scat: new In Men Footwear',
                    'Sneaker',
                    'Sneakers',
                ],

            ],
        ],
    ],

];
