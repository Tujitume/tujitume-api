# Program Import: Decisions
| Date | ID | Decision | Status |
|---|---|---|---|
| 2026-11-16 | SCOPE | Full backend + frontend, ask when unsure (user message 15:51-15:52) | answered |
| 2026-11-16 | DOCS-LOC | Docs under `docs/program-import/` in tujitume-api as the task specifies | assumed |
| 2026-11-16 | Q-STANDING | Module-only backend edits allowed for Program Import ("lets do this, ask as we work") | answered |
| 2026-11-16 | Q-TEST-ENV | Tests run on in-memory SQLite per phpunit.xml; no real database is touched | resolved |
| 2026-11-16 | Q-STATUS | Review landing: program `under_review`, target round `published` with past dates | assumed |
| 2026-11-16 | Q-ESCROW | ~~Block disbursement landings with partially released milestones~~ superseded 2026-11-17 | superseded |
| 2026-11-16 | Q-AGREEMENTS/PLANNING/ROUND/REVIEWMETHOD/REVIEWERS | `*_ready` flags only; planning `locked`; synthetic finalized round; manual review; no reviewers | assumed |
| 2026-11-16 | Q-CUSTOM | ~~Deferred until the custom-field feature exists~~ superseded 2026-11-17 | superseded |
| 2026-11-16 | MIGRATIONS | Module migrations live in `app/Modules/ProgramImport/database/migrations`, loaded only when enabled (so existing tests and a disabled platform never see the tables) | assumed |
| 2026-11-16 | SEAMS | Approved by the requester ("implement all, make correct long-lasting decisions"): provider line, four outbound guards. No scheduler seam (landing values make it unnecessary). | answered |
| 2026-11-16 | Q-ESCROW | ~~Kept: block partially paid landings~~ superseded 2026-11-17 | superseded |
| 2026-11-16 | Q-EXISTING-FORM | The module has its own SSRF-safe fetcher and parser (the existing reader fetches without host re-checks). Existing reader untouched. | assumed |
| 2026-11-16 | Q-FRONTEND | Wizard built at the existing stub route `programs/bulk-upload`, reached from Programs -> Add new -> Import a program. | assumed |
| 2026-11-16 | Q-BADGE | No "imported" badge in existing screens (would need core resource changes). | assumed |
| 2026-11-17 | GOOGLE-OAUTH | Phase C built (read-only scopes, encrypted tokens, Forms API fallback); off until flag + credentials. | answered |
| 2026-11-17 | JOBS | Validate/Commit split into per-sheet chunk jobs + finalize job; all-or-nothing stays one transaction. | answered |
| 2026-11-17 | Q-ESCROW | Approved by the requester; M-Pesa/LIPR escrow now charges the unreleased remainder; Stripe untouched; block lifted (warning). | answered |
| 2026-11-17 | Q-CUSTOM / Q-LEGACY-FIELDS | Custom fields built as a core feature; imports create definitions (scoring only as suggestion), answers and optional computed score. | answered |
| 2026-11-17 | Q-REVIEWERS | Safe version: roster only (pending, no orders/fees/assignments) and historic completed site visits. | answered |
| 2026-11-17 | Q-CHECKPOINTS | Option `mark_checkpoints_sent` (default off). | answered |
| 2026-11-17 | GATE-4 | Module enabled in .env (local). Migrations not run: local MySQL was not running. | partly |
| 2026-11-16 | AI-AFTER-IMPORT | ~~Stored but starts nothing~~ superseded: `POST {batch}/queue-ai-review` starts it on request | superseded |
| 2026-11-17 | SCHEDULER | Imported rounds never send `round.closing_soon` (guard in `UpdateProgramStatus`) | answered |
| 2026-11-17 | AI-PRIVACY | Real samples need the organization's consent (`PUT settings`) AND the privacy gate; platform kill switch `allow_real_samples` | answered |
| 2026-11-17 | GOOGLE-API | `google_api` source reads sign-in forms through the connected account | answered |
| 2026-11-17 | ROUNDS | `round_id` / `round_number` choose the target round for existing-program imports | answered |
| 2026-11-17 | ROLLBACK-USERS | Rollback verifies imported accounts gained no other data, in the guard and again inside the transaction | answered |
| 2026-11-17 | CORE-BUG | `approve_completion` wrote a non-existent `notes` column when unlocking the next milestone; fixed | answered |
| 2026-11-17 | FOLLOW-UPS | Import problems become Pending-list items derived from data (self-clearing), with Try again, Mark as handled and download-rows-to-fix. | answered |
| 2026-11-17 | PARITY | Show-endpoint parity compares VALUES for program, round and application; only documented differences are excluded. | answered |
