# Batches

## POST `/` (multipart) create
| Field | Type | Required | Notes |
|---|---|---|---|
| source | `file` `text` `google_form_url` `google_api` | yes | `google_api` reads a form through the connected Google account |
| start_point | start point key | one of | multi-sheet XLSX bundle |
| type | sheet type or `form` | one of | single data type, or a form import |
| file | `.csv` `.xlsx` | when source=file | max 10 MB, 5000 rows, 80 columns; extension and magic bytes checked |
| attachments | `.zip` | no | applicant files, pdf/doc/docx/ppt/pptx, max 500 files, 20 MB each, 200 MB unpacked |
| text, url | string | by source | pasted data or questions; public Google Forms link |
| program_id | int | no | add to an existing program of the organization |
| round_id / round_number | int | no | target round of that program when rows carry no round (404 `round_not_found`, 422 `program_required`) |
| options.* | | no | `on_duplicate` skip/update/fail, `mode` partial/all_or_nothing, `link_existing`, `update_existing`, `date_format`, `default_currency`, `default_country_code`, `compute_match_score`, `queue_ai_review_after_import`, `raise_max_awardees`, `adjust_program_balance`, `mark_checkpoints_sent` |

201 `data` = batch resource:
```json
{ "id": 7, "program_id": null, "start_point": "review", "type": "review", "source": "file", "status": "uploaded",
  "original_filename": "program.xlsx", "options": {"mode":"partial","on_duplicate":"skip"}, "mapping": null, "form_spec": null,
  "readiness": null, "landing_preview": null, "stats": {"audit":[{"at":"2026-11-16T10:00:00+00:00","user_id":4,"action":"uploaded"}]},
  "rows": {"total":0,"by_status":[]}, "ai_usage": null, "error_summary": null, "consent_confirmed_at": null }
```
Errors (`errors.code`): `invalid_file`, `invalid_attachments`, `program_not_found` (404), `form_private`, `form_url`, `form_unreachable`, `form_empty`, `form_too_large`, `too_many_redirects`, `invalid_type`.

## GET `/`, GET `{batch}`, DELETE `{batch}`, GET `{batch}/file`
List (`per_page` max 100) returns `{ items, meta }`. Show returns the resource above plus mapping, readiness, `stats.sheets` (headers and row counts per sheet), `stats.validation`, `stats.commit` (`imported, failed, skipped, by_sheet, program_id`), `stats.audit`. DELETE cancels before commit (422 `invalid_state` after). `file` returns a 10-minute temporary link (422 `no_temporary_url` if the storage cannot sign links, 404 `file_removed` after purge).

## Status flow
`uploaded -> parsing -> needs_mapping -> mapped -> validating -> ready | has_errors -> importing -> completed | completed_with_errors | failed`, plus `cancelled`, `rolled_back`.

## Business rules
Everything an organization sees is scoped to its own batches (others are 404). Cells are untrusted: HTML is stripped, text beginning with `= + - @` is stored with a leading quote, spreadsheet formulas are never evaluated, XML entities are refused, and size/row/column caps apply.
