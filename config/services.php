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

];
