<?php

/*
|--------------------------------------------------------------------------
| AI-assisted / AI-only application review
|--------------------------------------------------------------------------
| Every provider is an OpenAI-compatible chat endpoint, so changing provider, URL, key or model is an .env change.
| Values below were checked against public pages on 2026-11-11, but free-tier limits, model IDs and data terms change
| often and the sources disagree. Treat every limit as a conservative default that THIS system enforces; confirm the
| numbers in each provider's console before relying on them. Anything not confirmed is trains_on_data = 'unknown'.
*/

$list = fn (string $key, string $default = '') => array_values(array_filter(array_map('trim', explode(',', (string) env($key, $default)))));

return [
    'enabled' => env('AI_REVIEW_ENABLED', false),
    'default_provider' => env('AI_REVIEW_PROVIDER', 'groq'),
    'fallbacks' => $list('AI_REVIEW_FALLBACKS', ''),
    'prompt_version' => env('AI_REVIEW_PROMPT_VERSION', 'v1'),
    'queue' => env('AI_REVIEW_QUEUE', 'ai-review'),
    // Cache store holding the live rate counters (null = the app default; use 'redis' in production).
    'cache_store' => env('AI_REVIEW_CACHE_STORE'),

    // When true nothing leaves the server: only the deterministic checks run (self-hosted-only setups).
    'external_calls_disabled' => env('AI_REVIEW_EXTERNAL_CALLS_DISABLED', false),

    'system_user_email' => env('AI_REVIEW_SYSTEM_USER_EMAIL', 'ai-reviewer@system.tujitume.com'),

    'max_documents_per_application' => (int) env('AI_REVIEW_MAX_DOCUMENTS', 8),
    'max_file_mb' => (int) env('AI_REVIEW_MAX_FILE_MB', 20),
    'max_pages_per_document' => (int) env('AI_REVIEW_MAX_PAGES', 60),
    'max_answer_chars' => (int) env('AI_REVIEW_MAX_ANSWER_CHARS', 4000),
    'max_sheet_rows' => (int) env('AI_REVIEW_MAX_SHEET_ROWS', 200),
    'extraction_retention_days' => (int) env('AI_REVIEW_RETENTION_DAYS', 90),
    'redact_pii' => env('AI_REVIEW_REDACT_PII', true),

    // Never read, never sent: identity / KYC style documents.
    'excluded_document_types' => $list('AI_REVIEW_EXCLUDED_TYPES', 'id,national_id,passport,kyc,tax_pin,identity,id_document'),

    // Local OCR (poppler + tesseract). Missing binaries degrade gracefully.
    'ocr' => [
        'enabled' => env('AI_REVIEW_OCR', true),
        'pdftotext' => env('AI_REVIEW_PDFTOTEXT', 'pdftotext'),
        'pdftoppm' => env('AI_REVIEW_PDFTOPPM', 'pdftoppm'),
        'tesseract' => env('AI_REVIEW_TESSERACT', 'tesseract'),
        'languages' => env('AI_REVIEW_OCR_LANGS', 'eng'),
        'timeout' => (int) env('AI_REVIEW_OCR_TIMEOUT', 120),
    ],

    // Send a page image to the provider's vision model when OCR returns nothing. Off by default; privacy gate applies.
    'vision_fallback' => env('AI_REVIEW_VISION_FALLBACK', false),

    'ai_only' => [
        'min_verified_ratio' => (float) env('AI_REVIEW_MIN_VERIFIED_RATIO', 0.6),
    ],

    'timeouts' => ['connect' => 10, 'request' => (int) env('AI_REVIEW_TIMEOUT', 90)],
    'retries' => (int) env('AI_REVIEW_RETRIES', 3),
    'backoff_seconds' => [30, 120, 600],
    'provider_cooldown_seconds' => (int) env('AI_REVIEW_COOLDOWN', 300),

    // Reports-ready notifications are batched: at most one per reviewer per this many minutes.
    'notify_batch_minutes' => 15,
    'rerun_throttle_per_minute' => 6,

    'providers' => [
        // https://console.groq.com/docs/rate-limits  (checked 2026-11-11; third-party numbers disagree: 30 rpm is common,
        // rpd 1,000-14,400, tpm 6,000-12,000 depending on model). Defaults below are deliberately conservative.
        'groq' => [
            'driver' => 'openai_compatible',
            'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
            'api_key' => env('GROQ_API_KEY'),
            'model' => env('GROQ_MODEL', 'openai/gpt-oss-120b'),
            'vision_model' => env('GROQ_VISION_MODEL'),
            // Extra request fields. gpt-oss models reason before answering; low effort keeps the token cost down.
            'extra' => array_filter(['reasoning_effort' => env('GROQ_REASONING_EFFORT', 'low')]),
            'supports_json_schema' => env('GROQ_JSON_SCHEMA', true), // schema-enforced output works on the gpt-oss models (live-tested 2026-11-11); models are listed at /openai/v1/models
            'supports_json_mode' => env('GROQ_JSON_MODE', true),
            'context_tokens' => (int) env('GROQ_CONTEXT_TOKENS', 128000),
            'max_output_tokens' => (int) env('GROQ_MAX_OUTPUT', 4096),
            'limits' => ['rpm' => (int) env('GROQ_RPM', 20), 'tpm' => (int) env('GROQ_TPM', 6000), 'rpd' => (int) env('GROQ_RPD', 900), 'reset_tz' => 'UTC'],
            'trains_on_data' => env('GROQ_TRAINS_ON_DATA', 'unknown'),
            'allow_applicant_data' => env('GROQ_ALLOW_APPLICANT_DATA', false),
            'notes' => 'Verify terms at https://groq.com/privacy-policy (2026-11-11: not confirmed from an official page).',
        ],
        // https://inference-docs.cerebras.ai/support/rate-limits  (2026-11-11: a permanent free tier may no longer exist;
        // reports say one-time trial credit, ~5 rpm). Base URL not confirmed from official docs in this review.
        'cerebras' => [
            'driver' => 'openai_compatible',
            'base_url' => env('CEREBRAS_BASE_URL', 'https://api.cerebras.ai/v1'),
            'api_key' => env('CEREBRAS_API_KEY'),
            'model' => env('CEREBRAS_MODEL', 'gpt-oss-120b'),
            'vision_model' => null,
            'supports_json_schema' => env('CEREBRAS_JSON_SCHEMA', false),
            'supports_json_mode' => true,
            'context_tokens' => (int) env('CEREBRAS_CONTEXT_TOKENS', 64000),
            'max_output_tokens' => (int) env('CEREBRAS_MAX_OUTPUT', 4096),
            'limits' => ['rpm' => (int) env('CEREBRAS_RPM', 5), 'tpm' => (int) env('CEREBRAS_TPM', 30000), 'rpd' => (int) env('CEREBRAS_RPD', 200), 'reset_tz' => 'UTC'],
            'trains_on_data' => env('CEREBRAS_TRAINS_ON_DATA', 'unknown'),
            'allow_applicant_data' => env('CEREBRAS_ALLOW_APPLICANT_DATA', false),
            'notes' => 'Unverified: base URL, model ID, limits and data terms. Check https://inference-docs.cerebras.ai',
        ],
        // Workers AI OpenAI-compatible endpoint: https://api.cloudflare.com/client/v4/accounts/{account_id}/ai/v1
        // Free allowance: 10,000 neurons/day, resets 00:00 UTC (https://developers.cloudflare.com/workers-ai/platform/pricing/, 2026-11-11).
        'cloudflare' => [
            'driver' => 'openai_compatible',
            'base_url' => env('CLOUDFLARE_AI_BASE_URL', 'https://api.cloudflare.com/client/v4/accounts/'.env('CLOUDFLARE_ACCOUNT_ID', 'ACCOUNT_ID').'/ai/v1'),
            'api_key' => env('CLOUDFLARE_API_TOKEN'),
            'model' => env('CLOUDFLARE_AI_MODEL', '@cf/meta/llama-3.3-70b-instruct-fp8-fast'),
            'vision_model' => null,
            'supports_json_schema' => false,
            'supports_json_mode' => true,
            'context_tokens' => (int) env('CLOUDFLARE_CONTEXT_TOKENS', 24000),
            'max_output_tokens' => (int) env('CLOUDFLARE_MAX_OUTPUT', 2048),
            'limits' => ['rpm' => (int) env('CLOUDFLARE_RPM', 30), 'tpm' => (int) env('CLOUDFLARE_TPM', 20000), 'rpd' => (int) env('CLOUDFLARE_RPD', 40), 'reset_tz' => 'UTC'],
            'trains_on_data' => env('CLOUDFLARE_TRAINS_ON_DATA', 'unknown'),
            'allow_applicant_data' => env('CLOUDFLARE_ALLOW_APPLICANT_DATA', false),
            'notes' => 'Unverified: OpenAI-compatible endpoint behaviour and per-model neuron cost. Daily cap is in neurons, so rpd here is a rough request cap.',
        ],
        // https://help.mistral.ai/en/articles/323757 : Experiment-plan API data MAY be used for training (opt-out available);
        // Scale plan data is not (2026-11-11). Limits: see the admin console; ~1 rps is widely reported.
        'mistral' => [
            'driver' => 'openai_compatible',
            'base_url' => env('MISTRAL_BASE_URL', 'https://api.mistral.ai/v1'),
            'api_key' => env('MISTRAL_API_KEY'),
            'model' => env('MISTRAL_MODEL', 'mistral-small-latest'),
            'vision_model' => env('MISTRAL_VISION_MODEL'),
            'supports_json_schema' => env('MISTRAL_JSON_SCHEMA', true),
            'supports_json_mode' => true,
            'context_tokens' => (int) env('MISTRAL_CONTEXT_TOKENS', 32000),
            'max_output_tokens' => (int) env('MISTRAL_MAX_OUTPUT', 4096),
            'limits' => ['rpm' => (int) env('MISTRAL_RPM', 30), 'tpm' => (int) env('MISTRAL_TPM', 100000), 'rpd' => (int) env('MISTRAL_RPD', 2000), 'reset_tz' => 'UTC'],
            'trains_on_data' => env('MISTRAL_TRAINS_ON_DATA', true),
            'allow_applicant_data' => env('MISTRAL_ALLOW_APPLICANT_DATA', false),
            'notes' => 'Free (Experiment) plan may train on data. Set MISTRAL_TRAINS_ON_DATA=false only on the Scale plan.',
        ],
        // https://ai.google.dev/gemini-api/docs/openai : base URL below (consistent across sources, 2026-11-11).
        // Free-tier content may be used to improve Google products. Scanned-document / vision fallback only.
        'gemini' => [
            'driver' => 'openai_compatible',
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta/openai'),
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
            'vision_model' => env('GEMINI_VISION_MODEL', 'gemini-2.5-flash'),
            'supports_json_schema' => false,
            'supports_json_mode' => true,
            'context_tokens' => (int) env('GEMINI_CONTEXT_TOKENS', 128000),
            'max_output_tokens' => (int) env('GEMINI_MAX_OUTPUT', 4096),
            'limits' => ['rpm' => (int) env('GEMINI_RPM', 10), 'tpm' => (int) env('GEMINI_TPM', 200000), 'rpd' => (int) env('GEMINI_RPD', 240), 'reset_tz' => 'America/Los_Angeles'],
            'trains_on_data' => env('GEMINI_TRAINS_ON_DATA', true),
            'allow_applicant_data' => env('GEMINI_ALLOW_APPLICANT_DATA', false),
            'notes' => 'Limits differ by project; read them in AI Studio. Free tier data may be used to improve products.',
        ],
        // https://openrouter.ai/docs : aggregator; the underlying model's host decides data handling (unverified).
        'openrouter' => [
            'driver' => 'openai_compatible',
            'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            'api_key' => env('OPENROUTER_API_KEY'),
            'model' => env('OPENROUTER_MODEL', 'meta-llama/llama-3.3-70b-instruct:free'),
            'vision_model' => env('OPENROUTER_VISION_MODEL'),
            'supports_json_schema' => false,
            'supports_json_mode' => true,
            'context_tokens' => (int) env('OPENROUTER_CONTEXT_TOKENS', 64000),
            'max_output_tokens' => (int) env('OPENROUTER_MAX_OUTPUT', 4096),
            'limits' => ['rpm' => (int) env('OPENROUTER_RPM', 20), 'tpm' => (int) env('OPENROUTER_TPM', 40000), 'rpd' => (int) env('OPENROUTER_RPD', 50), 'reset_tz' => 'UTC'],
            'trains_on_data' => env('OPENROUTER_TRAINS_ON_DATA', 'unknown'),
            'allow_applicant_data' => env('OPENROUTER_ALLOW_APPLICANT_DATA', false),
            'notes' => 'Unverified: free-model limits and the data policy of the model host.',
        ],
        // Self-hosted. Nothing leaves your infrastructure.
        'ollama' => [
            'driver' => 'openai_compatible',
            'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434/v1'),
            'api_key' => env('OLLAMA_API_KEY', 'ollama'),
            'model' => env('OLLAMA_MODEL', 'llama3.1:8b'),
            'vision_model' => env('OLLAMA_VISION_MODEL'),
            'supports_json_schema' => false,
            'supports_json_mode' => true,
            'context_tokens' => (int) env('OLLAMA_CONTEXT_TOKENS', 8000),
            'max_output_tokens' => (int) env('OLLAMA_MAX_OUTPUT', 2048),
            'limits' => ['rpm' => (int) env('OLLAMA_RPM', 600), 'tpm' => (int) env('OLLAMA_TPM', 10000000), 'rpd' => (int) env('OLLAMA_RPD', 1000000), 'reset_tz' => 'UTC'],
            'trains_on_data' => false,
            'allow_applicant_data' => true,
            'notes' => 'Self-hosted; limits are only a safety net.',
        ],
        // Any other OpenAI-compatible endpoint (paid provider, vLLM, LiteLLM ...).
        'custom' => [
            'driver' => 'openai_compatible',
            'base_url' => env('AI_CUSTOM_BASE_URL'),
            'api_key' => env('AI_CUSTOM_API_KEY'),
            'model' => env('AI_CUSTOM_MODEL'),
            'vision_model' => env('AI_CUSTOM_VISION_MODEL'),
            'supports_json_schema' => env('AI_CUSTOM_JSON_SCHEMA', false),
            'supports_json_mode' => env('AI_CUSTOM_JSON_MODE', true),
            'context_tokens' => (int) env('AI_CUSTOM_CONTEXT_TOKENS', 32000),
            'max_output_tokens' => (int) env('AI_CUSTOM_MAX_OUTPUT', 4096),
            'limits' => ['rpm' => (int) env('AI_CUSTOM_RPM', 60), 'tpm' => (int) env('AI_CUSTOM_TPM', 200000), 'rpd' => (int) env('AI_CUSTOM_RPD', 10000), 'reset_tz' => env('AI_CUSTOM_RESET_TZ', 'UTC')],
            'trains_on_data' => env('AI_CUSTOM_TRAINS_ON_DATA', 'unknown'),
            'allow_applicant_data' => env('AI_CUSTOM_ALLOW_APPLICANT_DATA', false),
            'notes' => 'Your own provider. Set trains_on_data / allow_applicant_data to what its contract says.',
        ],
    ],
];
