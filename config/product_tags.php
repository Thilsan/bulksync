<?php

/*
|--------------------------------------------------------------------------
| Product tag taxonomy
|--------------------------------------------------------------------------
|
| The fixed tag sets the AI Content review screen offers, keyed by category
| and then by product type. These replace the free-form tags Gemini used to
| invent: the store's tag vocabulary is a merchandising decision, not
| something a model should guess at.
|
| Each category has a "base" set applied to every type under it, plus the
| per-type tags. The review screen offers base + type, de-duplicated, with
| every tag pre-checked — unchecking is how you opt out of one.
|
| Tags are written exactly as they appear in Shopify (casing included), so
| adding one here never creates a near-duplicate of an existing tag.
|
*/

return [

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

];
