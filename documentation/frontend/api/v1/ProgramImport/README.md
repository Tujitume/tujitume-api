# Program Import API

Base path `/api/v1/program-imports`. Auth: `auth:sanctum`. Allowed: the organization's owner and team members with admin/editor roles (viewers may read). Another organization's batch is a 404. When the module is disabled these routes do not exist (404).

Envelope: `{ "success": bool, "message": string, "data": any }`; errors `{ "success": false, "message": string, "errors": { "code": string, ...extra } }`.

## Wizard flow
1. `GET start-points`, `GET types`, `GET templates/{name}?format=xlsx|csv`
2. `POST /` (multipart) create a batch. File/text sources are parsed by a job; Google Form / pasted form questions are read immediately (`data.form_spec`).
3. Poll `GET {batch}` until `status = needs_mapping`; `POST {batch}/analyze` (202) then poll until `mapped`.
4. `PATCH {batch}/mapping` (or `PATCH {batch}/form-spec` for forms) then `POST {batch}/validate` (202), poll until `ready` or `has_errors`.
5. `POST {batch}/readiness` returns blockers, warnings, landing preview, skipped, next steps. Fix rows with `PATCH {batch}/rows/{row}`; `GET {batch}/errors` downloads a CSV.
6. `POST {batch}/commit` with `confirm_data_rights=true` (202); poll until `completed` / `completed_with_errors` / `failed`.
7. Optional: `POST programs/{program}/invitations`, `POST {batch}/rollback`, `DELETE {batch}` (only before commit).

Batch status: `uploaded -> parsing -> needs_mapping -> mapped -> validating -> ready | has_errors -> importing -> completed | completed_with_errors | failed`; also `cancelled`, `rolled_back`.

## Endpoints
| Method | Path | Notes |
|---|---|---|
| GET | `start-points` | key, label, required/optional sheets, summary, next_steps, landing, skipped |
| GET | `types` | registry fields per sheet (see field-dictionary.md) |
| GET | `templates/{start_point or type}` | `format=xlsx` (any) or `csv` (single type only; start points answer 422 `bundle_requires_xlsx`) |
| GET | `/` | `per_page` (max 100), returns `items` + `meta` |
| POST | `/` | see below |
| GET | `{batch}` | status, mapping, form_spec, readiness, landing_preview, stats, rows summary |
| POST | `{batch}/analyze` | 202; 422 `invalid_state` |
| PATCH | `{batch}/mapping` | `{ sheets: { <sheet>: { columns:{header:{target}}, value_maps, defaults, ignored } } }`; targets outside the registry are dropped |
| PATCH | `{batch}/form-spec` | `{ program, questions[{label,type,required,options,include}], required_documents[], confirmed }` |
| POST | `{batch}/validate` | 202 |
| POST | `{batch}/readiness` | 422 `not_validated` until validated |
| GET | `{batch}/rows` | `status`, `sheet`, `per_page` |
| PATCH | `{batch}/rows/{row}` | `{ raw: { <column>: value } }`, revalidates the batch |
| GET | `{batch}/errors` | CSV, formula-injection safe |
| POST | `{batch}/commit` | 422 `consent_required`, `readiness_blocked` (+ `blockers[]`), `invalid_state` |
| POST | `{batch}/rollback` | 422 `rollback_blocked` (+ `blockers[]`) |
| DELETE | `{batch}` | cancel before commit; 422 `invalid_state` after |
| POST | `programs/{program}/invitations` | `{ user_ids? }`; 422 `not_imported` |
| GET/POST/DELETE | `integrations/google` | status, connect (returns `{url}`), disconnect; 422 `google_not_configured` until enabled; see 04 |
| GET/PUT | `settings` | organization AI consent for real sample values (`ai_real_samples`, `confirm_ai_data_sharing`) |
| POST | `{batch}/queue-ai-review` | on request only; 422 `no_ai_review_method`, `invalid_state` |
| GET | `{batch}/file` | 10-minute temporary link to the uploaded file |

### POST `/` (multipart)
| Field | Type | Notes |
|---|---|---|
| start_point | enum | `setup, review, finalization, funding_setup, disbursement, monitoring` (multi-sheet XLSX bundle) |
| type | enum | a single sheet type, or `form`; use instead of start_point for piecemeal imports |
| source | enum, required | `file`, `text`, `google_form_url` |
| file | file | `.csv`/`.xlsx`, max 10 MB, 5000 rows, 80 columns; checked by extension and magic bytes |
| text / url | string | pasted data or questions / public Google Forms link |
| program_id | int | add to an existing program of your organization (422 `program_not_found`) |
| options.on_duplicate | `skip`(default) `update` `fail` | |
| options.mode | `partial`(default) `all_or_nothing` | |
| options.link_existing / update_existing | bool, default false | manual records are never changed without these; nothing moves backward |
| options.date_format, default_currency (KES/USD), default_country_code | | |
| options.raise_max_awardees, adjust_program_balance | bool, default false | |

Error codes seen on create: `invalid_file`, `form_private`, `form_url`, `form_unreachable`, `form_empty`, `form_too_large`, `too_many_redirects`, `program_not_found`.

### Readiness example
```json
{ "ready": true, "blockers": [], "warnings": [{"code":"review_period_open","message":"Round 2 has a review period that has not ended yet; Finalize only works after it ends."}],
  "counts": {"program":1,"rounds":2,"applications":2},
  "landing_preview": {"program":{"status":"under_review","visible":false},"rounds":{"1":"finalized","2":"published"},
    "applications":{"approved / submitted":2},"milestones":{"count":0},"description":{"program":"under_review, hidden"}},
  "skipped": ["Earlier-round history is synthetic; ..."], "next_steps": ["Assign Business Coaches or score applications","Accept or reject (round 1)","Finalize the round"] }
```
Blocker codes: `missing_sheet, no_rows, funding_exceeds_total, dates_inverted, rounds_count, duplicate_round, too_many_rounds, round_dates_inverted, duplicate_application_ref, unknown_round, missing_scores, over_max_awardees, awarded_exceeds_total, milestones_exceed_award, duplicate_sequence, currency_unsupported, invalid_rows`. Warnings include `partial_release_remainder` (some milestones already paid: escrow covers only the rest), `required_answers_missing`, `review_period_open` (all_or_nothing only).

Business rules: imports are silent (no mail, notification, broadcast, AI or payment); imported programs are hidden and never published; historic payments are record-only; applicant accounts are created without a password and are contacted only by invitation; see `docs/program-import/` for landing states per start point.
