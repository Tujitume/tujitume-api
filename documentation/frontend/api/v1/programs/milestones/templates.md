## Milestone Templates

All routes below are under `/api/v1/programs` and require Sanctum authentication. Only the program owner can create, activate, or delete templates. The applicant can read the plan and, in `hybrid` planning mode, edit only fields listed in `allowed_edits` while `funding_setup_status` is `awaiting_applicant_revision`. The owner may edit while setup is incomplete. Activated plans are locked.

| Method | Endpoint | Purpose |
|---|---|---|
| POST | `/applications/{application_id}/milestones/templates` | Create a milestone template |
| GET | `/applications/{application_id}/milestones/templates` | List plan milestones, suppliers, budget items and progress |
| PATCH | `/milestones/templates/{milestone_id}` | Edit a template |
| DELETE | `/milestones/templates/{milestone_id}` | Delete a plan milestone before activation |
| POST | `/applications/{application_id}/milestones/activate` | Activate the plan |
| POST | `/applications/{application_id}/milestones/submit-plan` | Send the plan to the other party for review |
| POST | `/applications/{application_id}/milestones/request-changes` | Owner returns the plan for revisions |
| GET | `/applications/{application_id}/milestones/revision-feedback` | Read the latest revision request |

### Create template

`POST /applications/{application_id}/milestones/templates` accepts JSON, or multipart form data when uploading `document`.

| Field | Required | Type / values |
|---|---|---|
| `title` | Yes | string, max 255 |
| `description` | Yes | string |
| `amount` | Yes | number, minimum 0 |
| `expected_outcomes` | No | string |
| `purpose_type` | No | `capex`, `opex`, `services`, `mixed` |
| `sequence_order` | No | integer >= 1; auto-assigned when omitted |
| `target_completion_date` | No | date |
| `mprv_required`, `mid_milestone_required`, `final_approval_required` | No | boolean; default false |
| `mprv_notes`, `mid_milestone_notes`, `final_approval_notes` | No | string; may contain step configuration JSON described in [Verifications](verifications.md) |
| `verification_questions` | No | array of `{type, questions}`; legacy question format described in [Verifications](verifications.md) |
| `allowed_edits` | No | array of applicant-editable field names for hybrid plans |
| `document` | No | PDF, DOC/DOCX, PPT/PPTX |
| `supplier_id` | No | supplier-directory ID owned by the program owner |
| `assignment_type` | No | `primary`, `approved`, `preferred`; defaults to `approved` when a supplier is assigned |
| `payment_route` | No | `direct_to_supplier`, `split`, `direct_to_applicant`; defaults to `direct_to_supplier` |
| `is_locked` | No | boolean; defaults false |

`amount`, `planning_mode`, and `allowed_edits` cannot be made applicant-editable. `POST` returns `201` with the created milestone in `data`. Template validation errors return `422` with `message` and `errors`.

### List response

`GET` returns `{ "data": [...], "summary": { ... } }`. `summary` contains `total_milestones`, `total_allocated`, `approved_amount`, `remaining`, `is_complete`, `funding_setup_status`, and `dealroom_progress`. Each milestone includes its suppliers and budget items.

`dealroom_progress` is server-calculated. It includes `steps`, `completed_steps`, `not_required_steps`, `active_step_id`, and `funding_setup`. Funding setup progress has `steps`, `completed_steps`, `active_step_id`, and `next_action`.

### Editing and plan state

Applicant edits use `PATCH` and may contain only fields in the template's `allowed_edits`; applicant edits are accepted only in hybrid mode while awaiting revision. Owners can edit the plan until activation. An applicant who is not permitted to edit receives `403`; edits after activation also receive `403`.

`POST .../submit-plan` has no required body. The owner sends the plan to the applicant (`awaiting_applicant_revision`); the applicant returns a hybrid plan to the owner (`awaiting_owner_review`). Applicant submission requires each required pre-agreement to have status `submitted` or `agreed`; verification evidence is submitted later through the verification endpoint. An empty plan returns `422` for an applicant submission. Completed setup returns `409`.

`POST .../request-changes` is owner-only and is accepted while the plan is `awaiting_owner_review`:

```json
{
  "revision_notes": "Please update the delivery timeline.",
  "checklist": [
    {"item":"ms:31:timeline#abc123","status":"needs_revision","notes":"Add dates."}
  ]
}
```

`revision_notes` is required (string, max 2000). `checklist` is optional; each item requires `item` (string, max 500) and `status` (`pending`, `needs_revision`, or `approved`); `notes` is optional (max 500). Success returns `funding_setup_status: awaiting_applicant_revision`, `requested_by`, `revision_notes`, and `checklist_items`.

`GET .../revision-feedback` is available to the applicant and program owner. It returns `data: null` when no feedback exists, otherwise `revision_requested_by`, `revision_notes`, `revision_checklist`, `revision_requested_at`, and `funding_setup_status`.

### Activation

Activation requires at least one template and rejects allocation greater than the approved amount. In hybrid mode, every required pre-agreement must be `agreed`. It returns `activated_milestones` and `total_amount`. Only the first milestone is initially releasable, and only when it does not require MPRV; other milestones remain locked until their prerequisites are met. Activation is not payment settlement.

**Integration note:** activation currently allows allocations below the approved amount, while `summary.is_complete` and the progress allocation step require the allocation to match the approved amount (within 0.01). Confirm the desired under-allocation behavior with the backend team.
