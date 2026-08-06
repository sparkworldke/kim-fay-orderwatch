<?php

return [

    'acumatica' => [
        'sales_invoice_detail_filter_supported' => env(
            'ACUMATICA_SALES_INVOICE_DETAIL_FILTER_SUPPORTED',
            false,
        ),
    ],

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
            'Sight/1.0 (Kim-Fay Sight; +https://sight.fayshop.co.ke)',
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp OTP (Meta Cloud API)
    |--------------------------------------------------------------------------
    | driver=log    → local/testing (message logged, not sent)
    | driver=meta   → production Graph API send
    | Optional otp_template for business-initiated authentication templates.
    */
    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER', 'log'),
        'token' => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        'otp_template' => env('WHATSAPP_OTP_TEMPLATE'),
        'otp_template_language' => env('WHATSAPP_OTP_TEMPLATE_LANGUAGE', 'en'),
    ],

];
