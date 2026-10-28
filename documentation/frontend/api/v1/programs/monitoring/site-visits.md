# Site Visits and Site Visit Reviewer Orders

All routes are under `/api/v1/programs` and inherit `auth:sanctum`, `extend-token`, and `throttle:api`. Program-owner actions require ownership of the program; reviewer actions require the assigned reviewer. Only a checkpoint with `require_site_visit: true` can receive an assignment.

| Method | Endpoint | Access | Purpose |
|---|---|---|---|
| POST | `/monitoring/applications/{app}/checkpoints` | Program owner | Create a checkpoint; set `require_site_visit: true` when it needs a visit |
| POST | `/monitoring/checkpoints/{checkpoint}/site-visit/assign` | Program owner | Assign or reassign the checkpoint visit and create/update its `site_visit` reviewer order |
| POST | `/monitoring/site-visits/{visit}/accept` | Assigned reviewer | Accept the assignment and move the order to `in_progress` |
| POST | `/monitoring/site-visits/{visit}/decline` | Assigned reviewer | Decline a pending assignment |
| POST | `/monitoring/site-visits/{visit}/submit` | Assigned reviewer | Submit report fields/files; marks the visit completed and order delivered |
| GET | `/monitoring/site-visits/{visit}` | Program owner, assigned reviewer, or applicant | Get visit details and linked order |
| GET | `/monitoring/reviewer/{reviewer_id}/site-visits` | That reviewer | List the caller's assigned visits |
| GET | `/monitoring/programs/{program}/site-visit-orders` | Program owner | List checkpoint-linked site visit reviewer orders for the program |
| POST | `/monitoring/reviewer-orders/{order}/site-visit/request-changes` | Program owner | Return delivered site visit work for changes |
| POST | `/monitoring/reviewer-orders/{order}/site-visit/reject` | Program owner | Reject delivered site visit work |
| POST | `/monitoring/reviewer-orders/{order}/site-visit/approve` | Program owner | Approve delivered work and start payment when its fee is positive |
| POST | `/monitoring/reviewer-orders/{order}/site-visit/retry-payment` | Program owner | Retry payment for approved work with an unpaid or failed payment |
| GET | `/reviewer-orders/{order}/payment-status` | Program owner or assigned reviewer | Check asynchronous payment status |

### Assign a site visit

Checkpoint creation uses `POST /monitoring/applications/{app}/checkpoints`. Required fields are `checkpoint_name` (string, max 255) and `type` (`monitoring`, `reporting`, or `meeting`). For a site-visit checkpoint send `require_site_visit: true` and `should_notify_applicant` (boolean; required when `require_site_visit` is present). `due_date`, `requirement`, `checkpoint_description` (max 500), `meeting_required`, `meeting_id` (required when meeting is true), `kpis_to_track`, `evidence_required`, `submission_fields`, and `display_order` are optional. See [Monitoring Checkpoints](checkpoints.md) for the checkpoint workflow.

Required JSON fields: `reviewer_id` (existing user ID), `start_date` (date), and `assign_type` (`internal`, `external`, or `third_party_audit`). `email` is optional except it is required and must be valid when `assign_type` is `external`. Optional fields are `data_collection_fields` (array), `location` (string), `gps_lat`/`gps_lng` (numeric), `inspector` (string), `objective` (string), `kpi_targets` (array), and `reviewer_fee` (numeric >= 0; defaults to 0).

```json
{
  "reviewer_id": 42,
  "start_date": "2026-11-15",
  "assign_type": "third_party_audit",
  "location": "Kisumu",
  "reviewer_fee": 2500
}
```

Success is HTTP `201`, with the common `{success,message,data,status}` envelope. The reviewer receives an email and in-app notification. Assignment acceptance is initially `pending`; reassignment resets the acceptance and work statuses. An assignment cannot be changed while payment is pending, processing, or completed.

Site visit resources returned by assignment, report submission, detail, reviewer-list, checkpoint-list, and reviewer-order responses include the loaded `checkpoint` relation as an object with `id`, `checkpoint_name`, `type`, `due_date`, `requirement`, `require_site_visit`, and `status`.

### Reviewer lifecycle

Accept and decline take an optional JSON body on decline: `{ "reason": "..." }` (`reason` nullable string, max 1000). A reviewer must accept before submitting a report. Report submission accepts the optional text fields `objectives_assessment`, `observed_actions`, `evidence_found`, `risk_notes`, `recommendation_notes`, and `visit_comments`; `kpi_targets` and `data_collection_fields` arrays; numeric `gps_lat`/`gps_lng`; and `files[]` uploads (each file max 20 MB). It returns `422` if the reviewer has not accepted, or the order is not in progress/awaiting revisions. Submit the report again after a change request; the order becomes delivered again and the program owner is notified.

### Owner review and payment

Change request body: `{ "modification_note": "..." }`; rejection body: `{ "rejection_reason": "..." }`. Both are required strings up to 1000 characters. Only delivered work can be changed, rejected, or approved. Approval marks the order `approved`; when `fee_amount > 0`, it starts the existing LIPR wallet transfer to the reviewer. A successful API response means the transfer was initiated, not that settlement completed. Poll `GET /reviewer-orders/{order}/payment-status` for the current status (`unpaid`, `pending`, `leg1_processing`, `completed`, or `failed`). Retry is permitted only for approved orders with `unpaid` or `failed` status. A zero-fee order is approved without starting a transfer.

The owner site-visit order list returns `{ "data": [...] }`; each order includes `order_type: "site_visit"`, `site_visit_id`, reviewer/program data, `site_visit.checkpoint`, `work_status`, `acceptance_status`, `fee_amount`, `currency`, and `payment_status`. Work statuses include `assigned`, `in_progress`, `delivered`, `modification_requested`, `approved`, and `rejected`; acceptance is `pending`, `accepted`, or `declined`.

Validation failures return HTTP `422` with Laravel's `message` and `errors` fields. Unauthorized access returns `403`. Round reviewer-order endpoints remain separate and do not accept site visit orders.
