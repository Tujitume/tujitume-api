# Program Import: final delivery report (Section 16)

Date: 2026-11-17. Scope: backend module, custom-fields core feature, frontend wizard, custom-field screens, Amari help, documentation.

## 1. Result in one table
| Check | Result |
|---|---|
| Full backend suite, module disabled (default; phpunit forces it off) | **371 passed**, 0 failed, 7 skipped (existing) |
| Full backend suite, `PROGRAM_IMPORTS_ENABLED=true` | **369 passed**, 0 failed, 9 skipped (the 2 disabled-only tests skip themselves) |
| Frontend deployment gate | lint, `check:foundation` (architecture, locales, design system), `check:locales`, `check:translations`, `check:currency`, `check:notification-links`, `check:url-state`, `check:navigation`, `check:behavior`, `check:imports`, `amari:index:check`, `amari:guides:check`, `amari:coverage`, `test:amari`, `amari:answer-eval` all pass; `npm run build:deploy` produced `dist/index.html` and `dist/sw.js` |
| `check:links` | reports 17 existing items in landing/UI-docs pages (none in this work) |
| `amari:review:check` / `amari:knowledge:check` | **Pass** (117 features reviewed, 0 baseline). See section 9 for how the 113 older reviews were done |
| Verified against a real database | **No.** Tests use in-memory SQLite; LIPR, Google and the AI provider are faked. Migrations have not been run anywhere (task 10 in `backend-fix-for-dev.txt`) |

## 2. Files created
### Module (`tujitume-api/app/Modules/ProgramImport`, 96 files)
- `app/Modules/ProgramImport/Console/PurgeImports.php`
- `app/Modules/ProgramImport/Http/Controllers/BatchController.php`
- `app/Modules/ProgramImport/Http/Controllers/CatalogController.php`
- `app/Modules/ProgramImport/Http/Controllers/GoogleController.php`
- `app/Modules/ProgramImport/Http/Controllers/InvitationController.php`
- `app/Modules/ProgramImport/Http/Controllers/SettingsController.php`
- `app/Modules/ProgramImport/Http/Requests/StoreBatchRequest.php`
- `app/Modules/ProgramImport/Http/Resources/BatchResource.php`
- `app/Modules/ProgramImport/Importers/ApplicantUsers.php`
- `app/Modules/ProgramImport/Importers/ApplicationImporter.php`
- `app/Modules/ProgramImport/Importers/CheckpointImporter.php`
- `app/Modules/ProgramImport/Importers/DisbursementImporter.php`
- `app/Modules/ProgramImport/Importers/FieldImporter.php`
- `app/Modules/ProgramImport/Importers/Importer.php`
- `app/Modules/ProgramImport/Importers/MilestoneImporter.php`
- `app/Modules/ProgramImport/Importers/MilestoneSupplierImporter.php`
- `app/Modules/ProgramImport/Importers/ProgramImporter.php`
- `app/Modules/ProgramImport/Importers/QuestionImporter.php`
- `app/Modules/ProgramImport/Importers/ReviewerImporter.php`
- `app/Modules/ProgramImport/Importers/RoundImporter.php`
- `app/Modules/ProgramImport/Importers/ScoreImporter.php`
- `app/Modules/ProgramImport/Importers/SiteVisitImporter.php`
- `app/Modules/ProgramImport/Importers/SubmissionImporter.php`
- `app/Modules/ProgramImport/Importers/SupplierImporter.php`
- `app/Modules/ProgramImport/Importers/WinnerImporter.php`
- `app/Modules/ProgramImport/Jobs/AnalyzeBatchJob.php`
- `app/Modules/ProgramImport/Jobs/BatchJob.php`
- `app/Modules/ProgramImport/Jobs/CommitBatchJob.php`
- `app/Modules/ProgramImport/Jobs/CommitChunkJob.php`
- `app/Modules/ProgramImport/Jobs/FinalizeBatchJob.php`
- `app/Modules/ProgramImport/Jobs/ParseBatchJob.php`
- `app/Modules/ProgramImport/Jobs/ValidateBatchJob.php`
- `app/Modules/ProgramImport/Jobs/ValidateChunkJob.php`
- `app/Modules/ProgramImport/Jobs/ValidateFinalizeJob.php`
- `app/Modules/ProgramImport/Landing/BaseLanding.php`
- `app/Modules/ProgramImport/Landing/DisbursementLanding.php`
- `app/Modules/ProgramImport/Landing/FinalizationLanding.php`
- `app/Modules/ProgramImport/Landing/FundingSetupLanding.php`
- `app/Modules/ProgramImport/Landing/LandingDefinition.php`
- `app/Modules/ProgramImport/Landing/MonitoringLanding.php`
- `app/Modules/ProgramImport/Landing/ReviewLanding.php`
- `app/Modules/ProgramImport/Landing/SetupLanding.php`
- `app/Modules/ProgramImport/Mapping/AiFormExtractor.php`
- `app/Modules/ProgramImport/Mapping/AiMapper.php`
- `app/Modules/ProgramImport/Mapping/HeuristicMapper.php`
- `app/Modules/ProgramImport/Mapping/MappingApplier.php`
- `app/Modules/ProgramImport/Models/ImportBatch.php`
- `app/Modules/ProgramImport/Models/ImportLink.php`
- `app/Modules/ProgramImport/Models/ImportRecord.php`
- `app/Modules/ProgramImport/Models/ImportRow.php`
- `app/Modules/ProgramImport/Models/ImportSetting.php`
- `app/Modules/ProgramImport/Models/ImportToken.php`
- `app/Modules/ProgramImport/Models/ImportUser.php`
- `app/Modules/ProgramImport/Normalizers/Normalizer.php`
- `app/Modules/ProgramImport/Normalizers/RowNormalizer.php`
- `app/Modules/ProgramImport/Parsing/FileGuard.php`
- `app/Modules/ProgramImport/Parsing/ImportLimitException.php`
- `app/Modules/ProgramImport/Parsing/SpreadsheetReader.php`
- `app/Modules/ProgramImport/Pipeline/BatchStager.php`
- `app/Modules/ProgramImport/Pipeline/BatchValidator.php`
- `app/Modules/ProgramImport/Pipeline/CommitContext.php`
- `app/Modules/ProgramImport/Pipeline/CommitRunner.php`
- `app/Modules/ProgramImport/Pipeline/FormSpecStager.php`
- `app/Modules/ProgramImport/Pipeline/ImportRowException.php`
- `app/Modules/ProgramImport/Pipeline/ReadinessService.php`
- `app/Modules/ProgramImport/Policies/ImportAccess.php`
- `app/Modules/ProgramImport/Policies/ImportBatchPolicy.php`
- `app/Modules/ProgramImport/ProgramImportServiceProvider.php`
- `app/Modules/ProgramImport/Readiness/ReadinessChecker.php`
- `app/Modules/ProgramImport/Registry/ImportTypeRegistry.php`
- `app/Modules/ProgramImport/Registry/StartPointCatalog.php`
- `app/Modules/ProgramImport/Rollback/RollbackGuard.php`
- `app/Modules/ProgramImport/Rollback/Rollbacker.php`
- `app/Modules/ProgramImport/Silent/ImportContext.php`
- `app/Modules/ProgramImport/Silent/OutboundGuard.php`
- `app/Modules/ProgramImport/Support/AttachmentBag.php`
- `app/Modules/ProgramImport/Support/Audit.php`
- `app/Modules/ProgramImport/Support/BatchFiles.php`
- `app/Modules/ProgramImport/Support/Criteria.php`
- `app/Modules/ProgramImport/Support/FollowUps.php`
- `app/Modules/ProgramImport/Support/FormFetchException.php`
- `app/Modules/ProgramImport/Support/GoogleConnection.php`
- `app/Modules/ProgramImport/Support/GoogleFormFetcher.php`
- `app/Modules/ProgramImport/Support/GoogleFormParser.php`
- `app/Modules/ProgramImport/Support/ImportedRecords.php`
- `app/Modules/ProgramImport/Support/InvitationService.php`
- `app/Modules/ProgramImport/Support/Lifecycle.php`
- `app/Modules/ProgramImport/Support/Masker.php`
- `app/Modules/ProgramImport/Support/Refs.php`
- `app/Modules/ProgramImport/Support/TemplateGenerator.php`
- `app/Modules/ProgramImport/Support/TextFormParser.php`
- `app/Modules/ProgramImport/Support/UserReferences.php`
- `app/Modules/ProgramImport/database/migrations/2026_11_16_000001_create_program_import_tables.php`
- `app/Modules/ProgramImport/database/migrations/2026_11_17_000001_create_program_import_tokens_table.php`
- `app/Modules/ProgramImport/database/migrations/2026_11_17_000002_create_program_import_settings_table.php`
- `app/Modules/ProgramImport/resources/views/claim_invitation.blade.php`

### Core, new files and config
- `app/Service/Program/CustomFields/CustomFieldService.php`
- `app/Service/Program/CustomFields/CustomFieldValueService.php`
- `app/Service/Program/MatchScore/ (MatchScoreEngine + 4 strategies)`
- `app/Models/Programs/ProgramCustomField.php`
- `app/Models/Programs/ProgramCustomFieldValue.php`
- `app/Http/Controllers/Program/ProgramCustomFieldController.php`
- `app/Service/Pending/Scenarios/Owner/ImportFollowUps.php`
- `config/program_imports.php`
- `config/program_fields.php`
- `routes/api/program-imports.php`
- `database/migrations/2026_11_17_000010_create_program_custom_fields_tables.php`

### Tests (22 files)
- `tests/Feature/CustomFields/CustomFieldsTest.php`
- `tests/Feature/ProgramImport/AiAndFormsTest.php`
- `tests/Feature/ProgramImport/ApiImportTestCase.php`
- `tests/Feature/ProgramImport/ApiTest.php`
- `tests/Feature/ProgramImport/AuditFixesTest.php`
- `tests/Feature/ProgramImport/CompletionTest.php`
- `tests/Feature/ProgramImport/DisabledTest.php`
- `tests/Feature/ProgramImport/EscrowRemainderTest.php`
- `tests/Feature/ProgramImport/FollowUpsTest.php`
- `tests/Feature/ProgramImport/GoogleConnectTest.php`
- `tests/Feature/ProgramImport/ImportTestCase.php`
- `tests/Feature/ProgramImport/LandingEndToEndTest.php`
- `tests/Feature/ProgramImport/LandingLifecycleTest.php`
- `tests/Feature/ProgramImport/ModesAndParityTest.php`
- `tests/Feature/ProgramImport/ParityMoreTest.php`
- `tests/Feature/ProgramImport/PipelineReviewTest.php`
- `tests/Feature/ProgramImport/SchemaTest.php`
- `tests/Unit/CustomFields/MatchScoreEngineTest.php`
- `tests/Unit/ProgramImport/HeuristicMapperTest.php`
- `tests/Unit/ProgramImport/NormalizerTest.php`
- `tests/Unit/ProgramImport/ReadinessCheckerTest.php`
- `tests/Unit/ProgramImport/RegistryAndLandingTest.php`

### Documentation (35 files)
- `docs/program-import/DECISIONS.md`
- `docs/program-import/DISCOVERY.md`
- `docs/program-import/LANDING-STATES.md`
- `docs/program-import/QUESTIONS.md`
- `docs/program-import/README.md`
- `docs/program-import/RUNBOOK.md`
- `documentation/frontend/api/v1/ProgramImport/01-catalog-and-templates.md`
- `documentation/frontend/api/v1/ProgramImport/02-batches.md`
- `documentation/frontend/api/v1/ProgramImport/03-mapping-forms-and-rows.md`
- `documentation/frontend/api/v1/ProgramImport/04-commit-rollback-invitations.md`
- `documentation/frontend/api/v1/ProgramImport/README.md`
- `documentation/frontend/api/v1/ProgramImport/field-dictionary.md`
- `documentation/frontend/api/v1/ProgramImport/templates/tujitume-import-applications.csv`
- `documentation/frontend/api/v1/ProgramImport/templates/tujitume-import-checkpoints.csv`
- `documentation/frontend/api/v1/ProgramImport/templates/tujitume-import-disbursement.xlsx`
- `documentation/frontend/api/v1/ProgramImport/templates/tujitume-import-disbursements.csv`
- `documentation/frontend/api/v1/ProgramImport/templates/tujitume-import-fields.csv`
- `documentation/frontend/api/v1/ProgramImport/templates/tujitume-import-finalization.xlsx`
- `documentation/frontend/api/v1/ProgramImport/templates/tujitume-import-funding_setup.xlsx`
- `documentation/frontend/api/v1/ProgramImport/templates/tujitume-import-milestone_suppliers.csv`
- `documentation/frontend/api/v1/ProgramImport/templates/tujitume-import-milestones.csv`
- `documentation/frontend/api/v1/ProgramImport/templates/tujitume-import-monitoring.xlsx`
- `documentation/frontend/api/v1/ProgramImport/templates/tujitume-import-program.csv`
- `documentation/frontend/api/v1/ProgramImport/templates/tujitume-import-questions.csv`
- `documentation/frontend/api/v1/ProgramImport/templates/tujitume-import-review.xlsx`
- `documentation/frontend/api/v1/ProgramImport/templates/tujitume-import-reviewers.csv`
- `documentation/frontend/api/v1/ProgramImport/templates/tujitume-import-rounds.csv`
- `documentation/frontend/api/v1/ProgramImport/templates/tujitume-import-scores.csv`
- `documentation/frontend/api/v1/ProgramImport/templates/tujitume-import-setup.xlsx`
- `documentation/frontend/api/v1/ProgramImport/templates/tujitume-import-site_visits.csv`
- `documentation/frontend/api/v1/ProgramImport/templates/tujitume-import-submissions.csv`
- `documentation/frontend/api/v1/ProgramImport/templates/tujitume-import-suppliers.csv`
- `documentation/frontend/api/v1/ProgramImport/templates/tujitume-import-winners.csv`
- `documentation/frontend/api/v1/Programs/custom-fields.md`
- `documentation/frontend/api/v1/Programs/match-score-engine.md`

### Frontend (29 files)
- `src/amari/assistant/appKnowledge/sections/program-org/custom-application-fields.js`
- `src/amari/assistant/appKnowledge/sections/program-org/import-program.js`
- `src/amari/assistant/appKnowledge/sections/program-org/import-safety-and-undo.js`
- `src/amari/assistant/appKnowledge/sections/program-org/import-start-points.js`
- `src/amari/assistant/appKnowledge/sections/program-org/import-troubleshooting.js`
- `src/amari/assistant/pageGuides/ImportProgramGuide.jsx`
- `src/api/programFields/index.js`
- `src/api/programImports/index.js`
- `src/i18n/locales/en/programs/fields.json`
- `src/i18n/locales/en/programs/import.json`
- `src/i18n/locales/sw/programs/fields.json`
- `src/i18n/locales/sw/programs/import.json`
- `src/pages/dashboard/program-org/programs/bulk-upload/CancelImport.jsx`
- `src/pages/dashboard/program-org/programs/bulk-upload/CommitStep.jsx`
- `src/pages/dashboard/program-org/programs/bulk-upload/DataStep.jsx`
- `src/pages/dashboard/program-org/programs/bulk-upload/FormStep.jsx`
- `src/pages/dashboard/program-org/programs/bulk-upload/ImportHistory.jsx`
- `src/pages/dashboard/program-org/programs/bulk-upload/MappingStep.jsx`
- `src/pages/dashboard/program-org/programs/bulk-upload/ReviewStep.jsx`
- `src/pages/dashboard/program-org/programs/bulk-upload/StartStep.jsx`
- `src/pages/dashboard/program-org/programs/bulk-upload/helpers.js`
- `src/pages/dashboard/program-org/programs/bulk-upload/index.jsx`
- `src/pages/dashboard/program-org/programs/bulk-upload/useImportBatch.js`
- `src/pages/dashboard/program-org/programs/fields/FieldForm.jsx`
- `src/pages/dashboard/program-org/programs/fields/InfoValues.jsx`
- `src/pages/dashboard/program-org/programs/fields/index.jsx`
- `src/pages/dashboard/program-org/programs/fields/matchTypes.js`
- `src/store/global/programFields/index.js`
- `src/store/global/programImports/index.js`

## 3. Core files changed (everything outside the module that already existed)
| File | Change | Reason |
|---|---|---|
| `config/app.php` | one provider line | Laravel loads providers here |
| `app/Service/Notification/ProgramNotificationService.php` | recipient-loop guard | silent mode, unclaimed imported accounts |
| `app/Service/Notification/EmailService.php` | first-line guard | same, for mail outside the program service |
| `app/Service/Notification/NotificationService.php` | first-line guard | in-app notifications and the broadcast |
| `app/Service/AiReview/AiReviewDispatcher.php` | first-line guard | no AI review while importing |
| `app/Console/Commands/UpdateProgramStatus.php` | imported rounds skip `round.closing_soon` | scheduler guard |
| `app/Http/Controllers/CheckoutMpesaController.php` | escrow charges only the unreleased remainder; bulk path skips released milestones | approved money fix (M-Pesa/LIPR only; Stripe untouched; no change when nothing is released) |
| `app/Http/Controllers/Program/MilestoneVerificationController.php` | `verification_notes` instead of the non-existent `notes` column | bug found by the lifecycle test: the next milestone never unlocked |
| `ProgramController`, `ProgramApplicationController`, `MatchController`, `PublicProgramPreviewController`, `routes/api/v1/programs.php`, `Program` model | custom-field hooks and routes | new core feature; no change for programs without fields |
| `phpunit.xml` | test-only `APP_KEY`; module off in tests unless set in the shell | deterministic tests |
| `.env`, `.env.example` | module variables (`.env` has the module ON) | enabling |
| Frontend: `routes/*`, `i18n/loadTranslations.js`, programs/discover/manage/import/fields locales, `apply/*` (3 files), program-org and entrepreneur dashboard pages, programs and business stores, `bulk-upload` route target | wizard route, fields route, applicant form fields; two dashboard stat calls moved behind their stores so the architecture gate passes | integration |

## 4. Migrations (none run yet)
- `database/migrations/2026_11_17_000010_create_program_custom_fields_tables.php` (core)
- `app/Modules/ProgramImport/database/migrations/2026_11_16_000001_create_program_import_tables.php`
- `app/Modules/ProgramImport/database/migrations/2026_11_17_000001_create_program_import_tokens_table.php`
- `app/Modules/ProgramImport/database/migrations/2026_11_17_000002_create_program_import_settings_table.php`
Module migrations load only while `PROGRAM_IMPORTS_ENABLED=true`. No column was added to any existing table.

## 5. Approved seams
All approved by the requester ("implement all", then the explicit escrow approval). Exact diffs are in `docs/program-import/README.md` ("Core changes"). Each guard is behind `class_exists`, so deleting the module removes its effect.

## 6. Feature detection result and how the module adapted
Detected: review methods and the AI pipeline (reused, off by default); reviewer assignments, orders and payments (not imported, roster only); pre-agreements and planning modes (verification flags imported, ready flags set for active milestones); escrow and per-milestone release (fixed for remainders); organizations and roles (reused `ProgramAccess` ideas); LLM router, rate governor and privacy gate (reused for AI mapping). **Absent at discovery and since built:** custom fields and the server match score. **Absent and not needed:** the knowledge layer (no exemption to record).

## 7. Verified landing states
Reference: `docs/program-import/LANDING-STATES.md` (generated from the landing classes) and `tests/Feature/ProgramImport/LandingEndToEndTest.php` + `LandingLifecycleTest.php`, which run the owner's next steps through the existing endpoints.
| Start point | Program | Rounds | Applications | Milestones | Next steps proven by tests |
|---|---|---|---|---|---|
| setup | draft, hidden | draft | none | none | assign a Business Coach, start funding the wallet, confirm steps, publish |
| review | under_review, hidden | earlier finalized; live round published with past dates | R1 pending+submitted; R2+ approved+submitted | none | accept/reject (R1), advance, finalize |
| finalization | under_review | same | approved+scored; average and eligibility via `ScoreAverager` | none | finalize, awardees, program awarded |
| funding_setup | awarded | finalized (real or "Imported awardees") | awarded, advanced, setup not started, planning locked | templates | planning mode, templates, activate |
| disbursement | awarded | finalized | awarded, setup completed | active; first approved, or locked when a verification is required | verification, release, mark paid, completion, sign-off, next milestone unlocks |
| monitoring | awarded (completed when every milestone is done) | finalized | awarded | optional | applicant submits a checkpoint, owner verifies, analytics |

## 8. Questions asked and answered; `assumed` decisions
All questions are answered; see `docs/program-import/QUESTIONS.md` (final answers table) and `DECISIONS.md` (dated log; superseded entries are struck through). Assumed defaults that the requester can still change: review landing uses a `published` round with past dates (not `in_review`); planning mode `locked`; synthetic finalized "Imported awardees" round; manual review method; no "imported" badge in existing screens; supplier payment details kept in notes; checkpoints unsent unless `mark_checkpoints_sent`; AI real samples off unless the organization consents AND the privacy gate passes.

## 9. Open items (honest list)
1. **Amari source reviews: done, with a stated method and its limits.** 113 older features were flagged because they track shared files (`routes/paths.js`, `routes/r.js`, stores, locales) changed by a large `grant`→`program` rename on 2026-11-16 18:34 (not part of this work) and, for a few, by this work. The receipts cannot diff per feature, so each flagged doc was verified against the current product instead of being recorded blindly: every label its entry points and menu path quote was checked against the current English UI strings, menus and the feature's own source files (104 matched outright; the 9 that did not are structural descriptors such as "Awardees table" or a label that carries an amount, "Pay {{amount}} into escrow", read and confirmed by hand), and routes and `data-qa` targets are verified by `amari:index:check`, `amari:guides:check` and `amari:coverage`. The label check found real mismatches only in this work's own sections, which were corrected (menu path and button names) before recording. **Limit:** this verifies labels, routes and targets, not every sentence of prose; behavior described in steps was not re-derived from code for the 113 older features.
2. **Migrations and enabling.** Not run; handed to the backend developer as task 10 in `tujitume-frontend/backend-fix-for-dev.txt`.
3. **Real-environment verification.** Not done: SQLite and fakes only; no browser run of the wizard, the Amari guide, or the custom-field screens.
4. Known limits: preview text from the server is English only; imported rounds never send closing reminders (a re-dated round keeps that silence); reviewers are a roster only and site visits are historic records only.

## 10. Deployment actions (human)
1. Backend dev: set `PROGRAM_IMPORTS_ENABLED=true` (+ `PROGRAM_IMPORTS_DISK=s3`, retention, AI flags), run `php artisan migrate`, run `php artisan queue:work --queue=program-imports`, keep the scheduler running.
2. Optional Google connect: `PROGRAM_IMPORTS_GOOGLE_OAUTH=true`, `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, redirect `https://<api host>/api/v1/program-imports/integrations/google/callback` (Forms scope may need Google app verification).
3. Smoke test on staging with each start point template, then undo each import.
4. (Done) The Amari source reviews are recorded; commit `sourceReviews.json` with the change.
5. When something breaks during an import the owner sees it in the Pending list with a plain explanation, can download only the rows to fix, try again, and mark it handled; nothing needs a developer.
