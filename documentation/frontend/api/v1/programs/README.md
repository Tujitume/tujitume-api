# Programs API v1

All endpoints require a Bearer token and `Accept: application/json`. Write responses use `{ "success": true|false, "message": "…", "data"|"errors": … }`.

| Area | Reference |
|---|---|
| Programs, applications, wallets, messages | [program](program.md), [applications](applications.md), [wallet](wallet.md), [email templates](email-templates.md) |
| Rounds | [rounds](rounds/rounds.md), [questions](rounds/questions.md), [reviewers](rounds/reviewers.md), [documents](rounds/documents.md), [scoring](rounds/scoring.md), [finalization](rounds/finalization.md) |
| Funding setup | [milestones](milestones/milestones.md), [suppliers](milestones/suppliers.md), [disbursements](milestones/disbursements.md), [deal room](milestones/deal-room.md), [verifications](milestones/verifications.md), [pre-agreements](milestones/pre-agreements.md), [completions](milestones/completions.md) |
| Monitoring | [checkpoints](monitoring/checkpoints.md), [submissions](monitoring/submissions.md), [site visits](monitoring/site-visits.md), [analytics](monitoring/analytics.md) |

Routes are relative to `/api/v1/programs`.
