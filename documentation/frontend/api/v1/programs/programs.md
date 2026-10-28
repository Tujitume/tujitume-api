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
| `match_score_limit` | No | integer/null | 1–100; bonus points count only when the base match score reaches this value |

**Example Request**

```json
{"program_title":"Green Enterprise Fund","total_program_amount":500000,"funding_per_business":50000,"program_type":"multi_round","total_rounds":2,"program_focus":["agriculture"],"startup_stage_focus":["growth"],"start_date":"2026-11-01","application_deadline":"2027-01-15","match_score_limit":70,"application_round":{"round_name":"Application","rubric_mode":"weighted","advancement_mode":"manual"}}
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
| POST | `/{program}/duplicate` | Owner only; creates a draft copy (current path) |
| POST | `/programs/{program}/duplicate` | Legacy duplicate path |
| GET | `/visibility/{program_id}` | Owner toggles visibility |
| GET | `/store-watchlist/{pitch_id}` | Toggles watchlist membership |
| GET | `/get-watchlist` | Returns current user watchlist |

## List response and application state

`GET /programs` and `GET /public-programs` include `accepting_applications` and `applications_closed_reason` per program. Use those fields to control the Apply action. An unpublished program, absent first round, closed/finalized first round, later open round, future open date, or passed deadline can block new applications.

Program list responses retain the top-level `programs` array and also return `{ "success":true,"message":"Programs fetched successfully.","data":{"items":[...]} }`. Supply `page` or `per_page` to paginate; then `data.pagination` contains `current_page`, `per_page`, `total`, `last_page`, `from`, and `to`. `per_page` defaults to 15 when paginating and is clamped to 1–100. Status metadata objects contain `value`, `label`, and `color`.

`match_score_limit` only gates program bonus points; it is separate from the 50% minimum match score required to submit an application. `null` leaves bonuses unrestricted.
