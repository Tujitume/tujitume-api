# Program Import: Questions (Gate 1)

> Every question below has been answered. The final answer to each is in `DECISIONS.md` (later entries supersede earlier ones). Nothing here is open.

Each has what I found, the options, and my recommended default (first option).

**Q-STANDING (blocker)** Earlier you told me never to touch `tujitume-api`. Your 2026-11-16 message asks for full backend + frontend. I will treat that as a new explicit instruction for this module only (new files under `app/Modules/ProgramImport`, `routes/api/program-imports.php`, `config/`, `database/migrations/`, `tests/`, `docs/`). Core files stay untouched until Gate 3. Confirm? Options: (a) confirm, module-only (recommended); (b) docs only for now.

**Q-EXISTING-FORM** `GoogleFormImportController::preview` + `GoogleFormReader` already parse Google Forms into question drafts. (a) Module wraps/calls the reader unchanged and adds SSRF checks on its own side (recommended); (b) build a new fetcher inside the module and leave the old one; (c) refactor the old one (core change).

**Q-STATUS** Review landing: program `under_review` vs `closed`; target round must be `published` for accept/reject/advance (`advanceManual` needs `published`), but the prompt expected `in_review`. Also `finalize` needs a review-period end date in the past. (a) Round `published` with past `close_date`/`review_period_end`, program `under_review` (recommended); (b) round `in_review` (accept/advance endpoints will 422 for rounds 2+; needs a core change).

**Q-ESCROW** `release_fst_milestone` sums only the first milestone per application, is Stripe-based, and its released-check (`status === 1`) compares int to a string enum. (a) Do not fix; module blocks disbursement landing for apps with partially released milestones and documents it (recommended, money code); (b) approve a core fix at Gate 3.

**Q-AGREEMENTS** Pre-agreements are only enforced in `hybrid` planning, and only during activation. Imported active milestones: (a) set `*_ready` flags only (recommended); (b) also create `agreed` pre-agreement rows.

**Q-PLANNING** Planning mode is on the application, values `locked|hybrid` (not view_only/full_edit). Default imported winners to `locked`? (recommended) Or `hybrid`.

**Q-ROUND** Awardee-only start: synthetic finalized "Imported awardees" round (recommended; `current_round_id` is used by finalize/history/participation) vs null.

**Q-FINALIZE1** `finalize` has no round-1 block; manual mode works for round 1 when no undecided apps remain. Confirm round 1 landing = accept/reject, with finalize optional.

**Q-REVIEWMETHOD** Default imported rounds to `manual` review (no AI). Confirm; `queue_ai_review_after_import` stays off by default.

**Q-REVIEWERS** Reviewers/site visits not imported in v1. Confirm.

**Q-SCHEDULER** Scheduled commands that react to programs/rounds: `update:program-status`, `ai-reviews:sweep`, milestone reminders. Approve skip-guards at Gate 3 for programs with an import link? (recommended)

**Q-AI-PRIVACY** Headers + shape-masked samples without the privacy gate, real values only with the gate. Confirm.

**Q-BADGE** "Imported" indicator in existing screens would need a core resource change. Default: no.

**Q-FRONTEND** You asked for a full frontend, so I will build the wizard (not spec only), using app components from `components/ui`, stroke borders, Business Coach wording, and TruncatedText. Confirm, and tell me where it should live in the navigation (suggest: Programs dashboard "Import" entry).

**Q-LEGACY-FIELDS / Q-CUSTOM** Custom fields and the match-score engine do not exist in code. (a) Drop `Fields`, `custom:` columns and `compute_match_score` from this build and add them when the feature ships (recommended); (b) build the feature first.

**Q-CURRENCY** `programs.currency` is an enum KES|USD. Reject other currencies (recommended) or approve a core migration.

**Q-AMOUNT** `activateTemplates` and `award` derive amounts differently (requested vs sum of milestones). For imported winners set `total_amount_requested` = awarded amount when the file lacks one? (recommended)

**Q-TEST-ENV** Which database should tests use (MySQL test DB name/credentials)? I will not run anything against a non-test database.

---
## Final answers
| Question | Final answer |
|---|---|
| Q-STANDING | Module files plus the approved core seams |
| Q-EXISTING-FORM | Own SSRF-safe fetcher and parser; existing reader untouched |
| Q-STATUS | Program `under_review`; the live round `published` with past dates |
| Q-ESCROW | Approved and fixed for M-Pesa/LIPR: only the unreleased remainder is charged; the import warns instead of blocking |
| Q-AGREEMENTS | Verification flags imported; required verifications start as agreed on active milestones |
| Q-PLANNING / Q-ROUND / Q-FINALIZE1 / Q-REVIEWMETHOD | `locked`; synthetic finalized round; finalize optional in round 1; manual review |
| Q-REVIEWERS | Safe version built: roster only plus historic completed site visits |
| Q-SCHEDULER | Imported rounds skip the closing reminder; no other scheduler change |
| Q-AI-PRIVACY | Masked shapes by default; real values only with organization consent AND a passing privacy gate |
| Q-BADGE | No imported badge in existing screens |
| Q-FRONTEND | Wizard built at `programs/bulk-upload` |
| Q-LEGACY-FIELDS / Q-CUSTOM | Custom fields built as a core feature and integrated; missing required answers warn |
| Q-CURRENCY / Q-AMOUNT / Q-TEST-ENV | KES/USD only; requested amount defaults to the awarded amount; tests use in-memory SQLite |
