# Round custom questions

The filename is retained for existing documentation links; program-level custom fields have been removed. Applicant questions and match-score configuration are stored on `round_custom_questions`, attached to a program round.

All question-management routes require an authenticated program owner.

## Endpoints

| Method | Endpoint | Purpose |
|---|---|---|
| `POST` | `/api/v1/programs/rounds/{round}/questions` | Create one question |
| `GET` | `/api/v1/programs/rounds/{round}/questions` | List a round's questions |
| `PATCH` | `/api/v1/programs/questions/{question}` | Update a question |
| `DELETE` | `/api/v1/programs/questions/{question}` | Delete a question |

Use one request per question. Question options, score settings, and accepted answers are configured on the question—not on the program or round setup request.

## Request fields

| Field | Required | Type | Notes |
|---|---:|---|---|
| `question_text` | Yes | string | Maximum 500 characters |
| `question_type` | Yes | string | A supported round question type; see the [match-type matrix](match-score-engine.md#question-type-and-scoring-matrix) |
| `is_required` | No | boolean | Defaults to `false`; must be `true` for a scored question |
| `options` | No | string array or `null` | Type-specific values; matrix uses `row:Label` and `col:Label` strings |
| `display_order` | Yes | integer | Starts at `1` |
| `knockout_fail_value` | No | string or `null` | Only used for a knockout question |
| `use_in_match_score` | No | boolean | Defaults to `false`; allowed only in Round 1 when program scoring is enabled |
| `match_points` | Conditional | integer or `null` | Required when scored; `1`–`100`; total scored points in Round 1 cannot exceed `100` |
| `match_answers` | Conditional | object, array, or `null` | Type-specific owner answer key; required where the scoring matrix says a key is required |

When `use_in_match_score` is false, the server stores `match_points` and `match_answers` as `null`. A question type change resets answers unless valid answers for the new type are included.

## Scored-question examples

These are complete Round 1 create payloads. The responses below show the question resource returned in `data`. Values and IDs are illustrative.

### Interested places — checkbox with full points for any matching place

The old program-level `multi_select`/`in_set` configuration is not accepted. Use a checkbox question and give each target option the full question points; selected option points are summed and capped at `match_points`. Therefore selecting either Niagara or London earns the full 20 points.

**Request** `POST /api/v1/programs/rounds/7/questions`

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
  "match_answers": {
    "Niagara": 20,
    "London": 20
  }
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

### Business age (years) — number range

**Request** `POST /api/v1/programs/rounds/7/questions`

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

### Business plan PDF — required file upload

**Request** `POST /api/v1/programs/rounds/7/questions`

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

The file question restricts the applicant picker and server upload validation to PDF files. Together, the examples use `20 + 30 + 10 = 60` of the 100 available points.

## Errors

Validation errors use HTTP `422` and an `errors` object keyed to the responsible request field.

| Field | Example message / condition |
|---|---|
| `use_in_match_score` | “The match score only applies to Round 1.” |
| `use_in_match_score` | “Turn on the match score for this program first.” |
| `use_in_match_score` | Knockout question types cannot be scored |
| `match_points` | “Only N points left. All scored questions together can't pass 100.” |
| `match_points` | Must be an integer from `1` to `100` |
| `match_answers` | Missing/invalid type-specific answer key or no remaining valid option/row |
| `is_required` | Every scored question must be required |
| `question_type` | Unsupported question type |
| `options` | Invalid option list for a file, location, ranking, or matrix question |

## Business rules

- Match scoring belongs to Round 1 only. Round 2+ must not set score fields.
- The program must have `use_match_score: true` before any question can be saved as scored.
- `match_points` totals at most `100`. Unused points are allowed; scores are not rescaled.
- Every scored question is required. An unanswered answer would score zero, but required questions must be completed to submit.
- Knockout questions never score; the fail answer is evaluated separately.
- Changing type resets `match_answers`. Ranking's correct order is always derived from its `options`.
- Unknown/removed option keys are ignored when validating choice and matrix answer keys; at least one currently valid key must remain.
- Deleting or changing scored Round 1 questions recalculates existing application scores. Turning program scoring off keeps question rules dormant but clears saved scores.
- Round 2+ copies strip all three scoring fields.
