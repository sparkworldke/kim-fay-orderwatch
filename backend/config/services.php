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

    'microsoft' => [
        'client_id'     => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'tenant_id'     => env('MICROSOFT_TENANT_ID', 'common'),
        'redirect_uri'  => env('MICROSOFT_REDIRECT_URI'),
        'frontend_url'  => env('FRONTEND_URL', env('MICROSOFT_FRONTEND_URL', 'http://localhost:5173')),
        'graph_user_agent' => env(
            'MICROSOFT_GRAPH_USER_AGENT',
            'OrderWatch/1.0 (Kim-Fay OrderWatch; +https://orderwatch.fayshop.co.ke)',
        ),
    ],

];
