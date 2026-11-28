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

    // AI help for program imports ("Ask AI to fix" on the import review page). OpenAI-compatible, OpenRouter by
    // default. The key stays on the server; the browser only calls /api/v1/programs/imports/{id}/ai-fix.
    'import_ai' => [
        'base_url' => env('IMPORT_AI_BASE_URL', 'https://openrouter.ai/api/v1'),
        'api_key' => env('IMPORT_AI_API_KEY', env('OPENROUTER_API_KEY')),
        'model' => env('IMPORT_AI_MODEL', 'openai/gpt-4o-mini'),
        'supports_json_schema' => env('IMPORT_AI_JSON_SCHEMA', true),
        'supports_json_mode' => true,
        'max_output_tokens' => (int) env('IMPORT_AI_MAX_OUTPUT', 3000),
    ],

    // Amari, the in-app assistant. The key stays on the server; the browser only ever calls /api/v1/amari/chat.
    'amari' => [
        'key' => env('AMARI_AI_API_KEY'),
        'url' => env('AMARI_AI_URL', 'https://api.groq.com/openai/v1/chat/completions'),
        'model' => env('AMARI_AI_MODEL', 'openai/gpt-oss-120b'),
        // Public (signed-out) chat only answers after the prompt check passes; it also needs its own answer model.
        'public_model' => env('AMARI_AI_PUBLIC_MODEL', env('AMARI_AI_MODEL', 'openai/gpt-oss-120b')),
        'guard_model' => env('AMARI_AI_GUARD_MODEL', 'meta-llama/llama-prompt-guard-2-86m'),
        'rate_per_minute' => (int) env('AMARI_RATE_PER_MIN', 20),
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

   // LIPR payments. Read from .env; without it the SANDBOX is used, so a local or test backend never moves real money.
   // Production sets LIPR_BASE_PATH=https://api.prod.lipr.io and its live LIPR_API_KEY / LIPR_API_SECRET /
   // LIPR_SUBSCRIPTION_KEY in the server's .env (live secrets are not kept in the code).
    // Image moderation for uploads (SpamImageChecker). Set the keys in .env; the fallbacks keep the current account working
    'sightengine' => [
        'enabled' => env('SIGHTENGINE_ENABLED', true),
        'api_user' => env('SIGHTENGINE_API_USER', '725115685'),
        'api_secret' => env('SIGHTENGINE_API_SECRET', 'AgH2RUSa3FPoq2HexU9b32XqFvQhtSpa'),
    ],

   'lipr' => [
       'base_path' => env('LIPR_BASE_PATH', 'https://api.sandbox.lipr.io'),
       'api_key' => env('LIPR_API_KEY', 'api_test_3f30c671f57d4f2f9eaa65971f7e8f48'),
       'api_secret' => env('LIPR_API_SECRET', 'mdsxMNy69bCOCTBHpgpaN3VZxmjuPPLKhbOJ4-i-_BcQ6Y6eig14XL9TkoflUbbI'),
       'subscription_key' => env('LIPR_SUBSCRIPTION_KEY', 'a7e4372b-6926-405c-ac05-f25f74256f90'),
       // ISSUE-48: callback URLs are signed with this (default: APP_KEY); set LIPR_VERIFY_CALLBACKS=false only to let
       // transfers started before the deploy (unsigned URLs) finish, then switch it back on
       'callback_secret' => env('LIPR_CALLBACK_SECRET'),
       'verify_callbacks' => env('LIPR_VERIFY_CALLBACKS', true),
   ],

    // sandbox version
    // 'lipr' => [
    //     'base_path' => 'https://api.sandbox.lipr.io',
    //     'api_key' => 'api_test_3f30c671f57d4f2f9eaa65971f7e8f48',
    //     'api_secret' => 'mdsxMNy69bCOCTBHpgpaN3VZxmjuPPLKhbOJ4-i-_BcQ6Y6eig14XL9TkoflUbbI',
    //     'subscription_key' => 'a7e4372b-6926-405c-ac05-f25f74256f90'
    // ],
    
    'pusher' => [
        'app_id' => 'key.tujitume_e2f45h7',
    ],

];
