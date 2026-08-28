# Programs API v1

**Base URL**: `/api/v1/programs`

All endpoints require `Authorization: Bearer {token}`, except application callbacks outside this module. The API uses Laravel Sanctum. Every POST, PATCH, PUT, and DELETE response uses:

```json
{"success":true,"message":"Request completed.","data":{}}
```

Failures use `success: false` and an `errors` object where validation applies.

## User types

| ID | Role | Program permissions |
|---:|---|---|
| 1 | business_owner | Apply, submit evidence, edit permitted plans |
| 2 | investor | No owner operations |
| 3 | service_provider | No owner operations |
| 4 | organization | Own and operate programs |
| 5 | admin | Administrative access where configured |
| 6 | external_reviewer | Score assigned rounds and site visits |

## Status colors

| Value | Color | Meaning |
|---|---|---|
| draft, pending, submitted | info | Awaiting the next action |
| under_review, changes_requested, processing | warning | Being reviewed or changed |
| approved, awarded, completed, verified, agreed | success | Successfully accepted |
| rejected, not_selected, failed, cancelled, final_rejected | danger | Denied or stopped |

Status-bearing responses are represented as `{"value":"pending","color":"info"}` where that controller formats status metadata.

## Endpoint index

| Area | Methods and URI prefix | Reference |
|---|---|---|
| Program management | `GET /programs`, `POST /create-program`, `POST /programs/{program}`, `DELETE /delete-program/{id}`, `POST /programs/{program}/duplicate` | [Programs](programs.md) |
| Applications | `GET|POST /{program}/applications`, `POST /applications/{pitch}/accept`, `POST /applications/{application}/planning-mode` | [Applications](applications.md) |
| Wallet and messages | `GET /{program}/wallets`, `POST /wallets/{wallet}/deposit`, `PATCH|DELETE /{program}/email-templates/{event}` | [Wallet](wallet.md), [Email templates](email-templates.md) |
| Rounds | `GET|POST /{program}/rounds`, `PATCH|DELETE /rounds/{round}`, `POST /rounds/{round}/publish` | [Rounds](rounds/rounds.md), [Publish](rounds/publish.md) |
| Round workflow | Questions, reviewers, documents, scores, finalization, and manual decisions | [Questions](rounds/questions-answers.md), [Reviewers](rounds/reviewers.md), [Documents](rounds/documents.md), [Scoring](rounds/scoring.md), [Finalization](rounds/finalization.md) |
| Funding setup | Templates, agreements, verifications, completions, suppliers, disbursements, deal room | [Templates](milestones/templates.md), [Pre-agreements](milestones/pre-agreements.md), [Verifications](milestones/verifications.md), [Completions](milestones/completions.md), [Suppliers](milestones/suppliers.md), [Disbursements](milestones/disbursements.md), [Deal room](milestones/deal-room.md) |
| Monitoring | Checkpoints, submissions, visits, and analytics | [Checkpoints](monitoring/checkpoints.md), [Submissions](monitoring/submissions.md), [Site visits](monitoring/site-visits.md), [Analytics](monitoring/analytics.md) |
