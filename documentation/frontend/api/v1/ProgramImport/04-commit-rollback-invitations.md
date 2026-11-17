# Commit, rollback, invitations

## POST `{batch}/commit` (202)
Body `{ "confirm_data_rights": true }` (recorded with user and time). Errors: 422 `consent_required`, `invalid_state`, `readiness_blocked` (+ `errors.blockers[]`). Runs on the `program-imports` queue in silent mode: no mail, notification, broadcast, AI review or payment. Poll `GET {batch}` until `completed`, `completed_with_errors` or `failed`. Idempotent: re-running imports nothing twice.

## Follow-ups when something breaks
| Method | Path | Notes |
|---|---|---|
| GET | `{batch}/failed-rows?sheet=` | CSV of the rows that could not be saved: the original columns plus `problem`. 404 `nothing_to_fix` when there are none |
| POST | `{batch}/retry` | for a `failed` batch that was confirmed, or one stuck in `importing` for more than 10 minutes; 202. Rows already saved are never saved twice. 422 `invalid_state` otherwise |
| POST | `{batch}/handled` | the person has dealt with the follow-up; it leaves the Pending list |
`GET {batch}` adds `stats.commit.failure_reasons: [{sheet, message, count}]` (the most common reasons, plain language) and `updated_at`. The Pending drawer (`GET /api/v1/pending-tasks`) gets items of type `import.failed_rows`, `import.failed`, `import.stuck`, `import.invite`, `import.wallet`, `import.supplier`, each linking to `dashboard.programOrg.programsBulkUpload?batch={id}`.

## POST `{batch}/rollback`
Undoes a committed batch from its ledger when nothing downstream happened. 422 `rollback_blocked` with `errors.blockers[]`: `changed_after_import`, `scores_by_others`, `reviewers_assigned`, `reviewers_on_round`, `checkpoints_added`, `escrow_funded`, `answers_changed`, `milestone_activity`, `payments_added`, `new_applications`, `wallet_used`, `users_claimed`, `not_committed`. Restores changed records, deletes created records and stored files, removes links, deletes created accounts that are unreferenced and unclaimed.

## POST `{batch}/queue-ai-review` (202)
Only after a review/finalization import with `queue_ai_review_after_import`, only for imported applications in rounds that already use AI-assisted or AI-only review, through the normal rate-governed queue. 422 `invalid_state`, `no_ai_review_method`.

## POST `programs/{program}/invitations`
Body `{ "user_ids": [..] }` optional. Sends claim invitations (reusing the existing invitation token and `organization-invitations/accept` flow) to imported applicants who have a real email and no password. `data` = `{ sent, skipped, placeholders_not_contacted }`. 422 `not_imported` for programs without imported applicants.

## Google connection (Phase C)
Off unless `PROGRAM_IMPORTS_GOOGLE_OAUTH=true` and `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET` are set; otherwise every call answers 422 `google_not_configured`.

| Method | Path | Notes |
|---|---|---|
| GET | `integrations/google` | `{ configured, connected }` for the caller's organization |
| POST | `integrations/google/connect` | returns `{ url }`; open it so the person can approve **read-only** access (`forms.body.readonly`, `spreadsheets.readonly`) |
| GET | `integrations/google/callback` | public: Google redirects the browser here; the signed, 10-minute state identifies the organization. 422 `google_state` for a forged or expired state |
| DELETE | `integrations/google` | removes the stored tokens |

Tokens are stored encrypted per organization. When a pasted form link needs a sign-in (`form_private`) and Google is connected, the form is read through the Forms API with that access; otherwise the 422 offers uploading a file or pasting the questions.
