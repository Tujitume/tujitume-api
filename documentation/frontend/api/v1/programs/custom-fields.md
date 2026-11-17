# Program custom fields

A program owner (PO) fills in only the core program fields. Everything else is a PO-defined **custom field** stored as rows (never columns). Application fields are answered by applicants and may be **scoring factors**; info fields describe the program. Base path `/api/v1/programs`; owner endpoints need the organization owner or an admin/editor.

## Field object
| Field | Type | Notes |
|---|---|---|
| id, program_id, key | | `key` is generated from the label (slug, de-duplicated) and never changes |
| label | string, max 150 | HTML stripped |
| help_text | string | |
| audience | `application` `info` | info fields are never scored |
| type | `text textarea number boolean date select multi_select file url` | cannot change after creation |
| is_required | bool | |
| options | string[] (1..100) | select / multi_select only |
| validation | object | `max_length`, `min`, `max`, `mimes[]`, `max_kb` |
| is_scoring, is_bonus, scoring | | see match-score-engine.md |
| scoring_suggestion | object | set by imports; never active until the owner enables it |
| is_public, is_archived, display_order | | |

Limits (`config/program_fields.php`): 50 active fields per program, 100 options, 5000 characters of text, files up to 20 MB (pdf, doc, docx, png, jpg by default).

## Endpoints
| Method | Path | Notes |
|---|---|---|
| GET | `{program}/fields` | fields incl. scoring, `usage_count`, `scoring_summary` (`has_scoring_field`, `normalized_weights`) |
| POST | `{program}/fields` | create; 422 when the 51st active field or a bad scoring config |
| PATCH | `fields/{field}` | always: label, help_text, display_order, is_public. Before applications exist also: is_required, options, validation, is_scoring, scoring, is_bonus. Afterwards: only appending options. `type` / `audience` are rejected |
| DELETE | `fields/{field}` | hard delete when unused, otherwise archived (`data.result` = `deleted` / `archived`) |
| POST | `{program}/fields/reorder` | `{ ordered_ids: [] }` |
| PUT | `{program}/info-values` | `{ values: { key: value } }` (files multipart) |
| POST | `create-program` | accepts optional `custom_fields[]` (same rules, created in the same transaction) |
| POST | `{program}/duplicate` | copies definitions and info values, never answers |

`get_program/{id}` adds `application_fields` (label, key, type, options, is_required, help_text, validation; **never** scoring, weights or targets) and `info`.

## Applying
`POST /api/v1/programs/{program}/applications` takes `custom_answers` keyed by field key (arrays for multi_select, a file for file fields). Rules are built from the stored definitions; option values are checked against stored options; text is stripped of HTML; files are checked by type and size. A client-sent `match_score` is ignored. When the program has active scoring fields the server score and `score_breakdown` come from the engine (`score_breakdown.source = "custom_fields"`), and the program's minimum match score is checked on that score.

`POST /api/v1/programs/match-score/{program_id}` with `custom_answers` returns a read-only preview: `{ match_score, below_threshold, breakdown_for_applicant: [{key,label,status}] }` where status is `matched`, `partial` or `not matched`.

## Example
```json
{ "label": "Interested places", "type": "multi_select", "options": ["Niagara","NY","London"], "is_scoring": true,
  "scoring": { "enabled": true, "weight": 20, "match_type": "in_set", "mode": "proportional", "target": ["Niagara","London"] } }
```
Errors are standard 422 validation errors keyed by field (`scoring.target`, `options`, `label`, ...); 403 for other organizations.
