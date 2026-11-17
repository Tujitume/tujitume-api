# Mapping, forms and rows

## POST `{batch}/analyze` (202)
Heuristic mapping always; AI proposals for columns the heuristic could not place, only when AI is enabled and a provider has capacity. AI output is accepted only if valid JSON naming real headers and registry targets. Value mapping by AI sends real (never email/phone) values only when the platform allows it, **the organization consented** (`PUT settings` with `ai_real_samples=true` and `confirm_ai_data_sharing=true`) **and the provider passes the platform privacy gate** for that organization; otherwise only shape-masked samples are sent. Prompts carry headers and shape-masked samples (letters `a/A`, digits `9`, emails and phones fully masked, max 5 per column).

## PATCH `{batch}/mapping`
```json
{ "sheets": { "applications": {
    "columns": { "Business Name": { "target": "startup_name" }, "Q1 impact": { "target": "question:Q1" } },
    "value_maps": { "outcome": { "No": "rejected" } }, "defaults": { "sector": "Agriculture" }, "ignored": ["Notes"] } } }
```
Targets outside the registry are dropped. For a single-type batch the sheet wrapper may be omitted.

## PATCH `{batch}/form-spec` (form imports)
`{ program: {program_title, total_program_amount, ...}, questions: [{label, type, required, options[], include, help_text}], required_documents: [], confirmed }`. Types: `text paragraph choice checkbox scale date`. Questions start as `suggested` until you edit/confirm; `include=false` leaves one out. The spec may also carry `knockout_suggestions` and `scoring_suggestions` (never applied automatically).

## POST `{batch}/validate` (202), POST `{batch}/readiness`
Validate stages rows from the mapping (or the form spec) and checks every row. Readiness (422 `not_validated` before that) returns:

| Field | Meaning |
|---|---|
| ready | no blockers |
| blockers[] | `{code, message}`; must be fixed (`invalid_rows` only in all_or_nothing mode) |
| warnings[] | `{code, message}`, for example `review_period_open`, `required_answers_missing`, `no_applicant_emails`, `invalid_rows` (partial mode) |
| counts | rows per sheet |
| landing_preview | program/rounds/applications/milestones statuses after commit |
| skipped, next_steps | what is not imported; what to do next |

## GET `{batch}/rows`, PATCH `{batch}/rows/{row}`, GET `{batch}/errors`
Rows list takes `status` (`pending valid invalid imported skipped failed`), `sheet`, `per_page`. Fix a staged row with `{ "raw": { "<column>": "<value>" } }`; the batch is revalidated and readiness returned. `errors` downloads a CSV (formula-injection safe).
