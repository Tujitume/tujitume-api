# Programs

Program management endpoints are under `/api/v1/programs`. Authenticate with Sanctum. Mutations require the owning organization.

## Endpoints

| Method | Endpoint | Purpose |
|---|---|---|
| `POST` | `/create-program` | Create a program and its initial Round 1 |
| `POST` | `/programs/{program}` | Update program settings, including match-score enablement and threshold |
| `POST` | `/{program}/duplicate` | Duplicate a program, rounds, and questions |
| `GET` | `/get_program/{id}` | Get a program for display |
| `GET` | `/public-programs` | Browse public programs |
| `DELETE` | `/delete-program/{id}` | Delete a program |

Program custom questions are not created on the program. Create them against Round 1 through `POST /rounds/{round}/questions`; see [Round question setup](custom-fields.md).

## Create a program

**Request** `POST /api/v1/programs/create-program`

Send `multipart/form-data` from the program-creation form or JSON when there are no files.

| Field | Required | Type | Notes |
|---|---:|---|---|
| `program_title` | Yes | string | Maximum 255 characters |
| `program_industry_id` | Yes | integer | Existing `program_industries.id` |
| `total_program_amount` | Yes | number | Must be at least `0` |
| `funding_per_business` | Yes | number | Must be at least `0` and no greater than total amount |
| `application_start_date` | Yes | date | Date applications open |
| `application_deadline` | Yes | date | Must be after the application start date |
| `program_type` | Yes | string | `single_round` or `multi_round` |
| `total_rounds` | Conditional | integer | Multi-round requires `2`–`10`; single-round is stored as `1` |
| `use_match_score` | No | boolean | Defaults to `true`; accepts `1`/`0` |
| `match_score_limit` | Conditional | integer or `null` | `1`–`100`; threshold for owner-facing flag only. Stored as `null` when scoring is off |
| `application_round.rubric_mode` | No | string | `weighted`, `simple_total`, or `pass_fail`; defaults to `weighted` |
| `application_round.advancement_mode` | No | string | `manual`, `score_threshold`, or `fixed_quota`; defaults to `manual` |
| `application_round.*` | No | object | Optional Round 1 setup; see [Rounds](rounds/rounds.md) |
| `disbursement_type` | No | string | `supplier`, `hybrid`, or `beneficiary` |
| `available_amount` | No | number | Defaults to `total_program_amount` |
| `currency` | No | string | `KES` or `USD`; defaults to `KES` |
| `max_awardees` | No | integer | Defaults from total amount and funding per business |
| `require_kyc_verification_to_apply` | No | boolean | Defaults to `false` |
| `status` | No | string | Defaults to `draft` |
| `visible` | No | boolean | Defaults to `true` |

**Example request**

```text
program_industry_id=1
program_title=Youth Agribusiness Grant 2027
total_program_amount=16000000
funding_per_business=250000
application_start_date=2027-01-10
application_deadline=2027-03-31
program_type=multi_round
total_rounds=3
disbursement_type=hybrid
use_match_score=1
match_score_limit=50
application_round[rubric_mode]=weighted
application_round[advancement_mode]=manual
```

**Example response**

```json
{
  "message": "Program created successfully",
  "program": {
    "id": 12,
    "program_title": "Youth Agribusiness Grant 2027",
    "program_type": "multi_round",
    "total_rounds": 3,
    "use_match_score": true,
    "match_score_limit": 50
  },
  "firstRound": {
    "id": 7,
    "round_number": 1,
    "round_name": "Application Round",
    "rubric_mode": "weighted",
    "advancement_mode": "manual"
  },
  "rounds": [{"id": 7, "round_number": 1}]
}
```

The response includes the created program and first round. Build scoring questions after obtaining `firstRound.id`; score settings are not sent in the round setup payload.

## Update score settings

**Request** `POST /api/v1/programs/programs/{program}`

| Field | Required | Type | Notes |
|---|---:|---|---|
| `use_match_score` | No | boolean | `false` disables scoring and clears saved application scores; question rules stay stored |
| `match_score_limit` | No | integer or `null` | `1`–`100`; never automatically rejects an applicant |

**Example**

```json
{
  "use_match_score": true,
  "match_score_limit": 60
}
```

Changing the threshold changes the below-threshold flag without changing the score. Enabling or disabling scoring recalculates existing Round 1 applications.

## Duplicate a program

`POST /api/v1/programs/{program}/duplicate` copies its rounds and questions. Scoring definitions are copied only for the copied Round 1; any question in Round 2+ has `use_in_match_score: false`, `match_points: null`, and `match_answers: null`.

## Errors

| HTTP | Condition | Client action |
|---:|---|---|
| `401` | Missing or invalid authentication | Authenticate and retry |
| `403` | Caller does not own the program or organization | Use an authorized program-owner account |
| `404` | Program or industry does not exist | Refresh the selected resource |
| `422` | Invalid dates, amounts, rounds, or score threshold | Display the returned field errors |
| `500` | Unexpected server error | Retry safely; confirm the write did not succeed before resubmitting |

## Business rules

- Program creation creates Round 1. The application form and all custom questions are attached to this round.
- Match scoring defaults on for new programs unless `use_match_score` is explicitly false.
- When scoring is off at creation, `match_score_limit` is stored as `null`.
- The threshold only marks an application for program-owner review; it is not an eligibility gate.
- Round 1 scored-question points total no more than `100`. Rounds 2+ cannot score match answers.
- `bonus_points` is not part of match-score calculation.
- The database has no program-level custom-field scoring schema; use Round 1 questions instead.


## AI-assisted and AI-only review

A round can use `review_method` `manual` (default), `ai_assisted` or `ai_only`, chosen per round and locked after publish. See [AI review](ai-review.md) for the fields, endpoints, report format and privacy gate.
