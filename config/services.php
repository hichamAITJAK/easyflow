<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
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
        'client_id' => env('SHOPIFY_CLIENT_ID'),
        'client_secret' => env('SHOPIFY_CLIENT_SECRET'),
        // Keep in sync with [access_scopes] in shopify.app.toml — the CLI
        // can't read env vars, so the same list lives in both places.
        'scopes' => explode(',', (string) env('SHOPIFY_SCOPES', 'read_orders,read_products,read_customers')),

        // Password for the accounts ShopifyReviewerSeeder creates for app
        // review. Set only on the environment being submitted; null elsewhere
        // makes the seeder refuse to run.
        'review_password' => env('SHOPIFY_REVIEW_PASSWORD'),
    ],

    'youcan' => [
        'client_id' => env('YOUCAN_CLIENT_ID'),
        'client_secret' => env('YOUCAN_CLIENT_SECRET'),
        'redirect_uri' => env('YOUCAN_REDIRECT_URI'),
        'scopes' => explode('+', (string) env('YOUCAN_SCOPES', '*')),
    ],

    'lightfunnels' => [
        'client_id' => env('LIGHTFUNNELS_CLIENT_ID'),
        'client_secret' => env('LIGHTFUNNELS_CLIENT_SECRET'),
        'account_id' => env('LIGHTFUNNELS_ACCOUNT_ID'),
        'scopes' => explode(',', (string) env('LIGHTFUNNELS_SCOPES', 'products,orders')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Delivery Courier Services
    |--------------------------------------------------------------------------
    */
    'ozon_express' => [
        'client_id' => env('OZON_EXPRESS_CLIENT_ID'),
        'client_secret' => env('OZON_EXPRESS_CLIENT_SECRET'),
    ],

    'sendit' => [
        'public_key' => env('SENDIT_PUBLIC_KEY'),
        'secret_key' => env('SENDIT_SECRET_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | ForceLog
    |--------------------------------------------------------------------------
    |
    | A developer API key used only to sync ForceLog's courier-wide city
    | list (see SyncCourierCitiesCommand). Tenant shipments authenticate
    | with each delivery account's own key instead — this one is never used
    | to create parcels.
    |
    */
    'forcelog' => [
        'api_key' => env('FORCELOG_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Push Notifications (Expo)
    |--------------------------------------------------------------------------
    |
    | access_token is only needed once a project enables Expo's enhanced
    | push security; unset otherwise. `enabled` exists so local and CI
    | environments can hard-off real sends without every caller branching.
    |
    */
    'expo' => [
        'enabled' => (bool) env('EXPO_PUSH_ENABLED', true),
        'endpoint' => env('EXPO_PUSH_ENDPOINT', 'https://exp.host/--/api/v2/push/send'),
        'timeout' => (int) env('EXPO_PUSH_TIMEOUT', 10),
        'access_token' => env('EXPO_ACCESS_TOKEN'),
    ],

];
