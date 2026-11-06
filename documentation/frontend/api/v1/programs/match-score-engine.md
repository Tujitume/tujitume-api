# Match-score engine

The engine compares applicant answers to program-owner answer keys for scored Round 1 questions. The score is a server-calculated percentage from `0` to `100`; client scores are never trusted.

## Match-type matrix

Text comparison is trimmed and case-insensitive unless otherwise noted. An unanswered scored question earns `0`.

| Question type | `options` | `match_answers` | How it scores |
|---|---|---|---|
| `knockout`, `knockout_yes_no` | `null` or `[]` | Not scored | Knockout evaluator applies `knockout_fail_value`; scoring is rejected |
| `short_answer`, `long_text`, `long_answer`, `paragraph`, `address` | `null` | `{"keywords":["Nairobi"]}` or `null` | With no keywords, any non-empty answer earns full points. Otherwise, any keyword must occur in the answer |
| `yes_no` | `null` | `["yes"]` or `["no"]` | Exact normalized answer earns full points |
| `true_false` | `null` | `["true"]` or `["false"]` | Exact normalized answer earns full points |
| `dropdown`, `multiple_choice` | List of choices | `{"Registered":10,"In progress":5}` | Selected choice earns its configured points; an unconfigured choice earns zero |
| `checkbox`, `checkboxes` | List of choices | `{"Women":6,"Youth":6,"People with disabilities":3}` | Sum points for selected choices, capped at the question's `match_points` |
| `ranking` | Correct order, e.g. `["A","B","C"]` | Derived as `{"order":["A","B","C"]}` | Partial credit is based on normalized positional distance: `match_points × (1 − total rank displacement / maximum displacement)` |
| `rating`, `rating_scale` | `null` | `{"min":4}` | Rating at least `min` earns full points; scale is 1–5 |
| `number`, `currency`, `currency_money`, `percentage`, `linear_scale` | `null` | `{"min":5,"max":10}` | Inclusive range earns full points; either bound may be omitted |
| `phone`, `phone_number` | `null` | `{"countries":["ke","ug"]}` | E.164 answer with `+` earns full points when its calling code matches an accepted country |
| `email` | `null` | `{"domains":["company.com"]}` or `null` | Valid email earns full points when its domain matches; subdomains count. Null/empty domains accept any valid email |
| `url`, `url_website` | `null` | `{"domains":["example.org"]}` or `null` | Valid URL earns full points when its host matches; subdomains count. Null/empty domains accept any valid URL |
| `date` | `null` | `{"from":"2027-01-01","to":"2027-12-31"}` or `null` | Inclusive date window earns full points; format `YYYY-MM-DD` |
| `time` | `null` | `{"from":"09:00","to":"17:00"}` or `null` | Inclusive time window earns full points; format `HH:mm` |
| `datetime`, `date_time` | `null` | `{"from":"2027-01-01T09:00","to":"2027-12-31T17:00"}` or `null` | Inclusive datetime window earns full points; format `YYYY-MM-DDTHH:mm` |
| `location` | Parts to collect, such as `["region","country","specific_location"]` | One or more accepted part lists | Full points if **any** configured part matches any accepted value; other unmatched parts do not reduce the score |
| `matrix`, `matrix_grid` | `row:Label` and `col:Label` strings | `{"Records":"Ready","Staff":"Ready"}` | Points are shared equally across configured rows; each row with its expected column earns its share |
| `file_upload` | Categories and/or accepted types | `null` | A valid uploaded file earns full points |
| `signature` | `null` | `null` | A provided signature earns full points |

`video_link` and `budget_breakdown` are not scoreable types. Knockout questions are never scored.

### File-upload options

`options` is a flat list of strings:

- Categories: `document`, `photo`, `video`, `beneficiary_list`, `other`; legacy combined category `photo_video` is also read.
- File types use `type:` prefix: `pdf`, `doc`, `excel`, `powerpoint`, `csv`, `txt`, `rtf`, `image`, `svg`, `video`, `audio`, `zip`, `json`, `xml`, `html`, `code`, `cad`, `any`.
- Legacy options without the prefix are accepted as file types where recognized.
- When a category and a type are configured, both constraints must match. Multiple categories/types are alternatives within their respective group.
- The frontend picker uses the same restrictions; the server validates every uploaded file independently.

## Worked scoring example

The following Round 1 questions use exactly 100 points:

| Question | Points | Applicant answer | Earned |
|---|---:|---|---:|
| Location: East Africa or Kenya/Uganda/Tanzania | 20 | Kenya, even if region is not accepted | 20 |
| Checkbox: Women 6, Youth 6, disability inclusion 3 | 15 | Women + Youth | 12 |
| Annual revenue, minimum 500,000 | 15 | 800,000 | 15 |
| Phone accepted in KE/UG/TZ | 10 | `+254712345678` | 10 |
| Ranking A, B, C, D | 10 | A, B, D, C (one adjacent pair swapped) | 7.5 |
| Matrix: Records=Ready, Staff=Ready | 10 | Records=Ready, Staff=Partly | 5 |
| Rating, minimum 4 | 10 | 5 stars | 10 |
| Required PDF or image | 10 | Uploaded PDF | 10 |
| **Total** | **100** |  | **87** |

The location question gives full points because any configured part can match. Checkbox selection sums individual option points and is capped at 15. Ranking and matrix questions can award partial points. For four ranking options, swapping one adjacent pair has a total displacement of two against a maximum displacement of eight, so it earns `10 × (1 − 2/8) = 7.5`. Reversing all four has maximum displacement and earns zero. The total is `89.5`. A score is the plain sum; if an owner configures only 60 points, do not rescale those 60 points to 100.

### Three common question configurations

These are question-schema equivalents for common program form needs:

- **Interested places**, 20 points: a checkbox question with options `["Niagara","NY","London"]`, `match_answers: {"Niagara":20,"London":20}`. Any selected target place gives full 20; selecting both remains capped at 20.
- **Business age (years)**, 30 points: a number question with `match_answers: {"min":2,"max":10}`.
- **Business plan PDF**, 10 points: a required `file_upload` question with `options: ["document","type:pdf"]`, `match_answers: null`.

See [Round custom questions](custom-fields.md) for complete create payloads and responses.

## Preview endpoint

**Request** `POST /api/v1/programs/match-score/{program_id}`. Send structured answers as JSON strings in `round_answers`; multipart is supported for file previews.

| Field | Required | Type | Notes |
|---|---:|---|---|
| `round_answers` | No | array | Entries use `{question_id, response}`; a file may use `response` or `file` |
| `round_answers.*.question_id` | Yes per entry | integer | Must belong to the program's Round 1 |
| `round_answers.*.response` | No | string/file | Encode structured values as JSON text, e.g. `{"country":"Kenya"}` |

**Example request**

```json
{
  "round_answers": [
    {"question_id": 41, "response": "[\"Niagara\",\"NY\"]"},
    {"question_id": 42, "response": "6"}
  ]
}
```

**Applicant-safe response**

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

The endpoint does not save answers. It never returns question points or accepted answers.

## Score and threshold rules

1. Use only Round 1 questions with `use_in_match_score: true`.
2. Sum each question's earned points; cap the overall result at 100 and round to one decimal place.
3. If there are no active scored questions, the result is `null`.
4. If `use_match_score` is false, no score or below-threshold flag is calculated; stored application scores are cleared.
5. If `match_score` is below `match_score_limit`, set `below_threshold: true` for program-owner review. Equal/above is false. Never reject or block submission based only on this threshold.
6. Scores are recalculated when Round 1 answers change and when owners change scoring configuration or delete a scored question. Later round answers do not change the Round 1 score.
7. `bonus_points` is ignored. Scored questions share the full 100-point budget.

## Lock and validation rules

- Program-level `use_match_score` is the master switch. New programs default it on. The threshold is nullable only when omitted/disabled and otherwise must be an integer from 1 to 100.
- Only Round 1 can have scored questions. The server rejects scoring fields for Round 2+; copying a question to a later round clears all scoring fields.
- The program must have scoring enabled. Every scored question must be required, not knockout, use `match_points` from 1 to 100, and provide a valid type-specific `match_answers`.
- Sum of Round 1 scored `match_points` cannot exceed 100. Lower totals are allowed.
- Unscoring a question clears its `match_points` and `match_answers`. Disabling the program keeps existing question rules dormant so they work if scoring is re-enabled.
- A question-type change invalidates its old accepted answers. Unknown/removed choice or matrix keys are ignored; at least one key valid for the current options must remain.
- Knockout failure is evaluated independently of score and may affect application eligibility according to the knockout rules.

## Applicant versus program-owner data

| Resource | Applicant/public | Program owner |
|---|---|---|
| Program score settings | `use_match_score`, `match_score_limit` may be present | Full program settings |
| Question resource | Question, options, required flag; no `match_points` or `match_answers` | Full question including score settings and answer key |
| Score preview | Numeric score, below-threshold status, question text/status only | Not an owner scoring-configuration endpoint |
| Application | Applicant sees own saved `match_score`; no full score breakdown | Owner can inspect `below_threshold` and full server-calculated `score_breakdown` |

## Errors

| HTTP / field | Meaning |
|---|---|
| `401` | Missing or invalid authentication |
| `403` | Caller cannot operate on this program/application |
| `404` | Program, round, or question does not exist |
| `422 use_in_match_score` | Scoring is not Round 1 or program scoring is off |
| `422 match_points` | Points are outside 1–100 or total exceeds 100 |
| `422 match_answers` | Invalid/missing answer key for the question type |
| `422 round_answers` | Invalid question reference or file type |
