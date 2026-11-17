# Program Import module

Lets an organization that already runs a program elsewhere import what it has and start using Tujitume from any stage (setup, review, finalization, funding setup, disbursement, monitoring). Imported data is written exactly as the platform's own flows write it, so existing endpoints and screens work on it unchanged. Landing states: `LANDING-STATES.md`. Operations: `RUNBOOK.md`. Decisions and their answers: `DECISIONS.md`.

## Architecture
```
app/Modules/ProgramImport/
  ProgramImportServiceProvider.php   routes, migrations, views, policy, throttles, purge schedule; a no-op unless PROGRAM_IMPORTS_ENABLED=true
  Registry/    ImportTypeRegistry (single source of truth for sheets/fields), StartPointCatalog
  Landing/     one class per start point: owns the lifecycle values
  Parsing/     SpreadsheetReader (streams CSV/XLSX, never evaluates formulas), FileGuard (extension, size, magic bytes)
  Normalizers/ money, dates, phones, emails, urls, lists, enums, text (HTML stripped, formulas neutralized)
  Mapping/     HeuristicMapper (full no-AI fallback), AiMapper (column + value mapping), AiFormExtractor, MappingApplier
  Pipeline/    BatchStager, BatchValidator, ReadinessService, CommitContext, CommitRunner, FormSpecStager
  Importers/   one per sheet type: Program, Rounds, Questions, Fields, Reviewers, Applications, Scores, Winners, Suppliers,
               Milestones, MilestoneSuppliers, Disbursements, Checkpoints, Submissions, SiteVisits
  Readiness/   ReadinessChecker (pure): blockers, warnings, landing preview, skipped, next steps
  Silent/      ImportContext (silent mode), OutboundGuard (suppression decisions)
  Rollback/    RollbackGuard, Rollbacker (ledger based)
  Jobs/ Console/ Policies/ Http/ Support/ database/migrations/ resources/views/
routes/api/program-imports.php   config/program_imports.php
tests/Unit/ProgramImport  tests/Feature/ProgramImport
```
Module tables (prefix `program_import_`): `batches`, `rows` (staging), `records` (ledger), `links` (external refs, unique per organization/entity/ref), `users` (shadow/placeholder accounts), `tokens` (encrypted Google tokens), `settings` (organization AI consent). No column was added to a core table.

Pipeline: upload -> parse -> analyze (mapping) -> validate -> readiness (preview) -> commit (silent) -> optional rollback. Jobs on the `program-imports` queue: `ParseBatchJob`, `AnalyzeBatchJob`; validation as a chain `ValidateBatchJob -> ValidateChunkJob (per sheet) -> ValidateFinalizeJob`; commit as `CommitBatchJob -> CommitChunkJob (per sheet, dependency order) -> FinalizeBatchJob`. All-or-nothing commits run as one transaction in `CommitBatchJob`. Jobs are unique per batch (and sheet) and restart-safe.

## What imports can do
- **Types:** program, rounds, round questions, custom fields, Business Coach roster, applications, scores, awardees, suppliers, milestones, milestone suppliers, historic payments, M&E checkpoints, historic checkpoint reports, historic site visits.
- **Custom fields:** definitions are created through the same service the owner uses; any scoring in the file is stored as a suggestion and never switched on. `custom:<field>` columns import answers (values that do not fit the field are skipped, never guessed). `compute_match_score` recalculates with the round-question engine and, when the program has active scoring fields, the custom-field engine.
- **Applicant files:** an optional ZIP, matched by file name, stored with the platform file service, recorded in the ledger and deleted on rollback, cancel and purge.
- **Business Coaches and site visits (safe versions):** the roster only (existing accounts, `pending`, no fee, no order, no assignment, no message); site visits that already happened as completed records (no order, no payment).
- **Sources:** file, pasted text, public Google Form link, and `google_api` (reads a form that needs a sign-in through the organization's connected Google account). Target round for imports into an existing program: `round_id` or `round_number`.
- **AI (optional, never required):** column mapping, value mapping and form extraction. Every answer is schema-checked, repaired once, and the heuristic result stays if it fails. Only shape-masked samples are sent unless the organization consents (`PUT settings`) AND the provider passes the AI review privacy gate. `POST {batch}/queue-ai-review` queues AI review only on request, only for rounds already set to use it.

## Core changes (each inert unless the module is enabled, except the two product fixes)
| File | Change |
|---|---|
| `config/app.php` | one line registering `ProgramImportServiceProvider` |
| `app/Service/Notification/ProgramNotificationService.php` | recipient loop: `OutboundGuard::shouldSuppress($recipient)` guard |
| `app/Service/Notification/EmailService.php` | first line of `send`: same guard on the address |
| `app/Service/Notification/NotificationService.php` | first line of `store`: guard on the receiver id (covers the broadcast) |
| `app/Service/AiReview/AiReviewDispatcher.php` | first line of `dispatchFor`: nothing while `ImportContext::isSilent()` |
| `app/Console/Commands/UpdateProgramStatus.php` | an imported round never sends `round.closing_soon` |
| `app/Http/Controllers/CheckoutMpesaController.php` | approved fix: escrow charges only the unreleased remainder when a milestone is already released; the bulk paths skip released milestones (nothing changes when none is released). Stripe untouched |
| `MilestoneVerificationController` | bug fix found by the lifecycle test: approving a completion wrote a non-existent `notes` column when unlocking the next milestone |
| Custom fields (new core feature) | tables, models, services, controller, routes, hooks in create-program, get_program, duplicate, store_application, match-score preview; see `documentation/frontend/api/v1/Programs/custom-fields.md` |
| `phpunit.xml` | a test-only `APP_KEY` |
`class_exists` guards keep core independent of the module: delete the module and they vanish.

## When something breaks: follow-ups instead of dead ends
`Support/FollowUps` derives what an import left for a person to finish (rows not saved, a failed or stalled import, applicants not yet invited, awardees without a payment wallet, milestones without a supplier) straight from the data, so each item clears by itself once fixed. One thin core scenario (`app/Service/Pending/Scenarios/Owner/ImportFollowUps.php`, empty without the module) shows them in the Pending drawer with a plain-language title and a link to the exact import. On the import page the owner can see why rows failed (`stats.commit.failure_reasons`), download just the failed rows with the reason in the last column (`GET {batch}/failed-rows?sheet=`), **Try again** after a failure or stall (`POST {batch}/retry`; only what is left runs), and **Mark as handled** (`POST {batch}/handled`).

## Removing the module
1. Delete `app/Modules/ProgramImport`, `routes/api/program-imports.php`, `config/program_imports.php`, `tests/*/ProgramImport`.
2. Remove the provider line from `config/app.php` and, optionally, the guard lines above.
3. Drop the `program_import_*` tables. Imported programs, applications and so on stay: they are ordinary records.

## Rules that never change
Silent mode (no mail, notification, broadcast, AI review job or payment during import); historic money is record-only; imports are monotonic (never move a record backward) and never change records created by hand unless `update_existing` is on; every created record is in the ledger; a batch is visible only to its organization.

## Known limits
- Imported rounds never send closing reminders, so a round the owner later re-dates keeps that silence.
- Supplier payment details go into the supplier's notes (bank columns are not parsed).
- The preview text from the server (landing descriptions, next steps) is English only.
- Imported checkpoints stay unsent unless `mark_checkpoints_sent` is on (nobody is notified either way).
- Searching for `grant` in spreadsheet headers is not in the heuristic synonyms (new code avoids that word); map such columns by hand or use `award size`.
