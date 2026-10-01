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
| Shape, for reference while this is still being filled in:
|
|     'store.myshopify.com' => [
|         'Category Name' => [
|             'base'  => ['Tag on every type in this category'],
|             'types' => [
|                 'Type Name' => ['Tag', 'Another tag'],
|             ],
|         ],
|     ],
|
*/

return [

    // Bluesalon. Categories are added one at a time, each derived from that
    // category's own Shopify product export.
    'qatarbluesalon.myshopify.com' => [

        "Women's Fashion" => [

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

                'Blouses' => [
                    'Blouse',
                    'scat: Shirts & Blouses',
                    'Women All Clothing Shirts & Blouses',
                ],

                'Shirts' => [
                    'scat: Shirts & Blouses',
                    'Women All Clothing Shirts & Blouses',
                ],

                'Knitwear & Sweaters' => [
                    'scat: Knitwear & Sweaters',
                    'Women Knitwear',
                    'Women Knitwear & sweater',
                ],

                'Cardigans' => [
                    'Cardigan',
                    'scat: Cardigan And Coverups',
                ],

                'Blazers' => [
                    'Blazer',
                    'Blazers',
                    'scat: Blazers',
                ],

                'Coats & Jackets' => [
                    'Coats & Jackets',
                    'scat: Coats & Jackets',
                    'Women All Clothing Coats & Jackets',
                    'Women All Clothing Tailoring Coats & Jackets',
                    'women jacket',
                    'Women Jackets',
                ],

                'Trousers' => [
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

        "Men's Fashion" => [

            /*
            | Only Men and Mens are shared: the top-level bucket tag splits
            | three ways (All Clothing / All Accessories / All Shoes), so it
            | lives on each type rather than in the base.
            */
            'base' => [
                'Men',
                'Mens',
            ],

            'types' => [

                'Polo Shirts' => [
                    'All Clothing',
                    "Men's Polo Shirt",
                    'Polo shirt',
                    'Scat: New In Men Clothing',
                    'scat: Polo shirts',
                ],

                'Shirts' => [
                    'All Clothing',
                    'Scat: New In Men Clothing',
                    'scat: Shirts',
                    'Shirts & T-Shirts',
                ],

                'T-Shirts' => [
                    'All Clothing',
                    'scat: T-Shirts',
                    'Tops & T-Shirts',
                ],

                'Sweaters & Cardigans' => [
                    'All Clothing',
                    'Men sweater',
                    'scat: sweaters and cardigans',
                    'Sweaters & cardigans',
                    'Sweaters and Cardigans',
                ],

                'Sweatshirts & Hoodies' => [
                    'All Clothing',
                    'Men Sweatshirt & Hoodies',
                    'Scat: New In Men Clothing',
                    'scat: Sweatshirts & hoodies',
                ],

                'Coats & Jackets' => [
                    'All Clothing',
                    'Coats & Jackets',
                    'scat: Jackets & coats',
                ],

                'Trousers' => [
                    'All Clothing',
                    'Men Trousers',
                    'scat: Trousers',
                ],

                'Jeans' => [
                    'All Clothing',
                    'Denim',
                    'scat: Jeans',
                ],

                'Sportswear' => [
                    'All Clothing',
                    'scat: Sportswear',
                    'Sportswear',
                ],

                'Caps & Hats' => [
                    'All Accessories',
                    'Cap',
                    'Men Caps and Hats',
                    'scat: Caps & hats',
                ],

                'Scarves' => [
                    'All Accessories',
                    'Men Scarf',
                    'scat: Scarves',
                ],

                'Ties & Bowties' => [
                    'All Accessories',
                    'scat: Ties & bowties',
                    'Ties',
                ],

                'Sneakers' => [
                    'All Shoes',
                    'Men Sneaker',
                    'Scat: new In Men Footwear',
                    'scat: Sneakers',
                    'Sneaker',
                    'Sneakers',
                ],

            ],
        ],
    ],
];
