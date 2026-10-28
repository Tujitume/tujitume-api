## Funding Plan and Milestones

All endpoints are under `/api/v1/programs` and require Sanctum authentication. Owners configure and activate a plan. In hybrid mode, the applicant can edit only permitted fields while revisions are requested, and submits the revised plan back to the owner.

| Method | Endpoint | Purpose |
|---|---|---|
| POST | `/applications/{application}/award` | Award an application and start funding setup |
| POST/GET | `/applications/{application_id}/milestones/templates` | Create/list planned milestones |
| PATCH/DELETE | `/milestones/templates/{milestone_id}` | Edit/delete a planned milestone |
| POST | `/applications/{application_id}/milestones/activate` | Activate planned milestones |
| POST | `/applications/{application_id}/milestones/submit-plan` | Send a plan to the other participant |
| POST | `/applications/{application_id}/milestones/request-changes` | Owner asks for plan revisions |
| GET | `/applications/{application_id}/milestones/revision-feedback` | Read revision feedback |
| POST | `/applications/{application}/planning-mode` | Set application planning mode |

Plan statuses are `not_started`, `in_progress`, `awaiting_applicant_revision`, `awaiting_owner_review`, and `completed`. Owner submission sets `awaiting_applicant_revision`; applicant submission in hybrid mode sets `awaiting_owner_review`. The owner can request changes only from `awaiting_owner_review`. Applicant submit requires required pre-agreements to be submitted or agreed; actual MPRV/mid/final evidence is submitted later. The API does not currently block a resubmission because checklist entries remain unresolved; the frontend may enforce this.

See [Templates](templates.md) for template payloads and revision feedback, [Pre-agreements](pre-agreements.md), [Verifications](verifications.md), [Suppliers](suppliers.md), [Completions](completions.md), and [Disbursements](disbursements.md) for the remaining workflow.
