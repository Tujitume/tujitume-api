# How program application match score works

This walkthrough creates a program with three scoring fields, submits an application, then calculates the result by hand. Replace example IDs, dates, and credentials with values from your environment.

## 1. Create a program and scoring fields

First authenticate as the owning organization and ensure `program_industry_id` exists. Create the program with inline custom fields:

**Request** `POST /api/v1/programs/create-program`

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

The controller currently responds with HTTP `200` and a `{ "message", "program" }` object. Use the returned program `id`, then verify generated field keys and definitions:

**Request** `GET /api/v1/programs/{program_id}/fields`

The keys in this example are `interested-places`, `business-age-years`, and `business-plan-pdf`. The program must also have a configured, open first round before submission. Create/configure and publish the round using the [round endpoints](rounds/rounds.md); creating the program alone does not open applications.

## 2. Submit an application

Authenticate as the business owner and use a `business_id` owned by that account. Submit the scalar and array answers as multipart form data because the form includes a required file:

```text
POST /api/v1/programs/12/applications
Content-Type: multipart/form-data

business_id=84
startup_name=Northstar Foods
contact_person_name=Amina Otieno
contact_person_email=amina@example.test
sector=Food processing
headquarters_location=Nairobi, Kenya
total_amount_requested=40000
custom_answers[interested-places][]=Niagara
custom_answers[interested-places][]=NY
custom_answers[business-age-years]=6
custom_answers[business-plan-pdf]=<attach a PDF file>
```

When the first round has knockout questions, include `round_answers` entries with each required `question_id` and response. Round custom questions are separate from program custom fields.

Do not include `match_score` or `score_breakdown`. If sent anyway, those client values are not trusted; the server computes the application score from the field definitions and answers.

## 3. Calculate the match score

For these answers:

| Field | Applicant answer | Target / rule | Match |
|---|---|---|---:|
| Interested places | `["Niagara","NY"]` | Target is `["Niagara","London"]`, proportional | `1 / 2 = 0.5` |
| Business age (years) | `6` | Inclusive range `2`–`10` | `1` |
| Business plan PDF | Uploaded PDF | `provided` | `1` |

The total non-bonus weight is `20 + 30 + 10 = 60`. Normalize:

```text
Interested places:     20 / 60 × 100 = 33.33; earned 33.33 × 0.5 = 16.67
Business age (years):  30 / 60 × 100 = 50.00; earned 50.00 × 1.0 = 50.00
Business plan PDF:     10 / 60 × 100 = 16.67; earned 16.67 × 1.0 = 16.67
Base score:            16.67 + 50.00 + 16.67 = 83.34
Stored integer score:  83 (half-up rounding)
```

`POST /api/v1/programs/match-score/12` uses the same server-side engine for preview and returns only the integer score plus applicant-safe status values. It does not expose weights, targets, or the full breakdown.

The PO's intended historic breakdown records each field's match, answer, target, configured and normalized weights, and earned points. The existing application serializer currently hides `score_breakdown` from all callers and does not add custom answers to the response; see [Match-score engine](match-score-engine.md#applicant-vs-program-owner-resources) for this known API gap.

## Business rules

- Only active, non-archived application fields participate.
- Unanswered optional scoring fields match `0` and remain in the base denominator.
- Bonus field weights are added as points only after the base score reaches `match_score_limit`; `null` means no threshold.
- The final score is capped at `100`.
- If no non-bonus scoring fields exist, the score is `null`.
- The saved score and breakdown are snapshots; later edits to field scoring must not rewrite historic application scores.
