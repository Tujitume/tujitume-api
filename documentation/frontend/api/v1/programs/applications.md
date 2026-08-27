## Applications

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/{program_id}/applications` | List program applications |
| GET | `/{program_id}/applications/awarded` | List awarded applications |
| GET | `/applications/{id}` | Get application |
| GET | `/my-applications` | List current user's applications |
| GET | `/{program_id}/sme/applications` | Applicant-facing program data |
| GET | `/my-applications/awarded` | Current user's awards |
| POST | `/{program}/applications` | Submit application |
| POST | `/applications/{pitch}/accept` | Accept application |
| POST | `/applications/{application}/planning-mode` | Set planning mode |
| GET | `/fund-release-request/{pitch_id}` | Request funding release |

## Submit Application

**Endpoint** `POST /api/v1/programs/{program}/applications`

**Request Body**: `business_id`, `startup_name`, `contact_person_name`, `contact_person_email`, `sector`, `headquarters_location`, `total_amount_requested`, `match_score`, and `score_breakdown` are required. Optional files are uploaded as multipart data.

**Error Responses**: `401`, `403`, `404`, and `422`. An applicant may submit only to a program with an available round.
