# Program applications

Routes are under `/api/v1/programs` and require Sanctum authentication. Applicants must use an account with business-owner type and submit a business listing they own. A new application enters the program's first round.

## Endpoints

| Method | Endpoint | Purpose |
|---|---|---|
| `POST` | `/{program}/applications` | Submit a new application |
| `GET` | `/{program_id}/applications` | Program staff list applications |
| `GET` | `/applications/{id}` | View application and current-round data |
| `GET` | `/my-applications` | List applications visible to the caller |
| `GET` | `/{program_id}/sme/applications` | Fetch public program form and applicant-safe Round 1 questions |
| `POST` | `/match-score/{program_id}` | Preview a server-calculated Round 1 score |

## Submit an application

**Request** `POST /api/v1/programs/{program}/applications`

Send JSON for text answers. Use `multipart/form-data` when a question has a file answer or round documents are required.

| Field | Required | Type | Notes |
|---|---:|---|---|
| `business_id` | Yes | integer | Must be a listing owned by the authenticated applicant |
| `startup_name` | Yes | string | Maximum 255 characters |
| `contact_person_name` | Yes | string | Maximum 100 characters |
| `contact_person_email` | Yes | email | Maximum 100 characters |
| `sector` | Yes | string | Application/business detail, not a match-score field |
| `headquarters_location` | Yes | string | Maximum 255 characters |
| `total_amount_requested` | Yes | number | `0` through the program's `funding_per_business` |
| `round_answers` | Conditional | array | `{question_id, response}` entries for Round 1. Required questions and knockout answers must be supplied |
| `round_answers.*.question_id` | Yes per entry | integer | Question must belong to the program's first round |
| `round_answers.*.response` | Conditional | string/file | Structured answers (checkbox, ranking, location, matrix) are JSON-encoded strings; file upload answers use a multipart file |
| `round_documents` | No | array | Owner-configured round documents, each `{document_type, file}` |
| `stage` | No | string | Existing application detail |
| `revenue_last_12_months` | No | number | At least `0` |
| `team_experience_avg_years` | No | integer | At least `0` |
| `traction_kpis` | No | string | Existing application detail |
| `pitch_deck_file` | No | file | PDF, PPT, PPTX; max 20 MB |
| `business_plan_file` | No | file | PDF, DOC, DOCX; max 20 MB |
| `pitch_video` | No | URL | Existing application detail |
| `match_score` | No | number | Ignored; server calculates score from saved Round 1 answers |
| `score_breakdown` | No | array | Ignored; client cannot set a score or breakdown |

Program questions are round-scoped. Do not send legacy program custom answers or program-level scoring fields.

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
  "round_answers": [
    {"question_id": 41, "response": "[\"Niagara\",\"NY\"]"},
    {"question_id": 42, "response": "6"},
    {"question_id": 43, "response": "{\"region\":\"East Africa\",\"country\":\"Kenya\"}"}
  ]
}
```

**Example multipart file answer**

```text
business_id=84
startup_name=Northstar Foods
contact_person_name=Amina Otieno
contact_person_email=amina@example.test
sector=Food processing
headquarters_location=Nairobi, Kenya
total_amount_requested=40000
round_answers[0][question_id]=43
round_answers[0][response]=<attach a PDF file>
```

The file must match both the file question's accepted categories and `type:` restrictions. The server validates the upload and stores the file reference on that question's Round 1 response.

**Example response**

```json
{
  "message": "Application submitted successfully.",
  "data": {
    "id": 301,
    "program_id": 12,
    "business_id": 84,
    "startup_name": "Northstar Foods",
    "match_score": "89.5",
    "status": "pending",
    "current_round_id": 7
  }
}
```

The saved score is recalculated from submitted Round 1 answers before returning the application.

## Match-score preview

**Request** `POST /api/v1/programs/match-score/{program_id}`

| Field | Required | Type | Notes |
|---|---:|---|---|
| `round_answers` | No | array | Same question-answer structure as application submission |
| `round_answers.*.question_id` | Yes per entry | integer | Must identify a question in Round 1 |
| `round_answers.*.response` | No | string/file | Structured answers are JSON strings; file answers are multipart uploads |

**Example request**

```json
{
  "round_answers": [
    {"question_id": 41, "response": "[\"Niagara\",\"NY\"]"},
    {"question_id": 42, "response": "6"}
  ]
}
```

**Example applicant-safe response**

```json
{
  "match_score": 50,
  "below_threshold": false,
  "breakdown_for_applicant": [
    {"question_id": 41, "question_text": "Interested places", "status": "matched"},
    {"question_id": 42, "question_text": "Business age (years)", "status": "not matched"}
  ]
}
```

Preview does not persist answers or score. It never includes question points or accepted answer keys.

## Resource visibility

| Resource value | Applicant | Program owner/staff |
|---|---|---|
| `match_score` | Own saved score | Application score |
| `below_threshold` | Not used to block application | Included when scoring is on and a threshold exists |
| Full `score_breakdown` | Hidden | Available in application list/detail |
| Round question `match_answers`, `match_points` | Omitted from applicant form/history | Returned by question management |
| Preview `breakdown_for_applicant` | Question ID/text and matched status only | No weights/answer key in preview response |

## Errors

| HTTP / field | Condition |
|---|---|
| `401` | Missing or invalid authentication |
| `403` | Not a business owner or business does not belong to caller |
| `404` | Program, business, or Round 1 question does not exist |
| `422 business_id` / other required field | Missing or invalid application data |
| `422 round_answers` | Required question unanswered, unknown question/round, or invalid encoded answer |
| `422 round_answers.*.response` | File submitted for a non-file question, invalid file category/type, or oversized file |
| `422` | Program is closed or has no application round |
| `500` | Unexpected server error; confirm whether submission succeeded before retrying |

## Business rules

- The program must be open for applications and a Round 1 must exist.
- The server checks question ownership against that Round 1 and enforces required answers.
- Knockout questions are required and evaluated independently; a failed knockout is not a match-score deduction.
- Match score is based on the server's current Round 1 question rules. Client `match_score` and `score_breakdown` are ignored.
- An application below `match_score_limit` is still accepted; the owner receives a `below_threshold` flag.
- Disabling program scoring clears saved scores and suppresses threshold flags.
- Answers in later rounds do not recalculate Round 1 match scores.
