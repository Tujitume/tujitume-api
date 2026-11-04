# Custom-field migration notes for existing frontends

This page lists the frontend changes needed when moving from fixed program properties to custom field definitions.

## Removed program properties

Do not read or send the following as program attributes:

`eligibility_criteria`, `required_documents`, `program_focus`, `regions`, `startup_stage_focus`, `impact_objectives`, `social_impact_areas`, `bonus_points`, `evaluation_criteria`, `funder_name`, `funder_type`, `program_brief_pdf`, `start_date`, and `end_date`.

| Old UI concept | New location |
|---|---|
| Eligibility, focus, region, stage, impact, and similar applicant questions | `program_custom_fields` definitions with `audience: "application"`; answers submitted under `custom_answers` |
| Program description or other owner-authored information | `program_custom_fields` with `audience: "info"`; values written to `PUT /{program}/info-values` |
| Program open date | `application_start_date` |
| Application close date | `application_deadline` |
| Industry classification | `program_industry_id` remains a core system field |
| Round-specific required documents/questions | Round APIs, not program custom fields |

Use the program's `application_fields` response to render public questions. It includes field `key`, label, type, options, required flag, help text, and validation metadata, but not scoring configuration.

## New application payload

Submit custom answers keyed by the exact immutable field key:

```json
{
  "business_id": 84,
  "startup_name": "Northstar Foods",
  "contact_person_name": "Amina Otieno",
  "contact_person_email": "amina@example.test",
  "sector": "Food processing",
  "headquarters_location": "Nairobi, Kenya",
  "total_amount_requested": 40000,
  "custom_answers": {
    "interested-places": ["Niagara", "NY"],
    "business-age-years": 6
  }
}
```

For file fields, use `multipart/form-data` and the input name `custom_answers[field-key]`. For multi-select fields, submit an array of option `value`s. Round questions and documents remain separate inputs (`round_answers`, `round_documents`).

## Client score values are ignored

Do not calculate or submit a score as an authoritative value. `match_score` and `score_breakdown` sent by older clients are ignored; the backend computes scores from the current server-side definitions and submitted custom answers. The preview endpoint is `POST /match-score/{program_id}` with `{ "custom_answers": { ... } }`.

Applicant-facing resources must not expose scoring weights or targets. The PO's field-management response contains scoring definitions; never reuse that response in a public form.

## Frontend rollout checklist

- [ ] Remove form bindings and payload properties for removed fixed program fields.
- [ ] Read `application_fields` to render dynamic questions and preserve returned field keys.
- [ ] Submit answers in `custom_answers` and upload custom files using multipart field names.
- [ ] Keep round-question answers/documents on the existing round-specific API.
- [ ] Stop treating client-side match score or breakdown as authoritative.
- [ ] Use `application_start_date` / `application_deadline` for program date display.
- [ ] Test archived fields, required fields, option values, and the open/closed application state.
- [ ] Do not rely on custom file `validation` metadata as a security boundary until the runtime file path enforces it.

## Known server-side follow-up

The application controller currently continues to validate some legacy **application-level** columns such as `sector`, `headquarters_location`, and `social_impact_areas`; these are distinct from removed `programs` table columns. Custom field `validation` metadata is not fully enforced, custom file upload persistence needs completion, and the PO application serializer currently hides the score breakdown and omits the `custom_answers` projection. Coordinate a frontend release with those API follow-ups; see [Applications](applications.md) and [Match-score engine](match-score-engine.md).
