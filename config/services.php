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

    'lipr' => [
        'base_path' => env('LIPR_BASE_PATH', 'https://api.sandbox.lipr.io'),
        'api_key' => env('LIPR_API_KEY'),
        'api_secret' => env('LIPR_API_SECRET'),
        'subscription_key' => env('LIPR_SUBSCRIPTION_KEY'),
    ],
    'pusher' => [
        'app_id' => env('PUSHER_APP_ID'),
    ],

    'sightengine' => [
        'api_user' => env('SIGHTENGINE_API_USER'),
        'api_secret' => env('SIGHTENGINE_API_SECRET'),
    ],

];
