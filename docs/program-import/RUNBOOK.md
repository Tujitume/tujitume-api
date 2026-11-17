# Program Import runbook

## Enable (Gate 4: do this deliberately)
1. Set `PROGRAM_IMPORTS_ENABLED=true`. Optional: `PROGRAM_IMPORTS_DISK` (a private disk, S3 in production), `PROGRAM_IMPORTS_RETENTION_DAYS` (default 30), `PROGRAM_IMPORTS_AI_ENABLED`.
2. `php artisan migrate` (module migrations load only when enabled).
3. Run a worker for the dedicated queue: `php artisan queue:work --queue=program-imports`.
4. Make sure `php artisan schedule:run` runs every minute: `program-imports:purge` is scheduled daily.
5. Smoke test on staging with the XLSX template of each start point, then enable in production.

6. Optional Google connect: set `PROGRAM_IMPORTS_GOOGLE_OAUTH=true`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, and add `https://<api host>/api/v1/program-imports/integrations/google/callback` as an authorized redirect URI. Requesting the Forms scope may need Google app verification.

## Operate
- Failed batch: `GET /program-imports/{id}` shows `error_summary`. Re-run from the wizard; each phase restarts cleanly, and a committed batch is never written twice (links make imports idempotent).
- Stuck in `importing`: check the worker, then set the batch to `failed` and use the wizard again. Rows already written are in the ledger and links, so a re-run skips them.
- Undo: `POST /program-imports/{id}/rollback`. A 422 `rollback_blocked` lists why.
- Purge: `php artisan program-imports:purge --days=30` deletes raw files and staged rows, keeps the batch summary and ledger.
- Silent mode: during import and for unclaimed imported accounts nothing is mailed, notified, broadcast or sent to AI. Claim invitations are sent only by `POST /program-imports/programs/{program}/invitations`.

## Human actions
Enable flag, run the queue worker, keep the scheduler running, optionally configure a private disk and the Google client credentials.
