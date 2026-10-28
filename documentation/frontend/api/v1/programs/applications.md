## Program Applications

All endpoints are under `/api/v1/programs` and require Sanctum authentication.

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/{program_id}/applications` | Owner/team list of applications |
| GET | `/{program_id}/applications/awarded` | List awarded applications |
| GET | `/applications/{id}` | Read an application, current round data and caller's review assignment |
| GET | `/my-applications` | List the caller's applications |
| GET | `/{program_id}/sme/applications` | Applicant view of program/application status |
| GET | `/my-applications/awarded` | Applicant's awarded applications |
| POST | `/{program}/applications` | Submit a new application |
| POST | `/applications/{pitch}/accept` | Owner accepts/awards an application |
| POST | `/applications/{application}/planning-mode` | Set funding-plan mode |
| GET | `/fund-release-request/{pitch_id}` | Get funding release request data |

### Submit an application

Only a business-owner account can apply, and `business_id` must belong to the authenticated user. The program's first round must exist and be open. New submissions enter that first round; subsequent rounds accept applicants advanced from prior rounds.

Submit JSON plus multipart files where applicable:

| Field | Required | Validation |
|---|---|---|
| `business_id` | Yes | Existing listing owned by caller |
| `startup_name` | Yes | string, max 255 |
| `contact_person_name` | Yes | string, max 100 |
| `contact_person_email` | Yes | valid email, max 100 |
| `sector` | Yes | string, max 100 |
| `headquarters_location` | Yes | string, max 255 |
| `total_amount_requested` | Yes | numeric, >= 0 and <= program `funding_per_business` |
| `match_score` | Yes | numeric; server recalculates it when scoring succeeds |
| `score_breakdown` | Yes | array; server replaces it with its computed breakdown when available |
| `round_answers` | No | array of `{question_id,response}`; response nullable string max 1000 |
| `round_documents` | No | array of `{document_type,file}`; file is required per item and max 20 MB |
| `pitch_deck_file` | No | PDF/PPT/PPTX, max 20 MB |
| `business_plan_file` | No | PDF/DOC/DOCX, max 20 MB |
| `pitch_video` | No | URL |
| `stage` | No | string, max 100 |
| `revenue_last_12_months` | No | numeric >= 0 |
| `team_experience_avg_years` | No | integer >= 0 |
| `traction_kpis` | No | string |
| `social_impact_areas` | No | array |
| `bonus_points` | No | comma-separated string, max 300; only recognized program options count |

All knockout questions for the first round must have non-empty answers; otherwise the request returns `422` with `missing_question_ids`. The server also recomputes the match score when possible and rejects a result below 50 with `422`. This 50% application gate is separate from the program's `match_score_limit`, which only controls whether bonus points count. The owner may configure a nullable integer `match_score_limit` from 1 to 100 on program create/update; null means no bonus threshold.

Application submission returns `422` if the program is unpublished, its first round is missing/closed/not yet open, or the application deadline passed. Public program list items expose `accepting_applications` and `applications_closed_reason` so the UI can disable the application action with the backend's reason.

### Application detail and response conventions

`GET /applications/{id}` returns `pitch` plus `round_data`. Status fields on the pitch are objects with `value` and `color`; include `round_status`, `funding_setup_status`, and `knockout_status`. The response also includes `my_review_assignment` for a reviewer who is assigned to that application.

Program list routes support optional `page` and `per_page` query parameters. They return an envelope `{ "success":true,"message":"...","data":{"items":[...],"pagination":{...}},"programs":[...] }` when paginated. Without pagination parameters, the same envelope and legacy top-level list key are returned, without `data.pagination`. `per_page` defaults to 15 when paginating and is clamped to 1–100. Program status objects now include `value`, `label`, and `color`.

Program-module write responses are normalized to `{ "success":true,"message":"...","data":... }`; errors use `success:false`, `message`, and `errors`. Validation errors are `422`; authorization is `403`; missing resources are `404`; lifecycle/conflict errors may be `409` or `422`.
