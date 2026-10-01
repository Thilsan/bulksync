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

        'Watches' => [

            /*
            | Nothing is shared store-wide here: this export's "Watches" type is
            | the watches & jewellery department, so it also carries pens,
            | wallets and cufflinks, and the men's and women's tag sets have no
            | tag in common. Everything therefore lives on the type.
            */
            'base' => [],

            'types' => [

                "Men's Fashion Watches" => [
                    'Collection: Watches',
                    'Fashion Watches',
                    'Men',
                    'Mens',
                    'Mens Watches',
                    'scat: Fashion Timepieces',
                    'Scat: New In Mens Watches',
                    'Watch',
                    'Watches',
                ],

                "Men's Luxury Watches" => [
                    'Collection: Watches',
                    'Luxury Watches',
                    'Men',
                    'Mens',
                    'Mens Watches',
                    'scat: Luxury Timepieces',
                    'Scat: New In Mens Watches',
                    'Watch',
                    'Watches',
                ],

                "Women's Fashion Watches" => [
                    'Collection: Watches',
                    'Fashion Watches',
                    'scat: Fashion Timepieces',
                    'Scat: New In Women Watches',
                    'Watch',
                    'Watches',
                    'Women',
                    'Women Watches & jewellery',
                    'Womens',
                    'Womens Watches',
                ],

                'Watch Sets' => [
                    'Collection: Watches',
                    'Fashion Watches',
                    'Men',
                    'Mens',
                    'Mens Watches',
                    'scat: Fashion Timepieces',
                    'Scat: New In Mens Watches',
                    'Watch',
                    'Watch Set',
                    'Watches',
                ],

                'Bracelets' => [
                    'Fashion Jewellery',
                    'Ladies bracelet',
                    'scat: Bracelets',
                    'scat: Fashion Jewellery',
                    'Scat: New In Women Jewellery',
                    'Women',
                    'Women Watches & jewellery',
                    "Women's Jewellery",
                    'Womens',
                ],

                'Bangles' => [
                    'Bangle',
                    'Fashion Jewellery',
                    'Ladies bracelet',
                    'scat: Bracelets',
                    'scat: Fashion Jewellery',
                    'Scat: New In Women Jewellery',
                    'Women',
                    'Women Watches & jewellery',
                    "Women's Jewellery",
                    'Womens',
                ],

                'Necklaces' => [
                    'Fashion Jewellery',
                    'Necklace',
                    'Necklace With Pendant',
                    'scat: Fashion Jewellery',
                    'scat: Necklaces',
                    'Scat: New In Women Jewellery',
                    'Women',
                    'Women Necklaces',
                    'Women Watches & jewellery',
                    "Women's Jewellery",
                    'Womens',
                ],

                'Cufflinks' => [
                    'Cufflink',
                    'Cufflinks',
                    'Men',
                    'Men All Accessories',
                    "Men's All Accessories",
                    'Mens',
                    'scat: Cufflinks',
                ],

                'Wallets & Cardholders' => [
                    'Men',
                    'Men All Accessories',
                    'Men Wallet',
                    "Men's All Accessories",
                    'Mens',
                    'scat: Wallets & cardholders',
                    'Wallet & Cardholders',
                    'Wallets & Card Holder',
                ],

                'Pens' => [
                    'Men',
                    'Men All Accessories',
                    "Men's All Accessories",
                    'Mens',
                    'Pen',
                    'scat: Pens',
                ],

            ],
        ],

        'Perfumes & Cosmetics' => [

            /*
            | No tag is shared across this category either: a mainstream
            | women's fragrance, a niche gift set and a box of agarwood have
            | nothing in common in this export, so each type carries its own
            | full set.
            */
            'base' => [],

            'types' => [

                "Women's Fragrances" => [
                    'Beauty Fragrances',
                    'Beauty Fragrances Fragrance',
                    'Beauty Women fragrances',
                    'Fragrance',
                    'Fragrances & Cosmetics',
                    'scat: Fragrance',
                    'Scat: New In Beauty & fragrance',
                    'scat: Women Fragrance',
                    'Women',
                    "Women'S Fragrance",
                ],

                "Men's Fragrances" => [
                    'Beauty Fragrances',
                    'Beauty Fragrances Fragrance',
                    'Beauty Men fragrances',
                    'Fragrance',
                    'Fragrances & Cosmetics',
                    "Men'S Fragrances",
                    'Mens',
                    'scat: Fragrance',
                    'scat: Men Fragrance',
                    'Scat: New In Beauty & fragrance',
                ],

                'Niche Fragrances' => [
                    'Beauty',
                    'Beauty Niche Fragrances',
                    'Beauty Niche fragrances Fragrance',
                    'Niche fragrances',
                    'Niche Perfumery',
                    'scat: Fragrance',
                    'Scat: New In Beauty & fragrance',
                    'scat: Niche Fragrances',
                ],

                'Fragrance Gift Sets' => [
                    'Beauty Fragrances',
                    'Beauty Fragrances Fragrance',
                    'Beauty Fragrances Gift Set',
                    'Fragrance',
                    'Fragrances',
                    'Fragrances & Cosmetics',
                    'Gift',
                    'Gift Set',
                    'scat: Fragrance',
                    'scat: Gift Set',
                    'Scat: New In Beauty & fragrance',
                ],

                'Niche Gift Sets' => [
                    'Beauty Niche Fragrances',
                    'Beauty Niche fragrances Gift Set',
                    'Niche fragrances',
                    'Niche Perfumery',
                    'scat: Gift Set',
                    'Scat: New In Beauty & fragrance',
                    'scat: Niche Fragrances',
                ],

                'Body Lotion' => [
                    'Beauty',
                    'Beauty Niche Bathline',
                    'Beauty Niche fragrances Bathline',
                    'Body Lotion',
                    'Niche fragrances',
                    'Niche Perfumery',
                    'Scat: New In Beauty & fragrance',
                    'scat: Niche Bathline',
                ],

                'Hand Cream' => [
                    'Beauty',
                    'Beauty Niche Bathline',
                    'Beauty Niche fragrances Bathline',
                    'Hand Cream',
                    'Niche Perfumery',
                    'Scat: New In Beauty & fragrance',
                    'scat: Niche Bathline',
                ],

                'Home Fragrance' => [
                    'All home fragrance',
                    'Beauty',
                    'Beauty Fragrancess Home fragrance',
                    'Beauty Niche fragrances Home Fragrance',
                    'Niche fragrances',
                    'Scat: New In Beauty & fragrance',
                    'scat: Niche Home Fragrance',
                ],

            ],
        ],

        'Make Up & Skin Care' => [

            /*
            | 'Beauty' is the one tag this export puts on nearly every row, so
            | it is the base; everything else splits between cosmetics, skin
            | care and hair care, which share nothing else.
            */
            'base' => [
                'Beauty',
            ],

            'types' => [

                'Lipsticks' => [
                    'Beauty Cosmetics',
                    'Beauty Cosmetics Lips',
                    'Beauty Lips',
                    'Lips',
                    'Lipstick',
                    'Liquid Lipstick',
                    'Makeup',
                    'scat: Lipsticks',
                ],

                'Foundation' => [
                    'Beauty Cosmetics',
                    'Beauty Cosmetics Face',
                    'Beauty Selfcare Skincare',
                    'Foundation',
                    'Make Up & Skin Care',
                    'Makeup',
                    'scat: Foundation',
                    'scat: Skincare',
                    'Serum',
                    'Serum and Facial Treatment',
                    'Serums',
                    'Skin Care',
                ],

                'Concealer' => [
                    'Beauty Cosmetics',
                    'Beauty Cosmetics Face',
                    'Beauty Face',
                    'Concealer',
                    'Face make up',
                    'Make Up & Skin Care',
                    'Makeup',
                    'scat: Concealer',
                ],

                'Blush' => [
                    'Beauty Cosmetics',
                    'Beauty Cosmetics Face',
                    'Beauty Selfcare',
                    'Blush',
                    'Blusher',
                    'Face make up',
                    'Make Up & Skin Care',
                    'Makeup',
                    'scat: Blush',
                ],

                'Eyebrows' => [
                    'Beauty Cosmetics',
                    'Beauty Cosmetics Eyes',
                    'Beauty Eyes',
                    'Eye Pencil',
                    'Eyebrow',
                    'Eyebrows',
                    'Eyebrows Pencil',
                    'scat: Eyebrows',
                ],

                'Face Serums' => [
                    'Beauty Cosmetics Face',
                    'Beauty Face',
                    'Beauty Selfcare',
                    'Beauty Selfcare Skincare',
                    'Face',
                    'Make Up & Skin Care',
                    'scat: Skincare',
                    'Serum',
                    'Serum and Facial Treatment',
                    'Serums',
                    'Skin Care',
                    'Skincare',
                ],

                'Face Cream' => [
                    'Beauty Cosmetics',
                    'Beauty Cosmetics Face',
                    'Beauty Face',
                    'Beauty Selfcare Skincare',
                    'Face Cream',
                    'Make Up & Skin Care',
                    'scat: Skincare',
                    'Skincare',
                ],

                'Body Lotion' => [
                    'Beauty Selfcare',
                    'Beauty Selfcare Bodycare',
                    'Beauty Selfcare Skincare',
                    'Body Lotion',
                    'scat: Skincare',
                    'Selfcare',
                    'Skincare',
                ],

                'Hand Cream' => [
                    'Beauty Niche Fragrances',
                    'Hand Cream',
                    'Niche fragrances',
                    'scat: Handcare',
                    'scat: Niche Fragrances',
                ],

                'Shampoo' => [
                    'Beauty Haircare',
                    'Beauty Selfcare',
                    'Beauty Selfcare Haircare',
                    'Beauty Selfcare Skincare',
                    'Hair Care',
                    'Hair Shampoo',
                    'Haircare',
                    'scat: Haircare',
                    'Selfcare',
                    'Shampoo',
                ],

                'Conditioner' => [
                    'Beauty Haircare',
                    'Beauty Selfcare',
                    'Beauty Selfcare Haircare',
                    'Beauty Selfcare Skincare',
                    'Hair Care',
                    'Hair Conditioner',
                    'Haircare',
                    'scat: Haircare',
                    'Selfcare',
                ],

                'Hair Treatments' => [
                    'Beauty Haircare',
                    'Beauty Selfcare',
                    'Beauty Selfcare Haircare',
                    'Beauty Selfcare Skincare',
                    'Hair Care',
                    'Haircare',
                    'scat: Haircare',
                    'Selfcare',
                ],

            ],
        ],
    ],
];
