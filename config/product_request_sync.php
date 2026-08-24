<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Master tracking sheet
    |--------------------------------------------------------------------------
    |
    | The "PRODUCT LISTING REQUEST" tab is the real request registry — one row
    | per brand/request. The other tabs (Perfumes & Cosmetics, Luggage, ...)
    | are a running historical SKU catalog, not a queue of new requests, so
    | they are only ever used to look up SKUs for a master row that has
    | already been matched — never scanned for new requests on their own.
    |
    */

    'master_sheet_url' => env(
        'PRODUCT_REQUEST_SHEET_URL',
        'https://abuissa1-my.sharepoint.com/:x:/r/personal/ahamed_ismalebbe_abuissa_com/_layouts/15/Doc.aspx?sourcedoc=%7BA4A6660A-454B-40D3-9F04-FB4F52CE8BD1%7D&file=E-Com%20Product%20Listing%20Request%20Tracking.xlsx&action=default&mobileredirect=true'
    ),

    'master_worksheet' => 'PRODUCT LISTING REQUEST',

    // Whose stored OneDrive token is used to read the sheet.
    'sync_user_email' => env('PRODUCT_REQUEST_SHEET_SYNC_USER', 'ahamed.ismalebbe@abuissa.com'),

    /*
    |--------------------------------------------------------------------------
    | Website → Store
    |--------------------------------------------------------------------------
    |
    | The sheet's "Website" cell often names more than one store at once
    | ("BS - PG-SN", "BS & Samsonite"), so it is split into tokens and each
    | token is looked up here. A token with no entry here is left unmatched
    | and that request is flagged for manual review instead of guessed —
    | add the missing store to Settings, then add its code below.
    |
    */
    /*
    | Tokens the sheet uses that this app deliberately does not sync. Reported as
    | skipped on purpose rather than as a missing store, so a permanent decision
    | stops looking like an outstanding job every single run.
    */
    'ignored_website_tokens' => [
        'COLE HAAN WEBSITE',
    ],

    /*
    | A token with no entry here is matched against the store names in Settings
    | before it is given up on — so creating a store called "Gold Gourmet" is
    | enough, without also editing this file.
    */
    'website_store_map' => [
        'BS'           => 'qatarbluesalon.myshopify.com',
        'PG'           => 'paris-gallery-qatar.myshopify.com',
        'SN'           => 'secretnotesperfumes.myshopify.com',
        'AT'           => 'amtqatar.myshopify.com',
        'Samsonite'    => 'samsoniteqatar.myshopify.com',
        'Mosafer'      => 'mosaferqa.myshopify.com',
        'Qatar Outlet' => 'qataroutlet.myshopify.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | Department → app category + SKU worksheet
    |--------------------------------------------------------------------------
    |
    | "Department" on the master tab maps to ProductRequest::CATEGORIES (the
    | app's coarse enum) and to the worksheet tab that holds this request's
    | SKU rows. A department not listed here is flagged for manual review
    | rather than guessed at.
    |
    | Matching is case-insensitive (see ProductRequestSheetSyncService::
    | departmentConfigFor) since the sheet is hand-typed and inconsistently
    | cased row to row — keys below just need to match the letters, not the case.
    */
    'department_map' => [
        'F&B'                  => ['category' => 'Food & Beverages',    'sheet' => 'Food & Beverages'],
        'Perfumes & Cosmetics' => ['category' => 'Beauty',              'sheet' => 'Perfumes & Cosmetics'],
        'Travel'               => ['category' => 'Luggage',             'sheet' => 'Luggage'],
        // Some rows put the website in the Department column. Travel is what it
        // means, and correcting it here beats leaving the row unsynced.
        'MOSAFER'              => ['category' => 'Luggage',             'sheet' => 'Luggage'],
        'KIDS FASHION'         => ['category' => 'Kids',                'sheet' => 'Kids Fashion'],
        'LINGERIE'             => ['category' => 'Lingerie',            'sheet' => 'Lingerie'],
        'MENS'                 => ['category' => "Men's Fashion",       'sheet' => 'Mens Fashion'],
        'WOMEN FASHION'        => ['category' => "Women's Fashion",     'sheet' => 'Womens Fashion'],
        'LEATHER GOODS'        => ['category' => 'Leather Goods',       'sheet' => 'Leather Goods'],
        // The same department, typed short on some rows.
        'LEATHER'              => ['category' => 'Leather Goods',       'sheet' => 'Leather Goods'],
        'WATCHES & JEWELLERY'  => ['category' => 'Watches & Jewellery', 'sheet' => 'Watches & Jewellery'],
        'FASHION ACCESSORIES'  => ['category' => 'Fashion Accessories', 'sheet' => 'Fashion Accessories'],
        'Home'                 => ['category' => 'Home',                'sheet' => 'Home'],
        'Linen'                => ['category' => 'Linen',               'sheet' => 'Linen'],
    ],

];
