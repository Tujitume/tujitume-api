# Program applications

All routes below are under `/api/v1/programs` and require Sanctum authentication. A business-owner account submits an application against the program's first open round.

## Endpoints

| Method | Endpoint | Purpose |
|---|---|---|
| `POST` | `/{program}/applications` | Submit an application |
| `GET` | `/{program_id}/applications` | Program staff list applications |
| `GET` | `/{program_id}/applications/awarded` | List awarded applications |
| `GET` | `/applications/{id}` | Get application detail and current round data |
| `GET` | `/my-applications` | List applications visible to the caller |
| `GET` | `/{program_id}/sme/applications` | Get applicant-facing program/application information |
| `POST` | `/match-score/{program_id}` | Preview a match score without submitting |

## Submit an application

**Request** `POST /api/v1/programs/{program}/applications`

Send JSON for scalar and array answers. Use `multipart/form-data` when a custom file answer or another file is uploaded. Custom answers are keyed by the immutable field `key`; custom file inputs use `custom_answers[key]`.

| Field | Required | Type | Notes |
|---|---:|---|---|
| `business_id` | Yes | integer | Must identify a business listing owned by the authenticated user |
| `startup_name` | Yes | string | Maximum 255 characters |
| `contact_person_name` | Yes | string | Maximum 100 characters |
| `contact_person_email` | Yes | email | Maximum 100 characters |
| `sector` | Yes | string | Maximum 100 characters; application/business property, not a program column |
| `headquarters_location` | Yes | string | Maximum 255 characters |
| `total_amount_requested` | Yes | number | At least `0` and no greater than the program's `funding_per_business` |
| `custom_answers` | Conditional | object | Active application fields keyed by field key; required fields depend on each definition |
| `round_answers` | Conditional | array | `{question_id, response}` entries for first-round questions; knockout questions must be answered |
| `round_documents` | No | array | `{document_type, file}` entries; each file is required within its entry and limited by the round-document rule |
| `stage` | No | string | Maximum 100 characters |
| `revenue_last_12_months` | No | number | At least `0` |
| `team_experience_avg_years` | No | integer | At least `0` |
| `traction_kpis` | No | string | Existing application field |
| `pitch_deck_file` | No | file | PDF, PPT, or PPTX; max 20 MB |
| `business_plan_file` | No | file | PDF, DOC, or DOCX; max 20 MB |
| `pitch_video` | No | URL | Existing application field |
| `match_score` | No | number | Deprecated client value; ignored and recomputed by the server |
| `score_breakdown` | No | array | Deprecated client value; ignored and recomputed by the server |

Do not send the replaced fixed **program** fields as application answers. If the program asks for one of those values, use its active `custom_answers` key.

**Example JSON payload**

```json
{
  "business_id": 84,
  "startup_name": "Northstar Foods",
  "contact_person_name": "Amina Otieno",
  "contact_person_email": "amina@example.test",
  "sector": "Food processing",
  "headquarters_location": "Nairobi, Kenya",
  "total_amount_requested": 40000,
  "custom_answers": {
    "interested-places": ["Niagara", "NY"],
    "business-age-years": 6
  },
  "round_answers": [
    {"question_id": 17, "response": "We will use the funding to expand production."}
  ]
}
```

For the required Business plan PDF field, submit multipart data:

```text
business_id=84
startup_name=Northstar Foods
contact_person_name=Amina Otieno
contact_person_email=amina@example.test
sector=Food processing
headquarters_location=Nairobi, Kenya
total_amount_requested=40000
custom_answers[interested-places][]=Niagara
custom_answers[interested-places][]=NY
custom_answers[business-age-years]=6
custom_answers[business-plan-pdf]=<PDF file>
```

The keys in this sample are generated from labels. Fetch `application_fields` or the PO field list and use the exact returned keys; do not generate keys independently.

**Example response — current implementation**

```json
{
  "message": "Application submitted successfully.",
  "data": {
    "id": 301,
    "program_id": 12,
    "business_id": 84,
    "startup_name": "Northstar Foods",
    "total_amount_requested": "40000.00",
    "match_score": 100,
    "status": "pending",
    "current_round_id": 7
  }
}
```

The example omits unrelated application columns. Current response serialization hides `score_breakdown` and does not append a `custom_answers` projection; see [resource differences and implementation notes](match-score-engine.md#applicant-vs-program-owner-resources).

## Match-score preview

**Request** `POST /api/v1/programs/match-score/{program_id}`

| Field | Required | Type | Notes |
|---|---:|---|---|
| `custom_answers` | No | object | Preview answers keyed by active custom-field key |

```json
{
  "custom_answers": {
    "interested-places": ["Niagara", "NY"],
    "business-age-years": 6,
    "business-plan-pdf": "uploaded-file-reference"
  }
}
```

The endpoint returns an applicant-safe status per scoring field:

```json
{
  "success": true,
  "message": "Request completed.",
  "data": {
    "match_score": 100,
    "breakdown_for_applicant": [
      {"field_id": 41, "key": "interested-places", "label": "Interested places", "status": "matched"},
      {"field_id": 42, "key": "business-age-years", "label": "Business age (years)", "status": "matched"},
      {"field_id": 43, "key": "business-plan-pdf", "label": "Business plan PDF", "status": "matched"}
    ]
  },
  "status": 200
}
```

The preview is read-only. It does not save answers or a historic score snapshot. The route is covered by the API-wide throttle middleware; use the preview only when the applicant changes answers, not on every keystroke.

## Errors

| HTTP | Condition | Client action |
|---:|---|---|
| `401` | Caller is unauthenticated | Authenticate and retry |
| `403` | Caller is not a business owner or does not own the selected business | Select an owned business and authenticate as its owner |
| `404` | Program, business, or referenced round question does not exist | Refresh the resource |
| `422` | Invalid/missing base answer, required custom answer, invalid option, unanswered knockout question, closed program, or score below a configured threshold | Show field errors or the returned closure/threshold message |
| `500` | Unexpected server error | Retry safely; avoid duplicate submissions |

## Business rules

- The program must be published, have an open first round, and be within its deadline.
- The submitted `business_id` must belong to the authenticated user.
- Only active application fields are validated and saved. Archived fields are not part of the application form.
- Multi-select answers are arrays of option values. Select answers use one option value. File fields are multipart uploads.
- The server calculates the score from the submitted `custom_answers`. Client-supplied `match_score` and `score_breakdown` never establish the saved score.
- The server stores an integer match score when scoring fields exist. If the program has no base scoring fields, there is no base score to calculate.
- `match_score_limit` controls bonus eligibility only; it is not a general application minimum.
- Round questions/documents are a separate feature from program custom fields. Use `round_answers` and `round_documents` for those round-specific inputs.
- Historic score snapshots should not be recalculated when a PO later edits scoring definitions.

## Resource visibility

The intended response contract is: applicants receive their `match_score` and an applicant-safe status breakdown, while the PO can inspect submitted custom answers and the full scoring breakdown. Neither public nor applicant-facing resources may expose scoring weights or targets. Current serialization gaps are documented in [Match-score engine](match-score-engine.md#applicant-vs-program-owner-resources).
