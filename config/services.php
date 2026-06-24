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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'recaptcha' => [
        'key' => env('GOOGLE_RECAPTCHA_KEY'),
        'secret' => env('GOOGLE_RECAPTCHA_SECRET'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        //'redirect' => config('app.api_url').'facebook/callback',
        'redirect' => env('FB_RDR'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_RDR'),
    ],

     'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'publishable' => env('STRIPE_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

//    'lipr' => [
//        'base_path' => 'https://api.prod.lipr.io',
//        'api_key' => 'api_live_d9c7bbc38f4a412f88bc8f19288226b9', //'a52b00aa0de1cc4fc742876b92e480e9',
//        'api_secret' => 'jNJdccQfb0oVC2pl8BfOynFVc-KoQWreBHtvHDiXpc-eKCPN7EVNf8dYwVcj3jnh', //'9c87f7c6d71312d89a86473eeefec46f',
//        'subscription_key' => 'bb41577b-29c5-4ed9-9ec6-0220a82b7e91'
//    ],

    // sandbox version
    'lipr' => [
        'base_path' => 'https://api.sandbox.lipr.io',
        'api_key' => 'api_test_3f30c671f57d4f2f9eaa65971f7e8f48',
        'api_secret' => 'mdsxMNy69bCOCTBHpgpaN3VZxmjuPPLKhbOJ4-i-_BcQ6Y6eig14XL9TkoflUbbI',
        'subscription_key' => 'a7e4372b-6926-405c-ac05-f25f74256f90'
    ],
    'pusher' => [
        'app_id' => 'key.tujitume_e2f45h7',
    ],

];
