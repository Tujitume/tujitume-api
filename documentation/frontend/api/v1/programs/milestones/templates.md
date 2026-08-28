## Milestone Templates

Templates define an awarded application's funding plan. The organization creates templates; the applicant can edit only explicitly allowed fields in hybrid planning mode before plan submission.

| Method | Endpoint | Purpose |
|---|---|---|
| POST | `/applications/{application_id}/milestones/templates` | Create template |
| GET | `/applications/{application_id}/milestones/templates` | List templates |
| PATCH | `/milestones/templates/{milestone_id}` | Edit template |
| DELETE | `/milestones/templates/{milestone_id}` | Delete unactivated template |
| POST | `/applications/{application_id}/milestones/activate` | Convert templates to milestones |
| POST | `/applications/{application_id}/milestones/submit-plan` | Applicant submits plan |
| POST | `/applications/{application_id}/milestones/request-changes` | Owner requests revisions |

**Template Request Body**

| Field | Required | Type | Notes |
|---|---|---|---|
| `title` | Yes | string | Milestone name |
| `amount` | Yes | numeric | Planned amount |
| `sequence_order` | Yes | integer | Unique for application |
| `purpose_type` | Yes | string | `capex`, `opex`, `services`, `mixed` |
| `allowed_edits` | No | array | Fields applicant may edit in hybrid mode |

**Example Response — 201 Created**

```json
{"success":true,"message":"Milestone template created successfully.","data":{"id":31,"app_id":105,"title":"Equipment purchase","amount":"50000.00","is_template":true,"status":"pending"}}
```

Activation requires every configured pre-agreement to be agreed. Submission is blocked when required suppliers or budget items are missing. All mutations return 401 without authentication, 403 for an invalid actor, and 422 when lifecycle guards fail.
