## Milestone Verifications

All endpoints are under `/api/v1/programs` and require Sanctum authentication. Only the application applicant can submit evidence. Only the program owner can make a decision. Verification types are `mprv`, `mid_milestone`, and `final_approval`; the corresponding `*_required` flag must be enabled for the milestone.

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/milestones/{milestone}/verifications` | List evidence submissions |
| POST | `/milestones/{milestone}/verifications` | Submit MPRV, mid-milestone, or final-approval evidence |
| PATCH | `/verifications/{verification}/approve` | Approve a pending submission |
| PATCH | `/verifications/{verification}/reject` | Reject a pending submission |
| PATCH | `/verifications/{verification}/request-modification` | Return a pending submission for changes |
| PATCH | `/verifications/{verification}/request-audit` | Request an audit |

The resource routes also expose `GET`, `PUT`, `PATCH`, and `DELETE /verifications/{verification}`; the create/list endpoints are the applicant submission flow.

### Submit evidence

Send multipart form data when uploading files. Top-level fields:

| Field | Required | Validation |
|---|---|---|
| `verification_type` | Yes | `mprv`, `mid_milestone`, `final_approval` |
| `conflict_of_interest_confirmed` | Yes | Boolean accepted as true |
| `funds_usage_confirmed` | Yes | Boolean accepted as true |
| `additional_declarations` | No | string; may contain the configured step-answer JSON below |
| `document` | No | PDF, DOC/DOCX, JPG/JPEG, PNG or GIF; max 5 MB |
| `answers` | No | Array keyed by configured legacy question index; see below |

Example multipart text fields:

```text
verification_type=mprv
conflict_of_interest_confirmed=1
funds_usage_confirmed=1
additional_declarations={"v":1,"note":"","response":"","answers":{"supplier-proof":{"label":"Supplier confirmation","type":"file_upload","value":["supplier-email.png"]}}}
document=<file>
```

For the funding-setup field configuration stored in `mprv_notes`, `mid_milestone_notes` and `final_approval_notes`, `additional_declarations` uses `{v:1,note,response,answers:{fieldId:{label,type,value}}}`. Every configured required text value must be supplied. Required `file_upload` fields require a file in `document`; `false` and `0` are valid non-empty values. Optional fields remain optional. Missing required answers/files return `422` with field errors.

The API also supports the older `verification_questions` configuration on the milestone. It is grouped by type as `[{"type":"mprv","questions":[{"question":"...","type":"text","required":true}]}]`. `answers` is keyed by each question's zero-based index. A text answer can be sent as a string or `{ "answer":"...", "dont_have":false, "note":null }`; file questions use multipart `answers[index][file]`. Text answers are limited to 10,000 characters, notes to 1,000, and uploaded question files to 5 MB. `dont_have: true` requires a non-empty explanatory note. Answers for unconfigured indexes or files on a non-file question return `422`.

### Decision payloads and state

Approval has no required body. Rejection requires `{"decision_notes":"Please correct the evidence."}`. Request modification also requires `decision_notes` (string, max 2000). Audit requires `auditor_id` (existing user ID) and accepts optional `decision_notes`.

Decisions are `pending`, `approved`, `rejected`, `audit_requested`, and `modification_requested`. A new submission is blocked while the latest submission of that type is `pending`, `approved`, or `audit_requested`. A rejected or modification-requested submission may be resubmitted. Agreement approval alone does not approve a verification; progress is based on approved verification records. Owner decisions return `422` if the record is no longer pending.

### Final completion

Completion is separate from verification: `POST /milestones/{milestone}/completions` requires `completion_report` (string), optionally accepts `delivery_notes` and `proof_files[]` (each file max 5 MB). Required final-approval answers configured in `final_approval_notes` are validated from the `delivery_notes` JSON using the same v1 answer format; required files must also be uploaded in `proof_files[]`. The applicant can submit only after funds are fully released. Success is `201` with the completion in `data`.

Owner review endpoints: `GET /milestones/{milestone}/completions`, `PATCH /completions/{completion}/approve`, and `PATCH /completions/{completion}/reject`. Rejection requires the decision notes described by the endpoint validator. Completion status and final-step progress change only after the owner approves the completion.
