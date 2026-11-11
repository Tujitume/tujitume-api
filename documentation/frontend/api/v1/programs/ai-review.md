# AI-assisted and AI-only review

Each **round** chooses how its applications are reviewed. The choice is made while the round is a draft and is locked once the round is published.

All AI work runs on the backend in queued jobs. The frontend never calls an AI provider and never holds a key. Findings and scores are labelled `ai` or `human`; a human can always confirm, dismiss or override an AI finding, and the original AI text is kept.

## Review methods

| `review_method` | Who reviews | AI writes scores? | Reviewers, orders, fees |
|---|---|---|---|
| `manual` (default) | Business Coaches | No | As before |
| `ai_assisted` | Business Coaches, helped by an AI report | **No** (suggestions only; the coach scores through the existing endpoint) | As before |
| `ai_only` | AI scores; the owner finalizes | Yes: one `application_scores` row per application (`source = ai`) | None. The round is saved as `owner_only`; reviewer-fee checks and start-review are skipped |

AI never accepts, rejects, advances, awards or changes `status`. In `ai_only` it only writes its score and sets `round_status = scored`. A human score always takes precedence over an AI score in the average.

### Behaviour matrix

| | manual | ai_assisted | ai_only |
|---|---|---|---|
| Review job queued on submit/change | no | yes | yes |
| Report with findings and sources | no | yes | yes |
| `application_scores` row from AI | no | no | yes (`source=ai`, system user) |
| Reviewer notified "N reports ready" (max 1 / 15 min) | no | yes | no |
| Owner notified when round is scored | no | optional, when all reports are ready | **once**, after the review period |
| Reviewer assignment / orders / fee check | yes | yes | skipped |
| Method editable | – | draft only | draft only (needs `confirm_ai_only`) |

## Round fields

Send these through the existing round endpoints: `POST /programs/{program}/rounds` and `PATCH /programs/rounds/{round}`.

| Field | Required | Type | Notes |
|---|---|---|---|
| `review_method` | No | `manual` \| `ai_assisted` \| `ai_only` | Changing it after publish returns `422` on `review_method` |
| `confirm_ai_only` | When switching to `ai_only` | boolean | Must be `true`; the server records who and when (`ai_only_acknowledged_by/at`) |
| `ai_review_config` | No | object | See below. Owner-only: never returned to applicants |

```json
{
  "review_method": "ai_assisted",
  "ai_review_config": {
    "dimensions": ["business_viability","program_alignment","market_opportunity","operational_readiness","traction"],
    "priority_keywords": ["women-led","rural","agritech"],
    "desired_responses": [{"question_id": 12, "expected": "Describes a repeatable revenue model"}],
    "instructions": "Free-text guidance, max 2000 chars",
    "document_rules": {"required_types": ["business_plan","financials"], "max_documents": 8}
  }
}
```

| Key | Rule |
|---|---|
| `dimensions` | Subset of the five shown |
| `priority_keywords` | Up to 50 items, 60 characters each |
| `desired_responses[].question_id` | Must belong to the round (checked on update) |
| `instructions` | Up to 2000 characters |
| `document_rules.max_documents` | 1–20 |

The scoring rubric is the round's existing `scoring_criteria`. The AI scores exactly those criteria, with names matched exactly and scores clamped to each criterion's range.

`GET /programs/rounds/{round}` (owner) returns `review_method`, `ai_review_config` and the acknowledgment fields. Applicant-facing round data returns `review_method` and `ai_review_disclosure` only.

### Applicant disclosure

`ai_review_disclosure` is `null` for manual rounds. Otherwise it is text such as: *"This round uses AI-assisted review ... Your application content is processed by a third-party AI provider for this purpose."* Show it on the application form.

## Publish checklist (additions)

`POST /programs/rounds/{round}/publish` returns `422` with these extra keys for AI rounds:

| Key | Meaning |
|---|---|
| `ai_review_config` | No config, or no dimension chosen |
| `ai_enabled` | `AI_REVIEW_ENABLED` is off on the server |
| `ai_provider` | Provider URL, model or key not configured (no live call is made) |
| `ai_privacy` | Privacy gate not satisfied; call `POST /programs/ai-privacy-ack` |

## Privacy gate

AI review may use a provider only if the provider is configured with `allow_applicant_data=true` **and** `trains_on_data=false`, **or** the owner's organization has acknowledged that provider.

`POST /api/v1/programs/ai-privacy-ack` body `{ "confirm": true }`. Organization owner, admin or editor only. Records user, time and provider; a different provider requires a new acknowledgment.

## Endpoints

Owner = program owner or their team. Reviewer = reviewer assigned to that application. Applicants never reach these.

| Method | Path | Who | Notes |
|---|---|---|---|
| `POST` | `/programs/ai-privacy-ack` | Org owner/admin/editor | See above |
| `GET` | `/programs/rounds/{round}/ai-reviews` | Owner | Counts by status, per-application status, provider/model, capacity, estimated completion, notified flags |
| `GET` | `/programs/applications/{application}/ai-review` | Owner, assigned reviewer | The report. `404` if the round is manual |
| `POST` | `/programs/applications/{application}/ai-review/rerun` | Owner | Throttled (6/min). `202`. `422` if the round is manual, finalized or AI is off |
| `POST` | `/programs/applications/{application}/ai-review/findings` | Owner, reviewer | Adds a **human** finding: `dimension`, `polarity`, `title`, `body` |
| `PATCH` | `/programs/ai-findings/{finding}` | Owner, reviewer | `action`: `confirm` \| `dismiss` \| `override`. Override needs `body` and `reviewer_note`; the first AI text stays in `original_body` |

### Report JSON

```json
{
  "data": {
    "id": 4, "status": "ready", "mode": "ai_assisted", "origin": "ai",
    "provider": "groq", "model": "openai/gpt-oss-120b", "prompt_version": "v1",
    "summary": "A women-led processor with clear growth.",
    "suggested_scores": [{"criterion": "Impact", "score": 8, "max": 10, "rationale": "...", "origin": "ai"}],
    "suggested_total": 76,
    "deterministic_checks": [{"check": "required_document", "status": "fail", "detail": "Missing document: financials"}],
    "missing_items": ["Required document missing: financials"],
    "extraction_notes": {"notes": ["Only the first 60 of 80 pages were read."], "documents": [{"label": "Plan", "status": "ok", "method": "native_pdf", "pages": 12}], "issues": []},
    "quality": {"verified_quote_ratio": 1, "cited_findings": 3, "verified_findings": 3, "schema_repairs": 0, "redactions": 4},
    "findings": [{
      "id": 31, "origin": "ai", "dimension": "business_viability", "polarity": "strength", "title": "Revenue growth",
      "body": "Revenue grew by 40%.", "confidence": "high", "status": "open",
      "quote": "women-led maize processing company", "quote_verified": true,
      "source": {"type": "answer", "id": 55, "question_id": 12, "label": "Describe your business", "page": null, "url": null},
      "original_body": null, "reviewed_by": null, "reviewer_note": null
    }]
  }
}
```

* `origin` is `ai` or `human` on the report, every suggested score and every finding. Show it.
* `quote_verified: false` means the quote was **not found** in the source text. Show those findings with a warning and do not count them as evidence.
* `source.type` is `answer` or `document`. For a document, `source.url` is a signed, temporary link (about 45 minutes) and `source.page` is the page. For an answer, use `source.question_id` to scroll to the answer.
* `dimension`: the five dimensions plus `data_quality`. `polarity`: `strength`, `risk`, `gap`, `inconsistency`, `info`.
* Extracted document text is never returned.

### Status machine

`queued -> extracting -> analyzing -> ready`. Also `failed` (gave up after retries), `needs_attention` (no provider, bad key, weak evidence in AI-only), and `stale` (the application changed; a new review replaces it). Quota or rate limits never fail a review: it stays `queued` and waits.

## AI-only consent

Choosing `ai_only` needs `confirm_ai_only: true`. The UI should show: "AI will score and recommend applications without a Business Coach, and I am responsible for the decisions I make from its results." AI refuses to score (`needs_attention`) when fewer than 60% of its quotes verify, when a criterion is missing, or when the report needed repeated corrections. Those applications are left for the owner to score with `POST /programs/applications/{application}/scores`.

## Notifications

| Event | To | When |
|---|---|---|
| `ai_review.reports_ready` | Reviewer (and the owner once, when every report is ready) | AI-assisted; at most one per reviewer per 15 minutes |
| `ai_review.round_scored` | Owner | AI-only, once, after the review period, when every application is scored |
| `ai_review.round_attention` | Owner | Applications that could not be scored, provider capacity shortfall, or an invalid provider key (once per day) |

No applicant ever gets an AI notification, and the system AI user (`Tujitume AI Reviewer`) never receives any.

## Errors

| Status | When | Show |
|---|---|---|
| `403` | Not the owner or an assigned reviewer; applicant; other organization | "You don't have access" |
| `404` | Round does not use AI review | Hide the AI tab |
| `422` | Method locked after publish; `confirm_ai_only` missing; config invalid; round finalized; override without note | Field errors |
| `202` | Rerun queued | "AI analysis started" |
