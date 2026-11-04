# Program custom fields

Custom fields let a program owner define application questions and program information without adding program-specific database columns. Field definitions are stored per program; application answers and info values are stored separately.

All endpoints are under `/api/v1/programs`. Authenticate with Sanctum. Field mutations require access to the owning program.

## Endpoints

| Method | Endpoint | Purpose |
|---|---|---|
| `GET` | `/{program}/fields` | List fields, scoring config, archive state, usage counts, and scoring summary |
| `POST` | `/{program}/fields` | Create one field; its key is generated from its label |
| `PATCH` | `/fields/{field}` | Edit allowed properties |
| `DELETE` | `/fields/{field}` | Delete an unused field or archive a field that has values |
| `POST` | `/{program}/fields/reorder` | Set display order |
| `PUT` | `/{program}/info-values` | Save values for program info fields; use multipart for files |

`custom_fields` may also be sent as an array when creating a program. Program updates do not accept field definitions; use these endpoints instead.

## Field definition

| Property | Required | Type | Notes |
|---|---:|---|---|
| `label` | Yes | string | Maximum 150 characters. Key is slugged from the label and de-duplicated |
| `type` | Yes | string | `text`, `textarea`, `number`, `boolean`, `date`, `select`, `multi_select`, `file`, `url` |
| `audience` | No | string | `application` (default) or `info` |
| `help_text` | No | string or `null` | Helper content shown with the question |
| `is_required` | No | boolean | Defaults to `false` |
| `options` | Conditional | array | Option list for `select`/`multi_select`; each option is `{value,label}` |
| `validation` | No | object | Type-specific constraints, for example `{min,max}`, `{max_length}`, or `{mimes,max_kb}` |
| `is_scoring` | No | boolean | Only application fields may be scoring fields |
| `scoring` | Conditional | object | Scoring definition; see [Match-score engine](match-score-engine.md) |
| `is_bonus` | No | boolean | If true, weight is bonus points rather than normalized base weight |
| `display_order` | No | integer | Controls form order |
| `visibility` | No | string | Info-field visibility: `public` or `owner_only`; defaults to `public` |

Field keys are immutable after creation. They are the names used in `custom_answers` and `values` request objects.

## Create fields

### Interested places

**Request** `POST /api/v1/programs/12/fields`

```json
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
  },
  "is_bonus": false,
  "display_order": 1,
  "visibility": "public"
}
```

**Response — 201 Created**

```json
{
  "success": true,
  "message": "Program field created successfully.",
  "data": {
    "id": 41,
    "program_id": 12,
    "key": "interested-places",
    "label": "Interested places",
    "help_text": null,
    "audience": "application",
    "type": "multi_select",
    "is_required": true,
    "options": [
      {"value": "Niagara", "label": "Niagara"},
      {"value": "NY", "label": "NY"},
      {"value": "London", "label": "London"}
    ],
    "validation": null,
    "is_scoring": true,
    "scoring": {
      "enabled": true,
      "weight": 20,
      "match_type": "in_set",
      "target": ["Niagara", "London"],
      "mode": "proportional"
    },
    "is_bonus": false,
    "display_order": 1,
    "visibility": "public",
    "is_archived": false,
    "created_by": 7,
    "created_at": "2026-11-04T17:10:00.000000Z",
    "updated_at": "2026-11-04T17:10:00.000000Z"
  }
}
```

IDs, timestamps, and `created_by` are assigned by the server. The key shown assumes it is available; if a key collides, the server adds a suffix.

### Business age (years)

**Request** `POST /api/v1/programs/12/fields`

```json
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
  },
  "is_bonus": false,
  "display_order": 2,
  "visibility": "public"
}
```

**Response — 201 Created**

```json
{
  "success": true,
  "message": "Program field created successfully.",
  "data": {
    "id": 42,
    "program_id": 12,
    "key": "business-age-years",
    "label": "Business age (years)",
    "help_text": null,
    "audience": "application",
    "type": "number",
    "is_required": true,
    "options": null,
    "validation": null,
    "is_scoring": true,
    "scoring": {
      "enabled": true,
      "weight": 30,
      "match_type": "range",
      "target": {"min": 2, "max": 10}
    },
    "is_bonus": false,
    "display_order": 2,
    "visibility": "public",
    "is_archived": false,
    "created_by": 7,
    "created_at": "2026-11-04T17:10:01.000000Z",
    "updated_at": "2026-11-04T17:10:01.000000Z"
  }
}
```

### Business plan PDF

**Request** `POST /api/v1/programs/12/fields`

```json
{
  "label": "Business plan PDF",
  "audience": "application",
  "type": "file",
  "is_required": true,
  "validation": {
    "mimes": ["pdf"],
    "max_kb": 5120
  },
  "is_scoring": true,
  "scoring": {
    "enabled": true,
    "weight": 10,
    "match_type": "provided"
  },
  "is_bonus": false,
  "display_order": 3,
  "visibility": "public"
}
```

**Response — 201 Created**

```json
{
  "success": true,
  "message": "Program field created successfully.",
  "data": {
    "id": 43,
    "program_id": 12,
    "key": "business-plan-pdf",
    "label": "Business plan PDF",
    "help_text": null,
    "audience": "application",
    "type": "file",
    "is_required": true,
    "options": null,
    "validation": {"mimes": ["pdf"], "max_kb": 5120},
    "is_scoring": true,
    "scoring": {
      "enabled": true,
      "weight": 10,
      "match_type": "provided"
    },
    "is_bonus": false,
    "display_order": 3,
    "visibility": "public",
    "is_archived": false,
    "created_by": 7,
    "created_at": "2026-11-04T17:10:02.000000Z",
    "updated_at": "2026-11-04T17:10:02.000000Z"
  }
}
```

## Read fields

`GET /api/v1/programs/12/fields` returns all definitions, including archived fields and scoring configuration, for the program owner. Example:

```json
{
  "success": true,
  "message": "Program fields fetched successfully.",
  "data": {
    "fields": [
      {
        "id": 41,
        "key": "interested-places",
        "label": "Interested places",
        "help_text": null,
        "audience": "application",
        "type": "multi_select",
        "is_required": true,
        "options": [
          {"value": "Niagara", "label": "Niagara"},
          {"value": "NY", "label": "NY"},
          {"value": "London", "label": "London"}
        ],
        "validation": null,
        "is_scoring": true,
        "scoring": {
          "enabled": true,
          "weight": 20,
          "match_type": "in_set",
          "target": ["Niagara", "London"],
          "mode": "proportional"
        },
        "is_bonus": false,
        "display_order": 1,
        "visibility": "public",
        "is_archived": false,
        "usage_count": 8
      }
    ],
    "scoring_summary": {
      "has_scoring_fields": true,
      "normalized_weights": [
        {"field_id": 41, "key": "interested-places", "weight": 20}
      ]
    }
  }
}
```

The current `normalized_weights` response contains configured weights; it does not calculate normalized percentages in the list controller.

## Update and lock rules

`PATCH /api/v1/programs/fields/{field}` accepts these properties:

| Property | Before submitted applications | After submitted applications |
|---|---|---|
| `label`, `help_text`, `display_order`, `visibility` | Allowed | Allowed |
| `type`, `validation`, `is_scoring`, `scoring`, `is_bonus` | Allowed | Locked; request returns `422` |
| `options` | Replace options | Append only; existing options and order must be preserved |
| `is_required` | May change either way | May be relaxed (`true` to `false`), not tightened |
| `is_archived` | Allowed | Allowed; archive removes field from new forms and scoring |
| `key` | Never editable | Never editable |

Example update:

```json
{
  "label": "Primary places of interest",
  "help_text": "Select all that apply.",
  "display_order": 1
}
```

After submissions exist, appending an option sends the complete existing list followed by new values:

```json
{
  "options": [
    {"value": "Niagara", "label": "Niagara"},
    {"value": "NY", "label": "NY"},
    {"value": "London", "label": "London"},
    {"value": "Toronto", "label": "Toronto"}
  ]
}
```

## Delete, archive, reorder, and info values

- `DELETE /fields/{field}` hard-deletes a definition with no stored values. If it has values, it sets `is_archived=true` so historic data remains.
- `POST /{program}/fields/reorder` takes `{ "ordered_ids": [41, 42, 43] }`.
- `PUT /{program}/info-values` takes `{ "values": { "key": "value" } }`. File info values require multipart form data.
- Public application form projections exclude archived and `owner_only` fields. The owner list includes archived definitions.

## Limits and errors

| Limit / error | Rule |
|---|---|
| Active definitions | Maximum 50 per program |
| Options | Maximum 100 per field |
| Label | Maximum 150 characters |
| Text answer | Maximum 5000 characters |
| Default file cap | 20 MB; default configured extensions are PDF, DOC, DOCX, PNG, JPG |
| `401` | Authentication is missing or invalid |
| `403` | Caller does not own/manage the program |
| `404` | Program or field is not found |
| `422` | Invalid field definition, field limit exceeded, or locked property changed after submissions |

## Important implementation notes

The field schema supports per-field `validation` metadata, but the current application validator does not yet enforce every `min`/`max`/`mimes`/`max_kb` member. The current custom file validation also does not apply the configured MIME list, and its configured size rule needs correction. Custom file values need a durable upload/storage path before treating a saved file answer as durable. Do not rely on validation metadata alone as a security boundary until those runtime paths have been completed.
