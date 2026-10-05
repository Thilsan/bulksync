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

                // One type: both shared the same two tags, and a blouse-only
                // 'Blouse' tag would have mislabelled every shirt filed here.
                'Shirts & Blouses' => [
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
            | Men, Mens and GCC are the only tags this department puts on
            | (nearly) every row. The top-level bucket tag splits three ways
            | (All Clothing / All Accessories / All Shoes) and "Our Exclusives"
            | is missing from a few brands, so both live on the types.
            |
            | Campaign tags in the export (EID2023, Father's day, Last Chance,
            | Special Prices, SP) are deliberately left out: they describe a
            | promotion, not a product, and would be pushed onto new SKUs long
            | after the promotion ended.
            */
            'base' => [
                'GCC',
                'Men',
                'Mens',
            ],

            'types' => [

                'Polo Shirts' => [
                    'All Clothing',
                    "Men's Polo Shirt",
                    'Mens Fashion',
                    'Our Exclusives',
                    'Polo',
                    'Polo shirt',
                    'Polo T-shirt',
                    'Scat: New In Men Clothing',
                    'scat: Polo shirts',
                    'Shirts & T-Shirts',
                ],

                'Shirts' => [
                    'All Clothing',
                    'Men Shirts',
                    'Mens Fashion',
                    'Our Exclusives',
                    'Scat: New In Men Clothing',
                    'scat: Shirts',
                    'Shirt',
                    'Shirt Dress',
                    'Shirts',
                    'Shirts & T-Shirts',
                    'Top',
                ],

                'T-Shirts' => [
                    'All Clothing',
                    'Basics',
                    'Basics T-Shirts',
                    'Crew Neck',
                    'Men T-shirts',
                    'Mens Fashion',
                    'Our Exclusives',
                    'scat: T-Shirts',
                    'Shirts & T-Shirts',
                    'T-shirt',
                    'T-Shirt',
                    'T-Shirts',
                    'T. shirt',
                    'T.Shirt',
                    'T.shirt',
                    'Top',
                    'Tops',
                    'Tops & T-Shirts',
                    'Tshirt',
                ],

                'Sweaters & Cardigans' => [
                    'All Clothing',
                    'Cardigan',
                    'Coats & Jackets',
                    'Knitwear',
                    'knitwear',
                    'Men sweater',
                    'Men Sweatshirt',
                    'Mens Fashion',
                    'Our Exclusives',
                    'Pullover',
                    'scat: sweaters and cardigans',
                    'Sweater',
                    'Sweaters & cardigans',
                    'Sweaters and Cardigans',
                    'Sweaters and Jackets',
                    'Sweatknit',
                    'Sweatshirts and Jackets',
                    'Top',
                ],

                'Sweatshirts & Hoodies' => [
                    'Adult Sweatshirt',
                    'All Clothing',
                    'Coats & Jackets',
                    'Hoodies',
                    'Hoodies & Sweatshirt',
                    'Hoody',
                    'Knitwear',
                    'Loungewear',
                    'Men Hoodies',
                    'Men Sweatshirt',
                    'Men Sweatshirt & Hoodies',
                    'Mens Fashion',
                    'Our Exclusives',
                    'Scat: New In Men Clothing',
                    'scat: Sweatshirts & hoodies',
                    'Sweat jacket',
                    'Sweater',
                    'Sweatknit',
                    'Sweats',
                    'Sweatshirt',
                    'Sweatshirts and Jackets',
                ],

                'Pullovers' => [
                    'Coats & Jackets',
                    'Pullover',
                    'pullover',
                ],

                'Coats & Jackets' => [
                    'All Clothing',
                    'Clothing',
                    'Coat',
                    'Coats & Jackets',
                    'Jacket',
                    'jacket',
                    'Jackets',
                    'Jaket',
                    'Men Jackets',
                    'Mens Fashion',
                    'Our Exclusives',
                    'scat: Jackets & coats',
                    'Sports Jacket',
                    'Sports Jackets',
                    'Vest',
                ],

                'Trousers' => [
                    'All Clothing',
                    'Bermuda',
                    'Bottom',
                    'Clothing',
                    'Men Shorts',
                    'Men Trousers',
                    'Mens Fashion',
                    'Our Exclusives',
                    'Pant',
                    'Pants',
                    'scat: Shorts',
                    'scat: Trousers',
                    'scat: Trousers & shorts',
                    'Short',
                    'Shorts',
                    'Sweatpants',
                    'Trouser',
                    'Trousers',
                    'Trousers & Shorts',
                    'trousers for men',
                ],

                'Jeans' => [
                    'All Clothing',
                    'Clothing',
                    'Denim',
                    'Jeans',
                    'Men Jeans Pants',
                    'Mens Fashion',
                    'Our Exclusives',
                    'Pocket Jeans',
                    'scat: Jeans',
                    'scat: Trousers',
                    'scat: Trousers & shorts',
                    'Trouser',
                    'Trousers',
                    'Trousers & Shorts',
                ],

                'Sportswear' => [
                    'Activewear',
                    'All Clothing',
                    'Our Exclusives',
                    'scat: Sportswear',
                    'Sports Tops & T-Shirts',
                    'Sportswear',
                    'Sweatpants',
                    'Tank Top',
                ],

                'Caps & Hats' => [
                    'Accessories',
                    'All Accessories',
                    'BASIC',
                    'Basics',
                    'Bonet',
                    'Cap',
                    'Clothing',
                    'Hat',
                    'Hats',
                    'Men Caps and Hats',
                    'Mens Fashion',
                    'MF Accessories',
                    'Mf Accessories',
                    'Our Exclusives',
                    'scat: Caps & hats',
                ],

                'Scarves' => [
                    'Accessories',
                    'All Accessories',
                    'Men Scarf',
                    'Mens Fashion',
                    'Mf Accessories',
                    'MF Accessories',
                    'Our Exclusives',
                    'scarf',
                    'Scarves',
                    'scat: Scarves',
                ],

                'Belts' => [
                    'Accessories',
                    'All Accessories',
                    'Belt',
                    'Belts',
                    'Men All Accessories',
                    'Men Belts',
                    "Men's All Accessories",
                    'Mens Fashion',
                    'MF Accessories',
                    'Mf Accessories',
                    'Our Exclusives',
                ],

                'Ties & Bowties' => [
                    'Accessories',
                    'All Accessories',
                    'BASIC',
                    'Basics',
                    'Men All Accessories',
                    "Men's All Accessories",
                    'Mens Fashion',
                    'Mf Accessories',
                    'Our Exclusives',
                    'scat: Ties & bowties',
                    'Ties',
                ],

                'Sneakers' => [
                    'All Shoes',
                    'All ShoesOur Exclusives',
                    'Footwear',
                    'Leather Goods',
                    'Men All Shoes Shoes',
                    'Men Sneaker',
                    'Mens Fashion',
                    'Our Exclusives',
                    'Scat: new In Men Footwear',
                    'scat: Sneakers',
                    'scat: Trainers',
                    'Shoes',
                    'Sneaker',
                    'Sneakers',
                    'Trainers',
                ],

                'Boots' => [
                    'All Shoes',
                    'Bootie',
                    'Booties',
                    'Boots',
                    'Footwear',
                    'Men All Shoes Boots',
                    'Mens Boots',
                    'Our Exclusives',
                ],

                'Oxford Shoes' => [
                    'All Shoes',
                    'Our Exclusives',
                    'Oxford',
                    'Oxford Shoes',
                    'scat: Oxford Shoes',
                ],

                'Shoes' => [
                    'All Shoes',
                    'All ShoesOur Exclusives',
                    'Casual',
                    'Footwear',
                    'Men All Shoes Shoes',
                    'Mens Fashion',
                    'Our Exclusives',
                    'Shoes',
                ],

                'Suits' => [
                    'All Clothing',
                    'Blazers',
                    "Men's Suit",
                    'Mens Fashion',
                    'Our Exclusives',
                    'Suit',
                    'Suits',
                ],

                'Blazers' => [
                    'All Clothing',
                    'Blazer',
                    'Coats & Jackets',
                    'Jackets',
                    'Men Jackets',
                    "Men's Blazer",
                    'Mens Fashion',
                    'Our Exclusives',
                    'scat: Blazers',
                ],

                'Underwear' => [
                    'Accessories',
                    'All Clothing',
                    'Basics',
                    'Boxer',
                    'Brif',
                    'Clothing',
                    'Men Underwear',
                    'Mens Fashion',
                    'Mf Accessories',
                    'Our Exclusives',
                    'scat: Underwear',
                    'Tank',
                    'Underwear',
                    'Underwear Set',
                ],

                'Socks' => [
                    'Accessories',
                    'All Accessories',
                    'All Clothing',
                    'Basics',
                    'Men All Accessories',
                    'Mens Fashion',
                    'MF Accessories',
                    'Mf Accessories',
                    'scat: Socks',
                    'Socks',
                ],

                'Swimwear' => [
                    'All Clothing',
                    'Beachwear',
                    'Men Shorts',
                    'Mens Fashion',
                    'Our Exclusives',
                    'Short',
                    'Shorts',
                    'Swim short',
                    'Swimsuit',
                    'Swimsuits',
                    'Swimwear',
                    'Trousers & Shorts',
                ],

                'Sleepwear' => [
                    'All Clothing',
                    'Loungewear',
                    'Mens Fashion',
                    'Nightwear',
                    'Nightwear & Underwear',
                    'Our Exclusives',
                    'Pyjama',
                    'Pyjamas',
                    'Pyjamas & Loungewear',
                    'Robe',
                    'scat: Nightwear & loungewear',
                    'Sleepwear',
                    'Sleepwear & Loungewear',
                    'Sleepwear and Loungewear',
                ],

                'Training Suits' => [
                    'All Clothing',
                    'Jogger Suit',
                    'Jogging Pants',
                    'Jogging suit',
                    'Jogging Suits',
                    'Jogging trouser',
                    'Jogging Trouser',
                    'Jogging Trousers',
                    'Men Jogging Pants',
                    'Mens Fashion',
                    'Sportswear',
                    'Training suit',
                    'Training Suit',
                    'Trousers & Shorts',
                ],

                'Traditional Wear' => [
                    'All Clothing',
                    'Our Exclusives',
                    'Traditional Wear',
                ],

                'Wallets' => [
                    'Accessories',
                    'All Accessories',
                    'All Bags',
                    'All Bags-Our Exclusives',
                    'Card Holder',
                    'Credit Card Holder',
                    'Lifestyle Accessories',
                    'Men All Accessories',
                    'Men All bags',
                    'Men Wallet',
                    "Men's All Accessories",
                    'Mens Fashion',
                    'MF Accessories',
                    'Our Exclusives',
                    'scat: Wallets & cardholders',
                    'Small Wallet',
                    'Wallet',
                    'Wallet & Cardholders',
                    'Wallets',
                    'Wallets & Card Holder',
                ],

                'Bags' => [
                    'Accessories',
                    'All Bags',
                    'All Other Bags',
                    'Backpacks',
                    'Bag',
                    'Bags',
                    'Crossover',
                    'Men All Bags',
                    'Men Handbag',
                    'MF Accessories',
                    'Our Exclusives',
                    'Scat: New In Bags & Accessories',
                ],

                'Sunglasses' => [
                    'Accessories',
                    'All Accessories',
                    'Men All Accessories',
                    'Men Sunglasses',
                    "Men's All Accessories",
                    'Mens Fashion',
                    'Mf Accessories',
                    'Our Exclusives',
                    'scat: Sunglasses',
                    'Sunglasses',
                ],

                'Arabic Sandals' => [
                    'All Shoes',
                    'All ShoesOur Exclusives',
                    'Arabic Sandal',
                    'Footwear',
                    'Men  Sandals',
                    'Mens',
                    'Our Exclusives',
                    'Sandal',
                    'Sandals',
                    'Scat: new In Men Footwear',
                    'Scat: New In Mens Traditional Footwear',
                    'scat: Traditional Footwear',
                    'Slide Sandals',
                    'Slides & Sandals',
                    'Traditional Footwear',
                    'Traditional Wear',
                ],

                'Slides & Sandals' => [
                    'All Shoes',
                    'Footwear',
                    'Men All Shoes Slipper',
                    'Men All Shoes slippers',
                    'Mens',
                    'Our Exclusives',
                    'Sandals',
                    'scat: Slides & sandals',
                    'Slide',
                    'Slide Sandals',
                    'Slides',
                    'Slides & Sandals',
                    'Slippers',
                    'Traditional Footwear',
                ],

                'Loafers' => [
                    'All Shoes',
                    'All ShoesOur Exclusives',
                    'Drivers',
                    'Loafer',
                    'Loafers',
                    'Our Exclusives',
                    'scat: Loafers & drivers',
                    'Shoes',
                    'Slip On',
                ],

                'Gloves' => [
                    'Accessories',
                    'All Accessories',
                    'Gloves',
                    'MF Accessories',
                    'Sets',
                ],

                'Key Holders' => [
                    'All Accessories',
                    'Key Holder',
                    'Mf Accessories',
                ],

                'Backpacks' => [
                    'All Bags',
                    'All Bags-Our Exclusives',
                    'All Other Bags',
                    'Backpack',
                    'Backpack Bag',
                    'Backpacks',
                    'Casual Backpack',
                    'Men All bags',
                    'Men Backpack',
                    'Our Exclusives',
                ],

                'Crossbody & Shoulder Bags' => [
                    'All Bags',
                    'All Other Bags',
                    'Bag',
                    'Bags',
                    'Cross Body Bag',
                    'Crossbody',
                    'Crossbody Bag',
                    'Crossover',
                    'Men All bags',
                    'Men Crossbag',
                    'Men Crossbody bags',
                    'scat: Crossbody bags',
                    'Shoulder Bag',
                    'Waist Bag',
                ],

                'Pouches' => [
                    'All Bags',
                    'All Bags-Our Exclusives',
                    'Bag',
                    'Bags',
                    'Clutch Bag',
                    'Cosmetic Bag',
                    'Pouch',
                    'Toiletry Bag',
                    'Travel Pouch',
                    'Zip Pouch',
                ],

                'Luggage & Travel Bags' => [
                    'Attaches',
                    'Briefcase',
                    'Lugagge & Holdalls',
                    'Luggage & Travel',
                    'Travel Accessories',
                    'Travel Series',
                    'Trolley 3 P.C',
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
