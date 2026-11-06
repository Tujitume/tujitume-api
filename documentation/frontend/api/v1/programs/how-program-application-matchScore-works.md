# How program application match score works

This end-to-end example creates a program, adds three scored Round 1 questions, submits an application, then previews the exact server score. IDs are illustrative.

## 1. Create a program

Authenticate as the program-owning organization and use an existing `program_industry_id`.

**Request** `POST /api/v1/programs/create-program`

```text
program_industry_id=1
program_title=Youth Agribusiness Grant 2027
total_program_amount=16000000
funding_per_business=250000
application_start_date=2027-01-10
application_deadline=2027-03-31
program_type=single_round
total_rounds=1
use_match_score=1
match_score_limit=50
application_round[rubric_mode]=weighted
application_round[advancement_mode]=manual
```

The API creates Round 1 as part of this request.

**Response**

```json
{
  "message": "Program created successfully",
  "program": {
    "id": 12,
    "program_title": "Youth Agribusiness Grant 2027",
    "use_match_score": true,
    "match_score_limit": 50
  },
  "firstRound": {
    "id": 7,
    "round_number": 1,
    "round_name": "Application Round"
  }
}
```

Use `firstRound.id` to add Round 1 questions. The score is a sum of question points, so the example below uses 60 points and does not scale that total to 100.

### Configure the questions

Create each question with a separate request to `POST /api/v1/programs/rounds/7/questions`.

**Interested places — 20 points**

The old program-level `multi_select`/`in_set` configuration has been replaced by a checkbox question. Giving each target option 20 points and capping the question at 20 makes any selected target place earn full points.

```json
{
  "question_text": "Interested places",
  "question_type": "checkbox",
  "is_required": true,
  "options": ["Niagara", "NY", "London"],
  "display_order": 1,
  "knockout_fail_value": null,
  "use_in_match_score": true,
  "match_points": 20,
  "match_answers": {"Niagara": 20, "London": 20}
}
```

**Response**

```json
{
  "message": "Question added successfully",
  "data": {
    "id": 41,
    "round_id": 7,
    "question_text": "Interested places",
    "question_type": "checkbox",
    "is_required": true,
    "options": ["Niagara", "NY", "London"],
    "display_order": 1,
    "use_in_match_score": true,
    "match_points": 20,
    "match_answers": {"Niagara": 20, "London": 20}
  }
}
```

**Business age (years) — 30 points**

```json
{
  "question_text": "Business age (years)",
  "question_type": "number",
  "is_required": true,
  "options": null,
  "display_order": 2,
  "knockout_fail_value": null,
  "use_in_match_score": true,
  "match_points": 30,
  "match_answers": {"min": 2, "max": 10}
}
```

**Response**

```json
{
  "message": "Question added successfully",
  "data": {
    "id": 42,
    "round_id": 7,
    "question_text": "Business age (years)",
    "question_type": "number",
    "is_required": true,
    "options": null,
    "display_order": 2,
    "use_in_match_score": true,
    "match_points": 30,
    "match_answers": {"min": 2, "max": 10}
  }
}
```

**Business plan PDF — 10 points**

```json
{
  "question_text": "Business plan PDF",
  "question_type": "file_upload",
  "is_required": true,
  "options": ["document", "type:pdf"],
  "display_order": 3,
  "knockout_fail_value": null,
  "use_in_match_score": true,
  "match_points": 10,
  "match_answers": null
}
```

**Response**

```json
{
  "message": "Question added successfully",
  "data": {
    "id": 43,
    "round_id": 7,
    "question_text": "Business plan PDF",
    "question_type": "file_upload",
    "is_required": true,
    "options": ["document", "type:pdf"],
    "display_order": 3,
    "use_in_match_score": true,
    "match_points": 10,
    "match_answers": null
  }
}
```

## 2. Submit an application

Authenticate as the applicant and choose a business listing that belongs to that account. The multipart answer format lets the file be uploaded in the same request.

**Request** `POST /api/v1/programs/12/applications`

```text
business_id=84
startup_name=Northstar Foods
contact_person_name=Amina Otieno
contact_person_email=amina@example.test
sector=Food processing
headquarters_location=Nairobi, Kenya
total_amount_requested=40000
round_answers[0][question_id]=41
round_answers[0][response]=["Niagara","NY"]
round_answers[1][question_id]=42
round_answers[1][response]=6
round_answers[2][question_id]=43
round_answers[2][response]=<attach a PDF file>
```

Structured answers should be JSON-encoded strings. Do not send `match_score` or `score_breakdown`; if an older client includes them, they do not determine the saved score.

**Response**

```json
{
  "message": "Application submitted successfully.",
  "data": {
    "id": 301,
    "program_id": 12,
    "startup_name": "Northstar Foods",
    "match_score": "60.0",
    "status": "pending",
    "current_round_id": 7
  }
}
```

## 3. Preview and understand the match score

The frontend may request a preview using the same Round 1 answers. For a file preview, submit multipart data and include the actual file; a local file path or a client-supplied “uploaded” string is not proof of an upload.

**Request** `POST /api/v1/programs/match-score/12`

```text
round_answers[0][question_id]=41
round_answers[0][response]=["Niagara","NY"]
round_answers[1][question_id]=42
round_answers[1][response]=6
round_answers[2][question_id]=43
round_answers[2][response]=<attach the PDF file>
```

**Applicant-safe response**

```json
{
  "match_score": 60,
  "below_threshold": false,
  "breakdown_for_applicant": [
    {"question_id": 41, "question_text": "Interested places", "status": "matched"},
    {"question_id": 42, "question_text": "Business age (years)", "status": "matched"},
    {"question_id": 43, "question_text": "Business plan PDF", "status": "matched"}
  ]
}
```

The calculation is:

| Answer | Rule | Earned |
|---|---|---:|
| `["Niagara","NY"]` | Niagara is an accepted checkbox option; 20 points are awarded and capped at 20 | 20 |
| `6` | Inside the inclusive range 2–10 | 30 |
| Uploaded PDF | Matches the file question's accepted category and type | 10 |
| **Total** | Plain sum; no rescaling of the configured 60-point total | **60** |

With `match_score_limit: 50`, this application is not below threshold. An otherwise identical score under 50 would still be accepted; the owner would see a `below_threshold` flag, not an automatic rejection.

For question types, validation errors, round restrictions, and score lifecycle, see [Match-score engine](match-score-engine.md).
