<?php

return [
    // Off by default. Turning it on registers routes, migrations and policies of app/Modules/ProgramImport.
    'enabled' => (bool) env('PROGRAM_IMPORTS_ENABLED', false),

    'queue' => 'program-imports',
    'disk' => env('PROGRAM_IMPORTS_DISK', 'local'),     // must be a private disk (S3 in production)
    'retention_days' => (int) env('PROGRAM_IMPORTS_RETENTION_DAYS', 30),

    'limits' => [
        'max_file_kb' => 10240,
        'max_rows' => 5000,
        'max_columns' => 80,
        'ai_calls_per_batch' => 6,
    ],

    'extensions' => ['csv', 'xlsx', 'txt'],

    'ai' => [
        'enabled' => (bool) env('PROGRAM_IMPORTS_AI_ENABLED', true),
        // Platform kill switch. Real sample values are still sent only with the organization's own consent (settings) AND a passing privacy gate.
        'allow_real_samples' => (bool) env('PROGRAM_IMPORTS_AI_REAL_SAMPLES', true),
    ],

    'google' => [
        'hosts' => ['docs.google.com', 'forms.gle'],
        'max_redirects' => 3,
        'timeout' => 10,
        'max_bytes' => 2 * 1024 * 1024,
        'oauth_enabled' => (bool) env('PROGRAM_IMPORTS_GOOGLE_OAUTH', false),
    ],
];
