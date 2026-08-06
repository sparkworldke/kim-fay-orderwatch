<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173',  // Vite dev server
        'http://localhost:3000',
        'http://localhost:8080',  // TanStack Start dev server
        'http://localhost',
        'http://kim-fay-orderwatch.test',
        'http://kim-fay-orderwatch.test:8080',
        // Production frontend
        'https://sight.fayshop.co.ke',
        'https://orderwatch.fayshop.co.ke', // legacy — redirects to sight
        // Staging frontend
        'https://staging.sight.fayshop.co.ke',
        // Cloudflare workers.dev fallbacks
        'https://orderwatchkimfay.nairobidental.workers.dev',
        'https://orderwatchkimfay.sharedsys.workers.dev',
        'https://sight-staging.nairobidental.workers.dev',
        'https://sight-staging.sharedsys.workers.dev',
        // API host (if browser ever hits it directly)
        'https://datacontroller.fayshop.co.ke',
        // Dev / other
        'https://kim-fay-orderwatch.tools',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => true,

];
