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
         */
        'max_images' => (int) env('PHOTOROOM_MAX_IMAGES', 300),

        /*
         * Edited images have to sit on disk between editing and review, which
         * is the one part of this feature that grows on its own. Full-size
         * edits are dropped as soon as they reach Shopify; whatever is left is
         * swept after this many days. The disk has filled twice before.
         */
        'retention_days' => (int) env('PHOTOROOM_RETENTION_DAYS', 7),
    ],

];
