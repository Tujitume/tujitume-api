## Create Program

Creates a draft program for the authenticated organization owner and provisions its initial round and wallet. Only an organization user may create a program; the owner is always derived from the Bearer token.

**Endpoint** `POST /api/v1/programs/create-program`

**Request Body**

| Field | Required | Type | Allowed values / notes |
|---|---|---|---|
| `program_title` | Yes | string | Maximum 255 characters |
| `total_program_amount` | Yes | numeric | Must be zero or greater |
| `funding_per_business` | Yes | numeric | Must not exceed total amount |
| `program_type` | Yes | string | `single_round`, `multi_round` |
| `program_focus` | Yes | array | Program focus areas |
| `startup_stage_focus` | Yes | array | Eligible business stages |
| `start_date` | Yes | date | Program start date |
| `application_deadline` | Yes | date | Must be in the future |
| `application_round` | Yes | object | Initial round settings |
| `total_rounds` | No | integer | 1–10; multi-round must be greater than 1 |
| `currency` | No | string | Defaults to KES |

**Example Request**

```json
{"program_title":"Green Enterprise Fund","total_program_amount":500000,"funding_per_business":50000,"program_type":"multi_round","total_rounds":2,"program_focus":["agriculture"],"startup_stage_focus":["growth"],"start_date":"2026-10-01","application_deadline":"2026-09-15","application_round":{"round_name":"Application","rubric_mode":"weighted","advancement_mode":"manual"}}
```

**Example Response — 201 Created**

```json
{"success":true,"message":"Program created successfully","data":{"program":{"id":12,"program_title":"Green Enterprise Fund","status":"draft","max_awardees":10},"first_round":{"id":7,"round_number":1,"status":"draft"}}}
```

**Error Responses**

| Status | Condition |
|---:|---|
| 401 | Missing or invalid Bearer token |
| 403 | Account has no organization profile |
| 422 | Invalid request data or incompatible round count |

**Business Rules**: the maximum awardee count is calculated from total funding divided by funding per business. A multi-round program cannot have only one round.

## Other Program Operations

| Method | Endpoint | Caller and rule |
|---|---|---|
| GET | `/programs` | Owner receives owned programs; public users receive non-draft records |
| GET | `/public-programs` | Returns programs visible to the public |
| GET | `/get_program/{id}` | Returns a program and first round; 404 if absent |
| POST | `/programs/{program}` | Owner updates own program |
| DELETE | `/delete-program/{id}` | Owner only; blocked by active applications or wallet history |
| POST | `/programs/{program}/duplicate` | Owner only; creates a draft copy |
| GET | `/visibility/{program_id}` | Owner toggles visibility |
| GET | `/store-watchlist/{pitch_id}` | Toggles watchlist membership |
| GET | `/get-watchlist` | Returns current user watchlist |
