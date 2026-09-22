<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'shopify' => [
        'domain'       => env('SHOPIFY_DOMAIN'),
        'access_token' => env('SHOPIFY_ACCESS_TOKEN'),
    ],

    'onedrive' => [
        'tenant_id'     => env('ONEDRIVE_TENANT_ID'),
        'client_id'     => env('ONEDRIVE_CLIENT_ID'),
        'client_secret' => env('ONEDRIVE_CLIENT_SECRET'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),

        /*
         * Requests per minute to pace ourselves to. Speed costs nothing — Gemini
         * bills per token, not per request — so this exists only to stay inside
         * the rate limit.
         *
         *   10  = free tier for gemini-2.5-flash (billing not enabled)
         *   300 = paid tier 1 (billing enabled on the key's project)
         *
         * Set GEMINI_RPM=300 once billing is confirmed and generation gets several
         * times faster with no change to the bill.
         */
        'rpm' => (int) env('GEMINI_RPM', 10),
    ],

    'fanar' => [
        'api_key' => env('FANAR_API_KEY'),
    ],

    'photoroom' => [
        'api_key' => env('PHOTOROOM_API_KEY'),

        /*
         * Photoroom bills per image edited, and a OneDrive link pasted by
         * mistake can point at a folder holding thousands. A run refuses to
         * start above this rather than discovering the bill afterwards.
         *
         * The Plus plan allows 1,000 images a month. At the old ceiling of 300
         * a single mistaken run took a third of the month with it — and items
         * that need a mannequin erased spend two requests, so 300 images could
         * mean 600. 120 keeps any one run recoverable.
         */
        'max_images' => (int) env('PHOTOROOM_MAX_IMAGES', 120),

        /*
         * Images included in the plan each month. Only used to show how much of
         * the allowance a run would take before it is started.
         *
         * 2,000 is what the Plus plan actually carries, read off Photoroom's
         * own dashboard. Both numbers here were wrong until they were checked
         * against it side by side — this one said 3,000 — and a quota figure
         * that is only ever compared against itself will stay wrong quietly,
         * because nothing in this app can tell it is.
         */
        'monthly_quota' => (int) env('PHOTOROOM_MONTHLY_QUOTA', 2000),

        /*
         * Day of the month the allowance resets. Photoroom bills from the day
         * the plan started, not from the 1st, so a calendar month would report
         * the wrong figure for most of it.
         *
         * The 14th, read off Photoroom's own dashboard. It said the 18th here
         * for months, and that was not only a wrong date on the screen: the
         * usage figure beside it is counted from this app's own edits since
         * the last reset, so four days of them were being left out of the
         * count entirely. Checked against Photoroom the app reported 694
         * against their 1,069 — the window being four days short is most of
         * that gap.
         */
        'quota_resets_on' => (int) env('PHOTOROOM_QUOTA_RESETS_ON', 14),

        /*
         * Requests per minute to pace the whole worker fleet to. Photoroom's
         * ceiling is 60 images/minute and answers a 429 past it; the default
         * leaves headroom so several workers finishing at once cannot cross it.
         *
         * This counts requests, not images — mannequin removal spends a second
         * request on the items that need it, and both are paced.
         */
        'rpm' => (int) env('PHOTOROOM_RPM', 50),

        /*
         * Edited images have to sit on disk between editing and review, which
         * is the one part of this feature that grows on its own. Full-size
         * edits are dropped as soon as they reach Shopify; whatever is left is
         * swept after this many days. The disk has filled twice before.
         */
        'retention_days' => (int) env('PHOTOROOM_RETENTION_DAYS', 7),

        /*
         * How long a pushed image keeps its full-size file, so it can be sent
         * to Shopify again.
         *
         * The file used to be deleted the moment Shopify accepted it, which
         * made re-pushing impossible without paying to edit the image a second
         * time. Keeping it costs disk — it is the largest thing this feature
         * writes, and this server's disk has filled twice — so the window is
         * short and separate from the seven-day session retention.
         *
         * Two days covers "we pushed it to the wrong product" and "the buyer
         * looked at it the next morning", which is what re-pushing is for.
         */
        'repush_days' => (int) env('PHOTOROOM_REPUSH_DAYS', 2),

        /*
         * Log the instructions sent with each edit. Off by default — it is a
         * few hundred bytes per image and only useful while something is
         * behaving in a way nobody can explain from the result alone.
         */
        'log_requests' => (bool) env('PHOTOROOM_LOG_REQUESTS', false),
    ],

    /*
     * The cross-platform orders endpoint on the ecommerce server. One shared
     * token unlocks revenue for every storefront, so it is read here and sent
     * from this server — it must never reach the browser.
     */
    'orders_api' => [
        'url'   => env('ORDERS_API_URL', 'https://ecommerce.abuissa.com/ecombackend/dashboard_api/orders_summary.php'),
        'token' => env('ORDERS_API_TOKEN'),

        /*
         * The endpoint answers a six-year range in under a second, so anything
         * near this is the server having stopped answering rather than a slow
         * query. Two requests go out per page load — the range and the one
         * before it — and the page waits on both.
         */
        'timeout' => (int) env('ORDERS_API_TIMEOUT', 20),
    ],

    /*
     * Google Analytics, for the visitor and session figures Shopify does not
     * carry. One service-account key covers every property; which property a
     * website's traffic lives in is per-store and kept on the store itself.
     *
     * The key is a credential and stays on disk rather than in the database
     * or the environment — the path is configurable so a deploy can put it
     * wherever it keeps secrets, but it must never sit under public/.
     */
    'ga4' => [
        'credentials' => env('GA4_CREDENTIALS_PATH', storage_path('app/google/analytics.json')),
        'timeout'     => (int) env('GA4_TIMEOUT', 30),
    ],

];
