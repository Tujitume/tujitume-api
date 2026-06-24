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

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'laravel-websockets/auth', 'login-from-token'],

    'allowed_methods' => ['*'],

//    'allowed_origins' => [
//        'https://beta.tujitume.com',
//        'http://localhost:81', // for Vite/React
//        'http://localhost:3000'
//    ],
    'allowed_origins' => ['*'],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,
    'supports_credentials' => true,
];
