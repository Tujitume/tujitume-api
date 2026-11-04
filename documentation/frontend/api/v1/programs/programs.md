# Programs

Program management endpoints are under `/api/v1/programs`. Requests use JSON unless noted. Program owners must be authenticated with Sanctum; program mutations are limited to the owning organization.

## Endpoints

| Method | Endpoint | Purpose |
|---|---|---|
| `POST` | `/create-program` | Create a program; optionally create its initial custom-field definitions |
| `POST` | `/programs/{program}` | Update core program settings |
| `GET` | `/programs` | List accessible programs |
| `GET` | `/public-programs` | List public programs |
| `GET` | `/get_program/{id}` | Get a program and its application form |
| `POST` | `/{program}/duplicate` | Duplicate a program and its field definitions |
| `DELETE` | `/delete-program/{id}` | Delete a program |
| `GET` | `/visibility/{program_id}` | Toggle visibility |

Custom-field definitions are created inline only when the program is created. For later field changes, use [Custom fields](custom-fields.md).

## Create a program

**Request** `POST /api/v1/programs/create-program`

| Field | Required | Type | Notes |
|---|---:|---|---|
| `program_title` | Yes | string | Maximum 255 characters |
| `program_industry_id` | Yes | integer | Must identify an existing program industry |
| `total_program_amount` | Yes | number | Must be at least `0` |
| `funding_per_business` | Yes | number | Must be at least `0` and no greater than `total_program_amount` |
| `program_type` | Yes | string | `single_round` or `multi_round` |
| `application_start_date` | Yes | date | Must be today or later |
| `application_deadline` | Yes | date | Must be after `application_start_date` |
| `organization_id` | No | integer | Server associates the authenticated organization |
| `available_amount` | No | number | Defaults to `total_program_amount` |
| `disbursement_type` | No | string | `supplier`, `hybrid`, or `beneficiary` |
| `total_rounds` | Conditional | integer | Multi-round requires at least `2`; single-round is set to `1` |
| `max_awardees` | No | integer | Defaults from total amount divided by funding per business |
| `currency` | No | string | Up to 3 characters; defaults to `KES` |
| `require_kyc_verification_to_apply` | No | boolean | Defaults to `false` |
| `match_score_limit` | No | integer or `null` | `1`–`100`; threshold for awarding configured bonus points |
| `status` | No | string | Defaults to `draft` |
| `visible` | No | boolean | Defaults to `true` |
| `custom_fields` | No | array | Definitions created in the same transaction. See [Custom fields](custom-fields.md) |

**Example request**

```json
{
  "program_title": "Green Enterprise Fund",
  "program_industry_id": 3,
  "total_program_amount": 500000,
  "available_amount": 500000,
  "funding_per_business": 50000,
  "disbursement_type": "hybrid",
  "program_type": "single_round",
  "total_rounds": 1,
  "max_awardees": 10,
  "currency": "KES",
  "application_start_date": "2026-12-01",
  "application_deadline": "2027-02-15",
  "require_kyc_verification_to_apply": false,
  "match_score_limit": null,
  "custom_fields": [
    {
      "label": "Interested places",
      "audience": "application",
      "type": "multi_select",
      "is_required": true,
      "options": [
        {"value": "Niagara", "label": "Niagara"},
        {"value": "NY", "label": "NY"},
        {"value": "London", "label": "London"}
      ],
      "is_scoring": true,
      "scoring": {
        "enabled": true,
        "weight": 20,
        "match_type": "in_set",
        "target": ["Niagara", "London"],
        "mode": "proportional"
      }
    },
    {
      "label": "Business age (years)",
      "audience": "application",
      "type": "number",
      "is_required": true,
      "is_scoring": true,
      "scoring": {
        "enabled": true,
        "weight": 30,
        "match_type": "range",
        "target": {"min": 2, "max": 10}
      }
    },
    {
      "label": "Business plan PDF",
      "audience": "application",
      "type": "file",
      "is_required": true,
      "validation": {"mimes": ["pdf"], "max_kb": 5120},
      "is_scoring": true,
      "scoring": {
        "enabled": true,
        "weight": 10,
        "match_type": "provided"
      }
    }
  ]
}
```

**Example response — current implementation**

```json
{
  "message": "Program created successfully",
  "program": {
    "id": 12,
    "public_id": "01JBF3W7Q8M2Y6K4T9P1C5V0RX",
    "program_title": "Green Enterprise Fund",
    "program_industry_id": 3,
    "total_program_amount": 500000,
    "available_amount": 500000,
    "funding_per_business": 50000,
    "program_type": "single_round",
    "total_rounds": 1,
    "currency": "KES",
    "application_start_date": "2026-12-01",
    "application_deadline": "2027-02-15",
    "require_kyc_verification_to_apply": false,
    "match_score_limit": null,
    "status": "draft",
    "visible": true
  }
}
```

The current controller returns HTTP `200` and does not include inline custom-field definitions in the program response. Fetch the field list with `GET /{program}/fields`.

## Update a program

**Request** `POST /api/v1/programs/programs/{program}`

The update endpoint accepts core program settings. It does not accept `custom_fields`; use the field-management endpoints for those. The update must preserve the date relationship: `application_deadline` follows `application_start_date`.

## Public program fields

`GET /get_program/{id}` and `GET /public-programs` expose active, public application-field metadata as `application_fields`. Each field has `id`, `key`, `label`, `type`, `options`, `is_required`, `help_text`, and `validation`. Scoring configuration, weights, and targets are not part of this projection.

The program response also includes `info` values for info fields. The current list/detail projection returns the stored value and does not sign file paths; never construct S3 URLs from a stored path or expose a private storage path. File info values need a signed-URL projection before frontend display is supported.

## Removed program properties

Do not read, submit, or expect these replaced fixed program fields: `eligibility_criteria`, `required_documents`, `program_focus`, `regions`, `startup_stage_focus`, `impact_objectives`, `social_impact_areas`, `bonus_points`, `evaluation_criteria`, `funder_name`, `funder_type`, `program_brief_pdf`, `start_date`, and `end_date`. Program-specific form data now belongs in custom-field definitions and values. `program_industry_id` remains a core system field.

## Errors

| HTTP | Condition | Client action |
|---:|---|---|
| `401` | Missing or invalid Sanctum authentication | Authenticate and retry |
| `403` | Caller has no organization or is not authorized for the program | Use an account with the owning organization role |
| `404` | Program or industry does not exist | Refresh the selected resource |
| `422` | Invalid field type, date, round count, amount, or custom-field definition | Display the returned validation errors |
| `500` | Unexpected server error | Retry safely; do not assume the write succeeded |

## Business rules

- The authenticated organization is used as the owner; clients must not rely on a supplied `organization_id` to claim another organization's program.
- `funding_per_business` cannot exceed `total_program_amount`.
- `multi_round` programs need at least two rounds; a single-round program is stored with one round.
- `application_start_date` is the canonical application opening date. The legacy `start_date` and `end_date` program columns are not part of the current contract.
- `match_score_limit` only gates bonus scoring fields. It is not a minimum base-score requirement.
- Duplicating a program copies custom-field definitions and program info values, but does not copy application answers.
- A new program needs a configured, open first round before applicants can submit. See [Applications](applications.md).
