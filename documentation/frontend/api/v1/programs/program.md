## Programs

**Authentication**

Bearer token required. Organization users manage their own records.

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/programs` | List accessible programs |
| GET | `/public-programs` | List public programs |
| GET | `/get_program/{id}` | Get program details |
| POST | `/create-program` | Create a program |
| POST | `/programs/{program}` | Update a program |
| DELETE | `/delete-program/{id}` | Delete a program |
| POST | `/programs/{program}/duplicate` | Duplicate a program |
| GET | `/visibility/{program_id}` | Toggle visibility |
| GET | `/store-watchlist/{pitch_id}` | Toggle watchlist entry |
| GET | `/get-watchlist` | Get watchlist |
| POST | `/update-profile` | Update organization profile |
| POST | `/update-user` | Update organization user |
| POST | `/delete-user` | Delete organization user |
| POST | `/delete/role-user` | Remove organization role user |
| GET | `/accept-invitation` | Accept invitation |
| POST | `/program-milestone-release-bulk` | Release first milestones |

## Create Program

**Endpoint** `POST /api/v1/programs/create-program`

**Request Body**

| Field | Type | Required | Description |
|---|---|---|---|
| program_title | string | Yes | Display title |
| total_program_amount | numeric | Yes | Available budget |
| funding_per_business | numeric | Yes | Per-applicant amount |
| program_type | string | Yes | `single_round` or `multi_round` |
| program_focus | array | Yes | Focus areas |
| startup_stage_focus | array | Yes | Eligible stages |
| start_date | date | Yes | Start date |
| application_deadline | date | Yes | Future deadline |
| application_round | object | Yes | Initial round configuration |

**Example Response — 201 Created**

```json
{"success":true,"message":"Program created.","data":{"id":1}}
```

**Error Responses**: `401` unauthenticated, `403` unauthorized, `422` validation failure, `404` not found.
