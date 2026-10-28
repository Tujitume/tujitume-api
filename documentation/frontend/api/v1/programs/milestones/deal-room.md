## Deal Room

The milestone deal-room resource is under `/api/v1/programs` and requires Sanctum authentication. `GET|POST /milestones/{milestone}/deal-room`, `GET|PUT|PATCH|DELETE /deal-room/{deal_room}`, and `GET /dealroom/applications/{application}` expose notes, documents and application detail. Document requests use multipart form data. See the funding setup pages for milestone templates, suppliers, agreements, verification and release workflows.

### Server-calculated progress

`GET /applications/{application_id}/milestones/templates` includes a `summary.dealroom_progress` object. Use it as the shared owner/applicant progress source rather than inferring completion from agreement badges or local page state:

```json
{
  "steps": [
    {"id":"funding-setup","required":true,"done":true},
    {"id":"mprv","required":true,"done":false},
    {"id":"mid-milestone","required":false,"done":false},
    {"id":"final-approval","required":true,"done":false},
    {"id":"monitoring-evaluation","required":true,"done":false}
  ],
  "completed_steps": ["funding-setup"],
  "not_required_steps": ["mid-milestone"],
  "active_step_id": "mprv",
  "funding_setup": {
    "steps": [{"id":"milestones","done":true},{"id":"allocation","done":true},{"id":"budget","done":true},{"id":"activate","done":true}],
    "completed_steps": ["milestones","allocation","budget","activate"],
    "active_step_id": "activate",
    "next_action": "Funding setup is complete."
  }
}
```

Step IDs are `funding-setup`, `mprv`, `mid-milestone`, `final-approval`, and `monitoring-evaluation`. Non-required MPRV/mid/final steps are skipped. MPRV and mid progress require approved verification records; agreement acceptance alone does not complete them. Final progress requires completion approval for every final-required milestone. Monitoring and evaluation is complete only when all milestones are complete and every checkpoint is verified.

During an incomplete plan, `funding_setup_status` may be `not_started`, `in_progress`, `awaiting_applicant_revision`, or `awaiting_owner_review`; activation changes it to `completed`. The progress object includes a human-readable `next_action` for the funding setup substep. The owner page may refresh while waiting for applicant revisions; this API does not provide a push update for that status.
