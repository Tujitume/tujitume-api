# AI review runbook

What the AI review feature needs to run, how to switch provider, and what to do when something is wrong.

## 1. Get a free key (Groq, the default)

1. Create an account at https://console.groq.com and create an API key.
2. Put it in `.env`:
   ```
   AI_REVIEW_ENABLED=true
   AI_REVIEW_PROVIDER=groq
   GROQ_API_KEY=gsk_...
   ```
3. Run `php artisan config:clear` (or `config:cache`), then `php artisan ai-review:ping`.

Free tiers are small and change. The limits in `config/ai_review.php` are conservative values this system enforces itself; check your provider's console for the real numbers and override them with env values (`GROQ_RPM`, `GROQ_TPM`, `GROQ_RPD`).

## 2. Switch provider (env only, no code change)

Every provider is an OpenAI-compatible chat endpoint. Change `AI_REVIEW_PROVIDER` and the provider's env values:

| Provider | Env | Notes (checked 2026-11-11; confirm in the provider's console) |
|---|---|---|
| `groq` (default) | `GROQ_API_KEY`, `GROQ_MODEL` | Default model `openai/gpt-oss-120b` (the older llama-3.3 id was retired; list your models at `https://api.groq.com/openai/v1/models`). Live-tested 2026-11-11 with schema-enforced JSON. Free tier; about 30 requests/min is commonly reported, daily and token limits vary by model. Data terms not confirmed: treated as `unknown` |
| `cerebras` | `CEREBRAS_API_KEY`, `CEREBRAS_MODEL` | Reports say the permanent free tier may have been replaced by one-time trial credit (about 5 requests/min). Base URL and terms not confirmed |
| `cloudflare` | `CLOUDFLARE_ACCOUNT_ID`, `CLOUDFLARE_API_TOKEN`, `CLOUDFLARE_AI_MODEL` | 10,000 neurons/day free, resets 00:00 UTC (https://developers.cloudflare.com/workers-ai/platform/pricing/). Compatibility of the OpenAI endpoint not verified |
| `mistral` | `MISTRAL_API_KEY`, `MISTRAL_MODEL` | Free (Experiment) plan data **may be used for training** (https://help.mistral.ai/en/articles/323757). Scale plan data is not; then set `MISTRAL_TRAINS_ON_DATA=false` |
| `gemini` | `GEMINI_API_KEY`, `GEMINI_MODEL` | Base URL `https://generativelanguage.googleapis.com/v1beta/openai`. Free-tier content may be used to improve Google products. Use for the scanned-document/vision fallback only |
| `openrouter` | `OPENROUTER_API_KEY`, `OPENROUTER_MODEL` | Aggregator; the model host decides data handling. Unverified |
| `ollama` | `OLLAMA_BASE_URL`, `OLLAMA_MODEL` | Self-hosted; nothing leaves your servers |
| `custom` | `AI_CUSTOM_BASE_URL`, `AI_CUSTOM_API_KEY`, `AI_CUSTOM_MODEL`, `AI_CUSTOM_TRAINS_ON_DATA`, `AI_CUSTOM_ALLOW_APPLICANT_DATA` | Paid providers, vLLM, LiteLLM... |

Add fallbacks with `AI_REVIEW_FALLBACKS=cerebras,mistral`. If the first provider is rate limited, over quota or down, the next one is used.

Prove a switch with:

```
php artisan ai-review:ping            # config check, then one tiny live call per configured provider
php artisan ai-review:ping --no-live  # config check only
php artisan ai-review:smoke {application_id}   # the whole pipeline on one application
```

`ai-review:ping` prints status, latency, model and the limits the system enforces. A provider marked `*` is in use.

## 3. Privacy

A provider can receive applicant text only if it is configured with `*_ALLOW_APPLICANT_DATA=true` and `*_TRAINS_ON_DATA=false`, or the program owner's organization has acknowledged it (`POST /programs/ai-privacy-ack`). Only locally extracted, redacted text is sent, never files. Identity and KYC documents are never read. Set `AI_REVIEW_EXTERNAL_CALLS_DISABLED=true` to run only the free automatic checks (no external call at all).

Human actions before going live: review the privacy policy and applicant terms with legal counsel; the applicant-facing disclosure text is in `ProgramRound::getAiReviewDisclosureAttribute()`.

## 4. Server requirements

* **Redis** for live rate counters: `AI_REVIEW_CACHE_STORE=redis`, `QUEUE_CONNECTION=redis` and a Redis server. The test suite runs on the array cache.
* **Queue worker** for the `ai-review` queue:
  ```
  php artisan queue:work --queue=ai-review,default --tries=0 --timeout=240
  ```
  Run it under Supervisor/systemd. With `QUEUE_CONNECTION=sync` reviews run inside the applicant's request: do not do that in production.
* **Scheduler** (already registered in `app/Console/Kernel.php`; needs the usual `* * * * * php artisan schedule:run`): `ai-reviews:sweep` hourly (retries, owner/reviewer notices, capacity watch) and `ai-reviews:purge-extractions` daily.
* **OCR (scanned PDFs and images):** install poppler and tesseract.
  ```
  # Debian/Ubuntu
  sudo apt-get install -y poppler-utils tesseract-ocr tesseract-ocr-eng tesseract-ocr-swa
  # macOS
  brew install poppler tesseract tesseract-lang
  ```
  Without them, text PDFs, DOCX, XLSX and PPTX still work (native text, `smalot/pdfparser`, ZipArchive). Scanned files are recorded as unreadable with a note that OCR is not installed; nothing fails. Set `AI_REVIEW_OCR_LANGS=eng+swa` to add Swahili.
* PHP extensions: `zip`, `dom`, `libxml`, `mbstring`.

## 5. When the free quota runs out

Nothing breaks and no application fails. The job releases itself and tries again after the provider's reset (or `Retry-After`). If the next provider in `AI_REVIEW_FALLBACKS` has capacity it is used. The sweep warns the program owner once when the waiting applications cannot be covered before the review period ends. The owner can then change provider or plan, or score manually with the normal endpoint.

An invalid key never retries forever: the review becomes `needs_attention` with only the automatic checks, and the owner is told once per day.

## 6. Moving to a paid provider or Ollama

1. Set the provider's env values (or `custom`) and raise its limits (`*_RPM`, `*_TPM`, `*_RPD`) to your plan.
2. Set `*_TRAINS_ON_DATA` and `*_ALLOW_APPLICANT_DATA` to what the contract says.
3. `php artisan config:clear && php artisan ai-review:ping`.
4. For Ollama: `ollama pull llama3.1:8b`, `OLLAMA_BASE_URL=http://host:11434/v1`. Small local models verify fewer quotes, so AI-only rounds will flag more applications for manual scoring.

## 7. Troubleshooting

| Symptom | Check |
|---|---|
| Publish says `ai_enabled` / `ai_provider` / `ai_privacy` | `AI_REVIEW_ENABLED`, the key/model env values, and the organization acknowledgment |
| Reviews stay `queued` | Queue worker running? `php artisan ai-review:ping`; the owner overview (`GET /programs/rounds/{round}/ai-reviews`) shows remaining capacity |
| Everything `needs_attention` | Provider key or outage (see `error` on the review), or AI-only quote verification below `AI_REVIEW_MIN_VERIFIED_RATIO` |
| Scanned files unreadable | Install poppler-utils and tesseract |
| Documents skipped | Over `AI_REVIEW_MAX_DOCUMENTS`, `AI_REVIEW_MAX_FILE_MB`, or an excluded identity type |

Logs contain ids, counts and status codes only. Prompts, completions and document text are never logged.
