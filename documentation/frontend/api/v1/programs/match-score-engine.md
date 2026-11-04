# Match-score engine

The match-score engine evaluates submitted custom answers against active scoring definitions on the server. Client-provided scores are not authoritative. Applicants may see their numeric result and per-field match status; scoring weights and targets are PO-only.

## Scoring configuration

Set `is_scoring: true` and provide `scoring` on an application field:

```json
{
  "enabled": true,
  "weight": 20,
  "match_type": "in_set",
  "target": ["Niagara", "London"],
  "mode": "proportional"
}
```

`weight` must be greater than zero and at most 1000. Weights are relative; they do not have to sum to 100. A scoring field must have `audience: "application"`.

## Match-type matrix

| `match_type` | Allowed field types | Target | Evaluation |
|---|---|---|---|
| `in_set` | `select`, `multi_select` | Non-empty list of option values | Select: `1` when the selected value is in the target set, else `0`. Multi-select supports `any`, `proportional`, and `all` |
| `range` | `number`, `date` | `{ "min": ..., "max": ... }`; at least one bound | `1` when answer is within the inclusive range, else `0` |
| `equals` | `boolean` | `true` or `false` | `1` when answer equals target, else `0` |
| `provided` | Any field type | None | `1` when a non-empty answer/file exists, else `0` |

Text, textarea, and URL fields may only use `provided`.

### Multi-select `mode`

| Mode | Match result |
|---|---|
| `any` | `1` if at least one answer overlaps the target; otherwise `0` |
| `proportional` | `count(answer ∩ target) / count(target)` |
| `all` | `1` only if every target value is present in the answer; otherwise `0` |

## Base score and bonuses

1. Select active, non-archived application scoring fields.
2. Score each field as a match fraction from `0` to `1`. An unanswered field is `0` but remains in the denominator.
3. Exclude bonus fields from the base calculation. For non-bonus fields:

   `base = (Σ(weight × match) / Σ(weight)) × 100`

4. Round the base/final result half-up to an integer, bounded from `0` to `100`.
5. Add each matched bonus field's weight as points only when the base score meets `program.match_score_limit`. A `null` limit means bonus-eligible at any base score.
6. Cap the final score at `100`.

If there are no non-bonus scoring fields, there is no base score (`null`). A bonus field alone does not create a score.

## Worked example: the three requested fields

Definitions:

| Field | Weight | Scoring definition |
|---|---:|---|
| Interested places | 20 | `in_set`, `proportional`; target `["Niagara","London"]` |
| Business age (years) | 30 | `range`; target `{"min":2,"max":10}` |
| Business plan PDF | 10 | `provided` |

Suppose the applicant answers `["Niagara","NY"]`, `6`, and uploads the PDF:

| Field | Match | Normalized weight | Earned base points |
|---|---:|---:|---:|
| Interested places | `1 / 2 = 0.5` | `20 / 60 × 100 = 33.33` | `33.33 × 0.5 = 16.67` |
| Business age (years) | `1` | `30 / 60 × 100 = 50.00` | `50.00` |
| Business plan PDF | `1` | `10 / 60 × 100 = 16.67` | `16.67` |
| **Total** |  | **100.00** | **83.34 → 83** |

The score is `83` after half-up integer rounding. If Niagara and London were both selected, the first match would be `1`, producing a base score of `100`.

## Preview endpoint

**Request** `POST /api/v1/programs/match-score/{program_id}`

```json
{
  "custom_answers": {
    "interested-places": ["Niagara", "NY"],
    "business-age-years": 6,
    "business-plan-pdf": "present"
  }
}
```

**Applicant-safe response**

```json
{
  "match_score": 83,
  "breakdown_for_applicant": [
    {
      "field_id": 41,
      "key": "interested-places",
      "label": "Interested places",
      "status": "partial"
    },
    {
      "field_id": 42,
      "key": "business-age-years",
      "label": "Business age (years)",
      "status": "matched"
    },
    {
      "field_id": 43,
      "key": "business-plan-pdf",
      "label": "Business plan PDF",
      "status": "matched"
    }
  ]
}
```

The preview output must never contain a weight, target, or raw scoring configuration. The route inherits API throttling. File previews require the server to receive a file or a trusted indication that a file is already present; do not send a local file path as an answer.

## Stored score and PO breakdown

On application submission, the server stores `match_score` as an integer and the full `score_breakdown` snapshot on the application. A breakdown item has this shape:

```json
{
  "field_id": 41,
  "key": "interested-places",
  "label": "Interested places",
  "match_type": "in_set",
  "weight": 20,
  "normalized_weight": 33.33,
  "match": 0.5,
  "earned_points": 16.67,
  "answer": ["Niagara", "NY"],
  "target": ["Niagara", "London"]
}
```

The snapshot is historical evidence. Editing scoring configuration later must not cause old applications to be silently rescored.

## Applicant-vs-program-owner resources

| Data | Applicant/public | Program owner |
|---|---|---|
| Application-form metadata | Public active fields: label, key, type, options, required flag, help text, validation | Full field definitions through `GET /{program}/fields` |
| Weights and targets | Never exposed | Available in field management and full score breakdown |
| Match preview | Numeric match score and field status (`matched`, `partial`, `not matched`) | Not a substitute for application detail |
| Submitted custom answers | Applicant's own answers, subject to resource contract | Field ID/key/label/type and saved value |
| Historic score breakdown | Applicant-safe result only | Full server-calculated snapshot |

### Current implementation gaps

- `GET /{program}/fields` exposes the configured raw `weight` in a property named `normalized_weights`; it does not calculate normalized percentages.
- The field-definition service does not yet fully validate weight bounds and all target/type/mode combinations described by the matrix; clients should still submit only valid combinations, but server validation must be completed.
- `ProgramApplication` currently hides `score_breakdown` for every caller and the application controller does not append the `custom_answers` projection. Thus the PO-specific resource shape above is the required contract but is not yet realized by current serialization.
- `application_fields` correctly omits the scoring properties, and `POST /match-score/{program_id}` strips the detailed engine output down to applicant-safe statuses.
- Custom file preview/submission and validation metadata need a complete file-storage/validation path; consult [Custom fields](custom-fields.md#important-implementation-notes).

## Errors and scoring rules

| HTTP / result | Meaning |
|---|---|
| `200`, `match_score: null` | No base scoring fields are configured |
| `200`, integer `match_score` | Score computed from the current answer set |
| `401` | Authentication missing or invalid |
| `403` | Caller is not permitted to access the requested program operation |
| `404` | Program does not exist |
| `422` | Invalid answer or application does not satisfy program submission rules |

Scoring config is PO-controlled and must be validated server-side. Option values are checked against the configured options. Never calculate or persist a score supplied by the browser.
