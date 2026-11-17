# Catalog and templates

All endpoints need `auth:sanctum` and an organization owner/team member (viewers read only). Prefix `/api/v1/program-imports`.

## GET `start-points`
Returns the six start points. Each item:

| Field | Type | Notes |
|---|---|---|
| key | string | `setup, review, finalization, funding_setup, disbursement, monitoring` |
| label, summary | string | display text |
| required, optional | string[] | sheet types the start point needs / accepts |
| next_steps | string[] | what the owner does next, using existing screens |
| landing | object | statuses the program, rounds, applications and milestones will have |
| skipped | string[] | what is not imported and why it matters |

```json
{ "success": true, "message": "Start points", "data": [ { "key": "review", "label": "Start at review",
  "required": ["program","rounds","applications"], "optional": ["questions"],
  "summary": "Applications arrive already submitted; earlier rounds are marked finalized. Start scoring or deciding.",
  "next_steps": ["Assign Business Coaches or score applications","Accept or reject (round 1)","Finalize the round"],
  "landing": {"program":"under_review, hidden","rounds":"earlier rounds finalized; the target round published with past dates so it can be reviewed and finalized","applications":"round 1: pending + submitted; round 2+: approved + submitted","milestones":"none"},
  "skipped": ["Earlier-round history is synthetic; reviewers, site visits and payments are not imported.","The target round must have a review period end date in the past before Finalize works."] } ] }
```

## GET `types`
`data.types[]` = `{ type, label, depends_on[], fields[] }`; each field `{ key, label, type, required, synonyms[], enum, default, target, rules[] }`. Also `data.form`. See `field-dictionary.md` (generated).

## GET `templates/{name}?format=xlsx|csv`
`name` is a start point (XLSX bundle with an Instructions sheet and one sheet per type, enum dropdowns) or a single type (CSV or XLSX). Errors: 404 `unknown_template`; 422 `bundle_requires_xlsx`. Ready-made files are in `templates/`.
