# Program Import Module: Discovery (Gate 1)

Date: 2026-11-16. Read-only discovery; no migrations or code written. Paths are relative to `tujitume-api/`.
Stack: Laravel 10.10, PHP 8.2, Pest 2.36, `maatwebsite/excel` ^3.1 installed. Frontend: `tujitume-frontend` (React + Vite), exists.

## 1. Existing import work (Section 1A)
| Found | Where | Notes |
|---|---|---|
| Google Form question preview | `POST programs/questions/import/google-form` -> `GoogleFormImportController::preview` (throttle 20/min), `app/Service/Program/GoogleFormReader.php`, `GoogleFormTypeGuesser.php` | Already fetches/parses a Google Form into question drafts. Overlaps module "form import". See Q-EXISTING-FORM. |
| `BulkImportListings` | `app/Service/Unused/` | Unused, business listings. Leave alone. |
| `maatwebsite/excel` | composer.json | Available for streaming reads (chunk reading); no existing program import. |
| `ProgramImportBatch`-style tables | none | Clean slate. No `app/Modules` layout exists; module will be the first. |

## 2. Program module as built (1B)
Routes: `routes/api/v1/programs.php`, wrapped in `auth:sanctum, extend-token, throttle:api` (per file header) plus `program, program.response` middleware under prefix `/programs`. The module routes must replicate this stack.
Controllers: `app/Http/Controllers/Program/*` and `Rounds/*` (ProgramController 1114 lines, ProgramRoundController 1843, ProgramMilestoneController 1092, ProgramApplicationController 1060, RoundReviewerController 1029, SupplierDirectory 900, MilestoneVerification 879, MEController 591, Disbursement 558).
Services: `app/Service/Program/` (RoundHistoryService, RoundFinalizationService, KnockoutEvaluator, RubricCalculator, **ScoreAverager** (reusable), RoundParticipation, RoundHelperService, RoundMatchScore, ProgramAccess, ReviewerPaymentService, GoogleFormReader). Notifications: `app/Service/Notification/{ProgramNotificationService,EmailService,NotificationService,BulkEmailService}`.
Scheduler (`app/Console/Kernel.php`):
- `update:program-status` daily: `published` programs with start date passed become `open` (+ `program.opened` notification); `open` programs past deadline become `closed` (+ `program.closed`); published rounds closing in 3 days email all applicants (`round.closing_soon`).
- hourly: `send:milestone_reminder`, `run:service-cron-actions`, `release:service_milestone_payment`, `send:bid_approval_reminder`, `ai-reviews:sweep`; daily `ai-reviews:purge-extractions`. Also commands `MilestoneNotifyActions`, `investProgressReminder` (not scheduled here; check before Gate 3).
Observers/listeners: none found for programs in `app/` by name; `Program::booted` only assigns `public_id` (ULID). To re-verify at Gate 2 by grepping `Providers/EventServiceProvider` and model `booted()` hooks.

## 3. Feature detection report (1C)
| Feature | Status | Evidence / consequence |
|---|---|---|
| Program custom fields + server match-score engine | **ABSENT at Gate 1 (since built)** | `instructions-program-custom-field-and-match-score-logic.md` exists but no custom-field tables/code; migration `2026_11_04_000003_drop_legacy_program_fields` dropped legacy columns. `use_match_score` and `match_score_limit` exist on programs; `RoundMatchScore` service and `MatchController::score` exist for rounds. Consequence: the Fields type is **not built**; `custom:` columns and `compute_match_score` are out of scope until the feature lands. Match score imported as snapshot only. |
| Review methods manual / ai_assisted / ai_only + AI pipeline | PRESENT | `rounds.review_method`, `ai_review_paused_at`; `AiReviewDispatcher::safely`, `RunApplicationAiReviewJob`, `ai-reviews:sweep`. Dispatch points: `submitRound` and others call `AiReviewDispatcher::safely`. |
| Reviewer assignments / acceptance / orders / payments | PRESENT | `RoundReviewerController`, `ReviewerOrder`, `ReviewerPaymentService`. Not imported in v1 (Q-REVIEWERS). |
| Pre-agreements, final_approval | PRESENT | `MilestonePreAgreement`, `milestones.*_required/_ready`; checked in `activateTemplates` only when `planning_mode = hybrid`. |
| Planning mode / allowed_edits | PRESENT | `program_applications.planning_mode` enum `locked, hybrid` (on application); `program_milestones.allowed_edits` json. |
| Escrow (`escrow_funded`, `escrow_amount`) | PRESENT | columns on applications. Bulk path `ProgramController::release_fst_milestone` uses **Stripe** (see Q-ESCROW). |
| Knowledge layer (AiTaskRunner, ContextBuilder) | **ABSENT** | no matches in `app/`. No coverage table to update. |
| LLM provider abstraction | PRESENT | `app/Service/AiReview/{ProviderRouter,OpenAiCompatibleClient,RateGovernor,PrivacyGate,Redactor}`; test double `tests/Feature/Programs/AiReview/FakeLlmClient.php`. Reuse; a small LlmClient interface may be needed if none is formal. |
| Organizations / workspaces / roles | PRESENT | `ProgramAccess::owns`: owner, or org user (user_type 4) with role in super_admin/admin/editor/viewer (viewer is included in this list, so "viewer read-only" needs its own check). |
| Frontend | PRESENT | `tujitume-frontend` (React). Wizard UI can be built. No existing import screens. |
| User types | verified | `UserTypeSeeder`: 1 business_owner, 6 external_reviewer (others per seeder; 4 organization used by ProgramAccess). |

## 4. Lifecycle contracts as coded (1D). These differ from the prompt in several places
**Statuses (migrations):** program `draft, published, open, closed, under_review, awarded, cancelled, completed`; round `draft, published, closed, in_review, finalized`; application `draft, pending, under_review, approved, rejected, waitlisted, awarded, withdrawn` (+ `completed` in config/status); round_status `draft, submitted, under_review, assigned, scored, advanced, not_selected, ...`; knockout `pending, passed, failed`; planning_mode `locked, hybrid`; milestone fund_release_status `locked, approved, processing, released, partial` (**default locked; no `pending`**); milestone status `pending, submitted, under_review, audit_requested, revision_requested, approved, rejected, disbursing, ...`; disbursement `pending, processing, completed, failed, reversed`; payment_method `mpesa_paybill, mpesa_mobile, mpesa_till, bank_transfer, pochi, other`; recipient_type `supplier, business_owner`; checkpoint status `pending, submitted, verified, changes_requested`.

**Round 1 accept** (`ProgramApplicationController::accept`, line 584): requires `status = pending`; owner = `program_owner_id`; enforces max_awardees using count of awarded; single_round: status `approved` + round_status `advanced`; multi_round: `approved`, `RoundHistoryService::closeAndCreate`, `current_round_id` = next round, round_status `draft`. Note it does **not** check `round.status`. `reject`: `status=rejected, round_status=not_selected`.
**advanceManual / rejectManual** (rounds 2+ manual mode): require round `advancement_mode=manual` and **round.status = `published`** (advanceManual). A round landed as `in_review` blocks advanceManual. Awarded amount = `sum(program_milestones.amount)`, which is 0 for imported winners until milestones exist.
**finalize**: needs round not `finalized`, not `draft`; **review period end date strictly in the past** (`review_period_end ?? close_date`, else 422 "Set a review period end date"). Manual mode: no undecided apps in `submitted/assigned/under_review/scored/draft`; final round sets program `awarded`. Scored modes (`score_threshold`, `fixed_quota`): processes `round_status=scored`, runs `KnockoutEvaluator` (apps with no knockout questions pass; with questions and no answers become `pending` and are not failed), applies `max_awardees`, writes history, moves winners to next round (`round_status=draft`, scores reset, `knockout_status=pending`). Sends notifications.
**Score submission**: `ApplicationScoreController::store`; requires round not finalized, application submitted (`RoundParticipation`), reviewer = owner or assigned. Writes `ApplicationScore(application,round,reviewer)` `source=human`, `total_score` from `RubricCalculator`, then `ScoreAverager::apply` sets `average_score`, `round_status=scored`, `is_eligible_to_advance` (threshold: `avg >= score_threshold`; fixed_quota/manual: true). Both services are side-effect free and reusable.
**submitRound**: sets `round_status=submitted`, history `submitted_at`; then AI dispatch + notification. Round 1 counts as submitted automatically in `RoundParticipation`.
**award** (`ProgramMilestoneController::award`): needs `status=approved` and milestones; reserves program wallet funds (money code, do not call). Winner paths via finalize/advance/accept set `awarded` without touching the wallet.
**Funding setup**: `storeTemplate` needs `status=awarded`; `activateTemplates`: total must be `<= total_amount_requested` (**uses requested, not awarded amount**), hybrid mode requires `agreed` pre-agreements for required checks, first milestone becomes `approved` unless `mprv_required`, others `locked`; sets `is_template=false`. Remaining update (completes `funding_setup_status`) was not fully read.
**Escrow bulk** `release_fst_milestone`: Stripe charge; sums only the **first milestone (lowest id) per application** and skips if `status === 1` (int compared to a string enum, likely never true). Confirms Q-ESCROW.
**Monitoring**: `m_e_checkpoints` (app_id, program_id, name, type, due_date, status `pending|submitted|verified|changes_requested`, `respondent`, `should_notify_applicant`, `sent_at`).

## 5. Programs table reality (1E)
Columns: user_id, organization_id, program_industry_id, program_title, program_description (500), total_program_amount, available_amount, funding_per_business, disbursement_type (`supplier|hybrid|beneficiary`), application_start_date/deadline, require_kyc_verification_to_apply, use_match_score, match_score_limit, status, visible, currency (**enum KES|USD only**), program_type, total_rounds, max_awardees, public_id (ULID). Store requires an organization on the acting user and creates a wallet (`ProgramWallet::create` at ProgramController:281) plus round 1 automatically. `store` validation enforces `application_start_date >= today` (import bypasses). `max_awardees` default `floor(total/funding_per_business)`.
Applications: `program_owner_id` NOT NULL (owner), `user_id` nullable (guest allowed since 2026-11-09), `sector` and `headquarters_location` NOT NULL, `total_amount_requested` NOT NULL, `awarded_at` is a **string** column, `uid` column added 2026-11-10. Application unique per round pair: rounds unique `(program_id, round_number)`; milestones unique `(app_id, sequence_order)`.

## 6. Proposed landing states (verified against code, pending answers)
| Start | Program | Round(s) | Applications | Milestones | Blockers found |
|---|---|---|---|---|---|
| setup | draft, visible=false | draft, dates null | none | none | none |
| review | `under_review`? or `closed` (Q-STATUS). Scheduler only touches published/open, so either is safe from the cron | earlier finalized; target round `published` so accept/reject/advance work (not `in_review`) | R1: `pending`/`submitted`/knockout pending; R2+: `approved`/`submitted` | none | `finalize` also needs review-period-end in the past; imported rounds need a past `review_period_end` |
| finalization | same | target `published` | R2+: `approved`, `scored`, avg and eligibility via ScoreAverager | none | same |
| funding_setup | `awarded` | last round `finalized` (synthetic or real) | `awarded`, `round_status=advanced`, funding_setup `not_started` | templates `is_template=true` | planning mode only `locked|hybrid` |
| disbursement | `awarded` | finalized | awarded, funding_setup `completed` | `is_template=false`, status/fund_release per file | Q-ESCROW; Stripe path |
| monitoring | `awarded`/`completed` | finalized | awarded/completed | optional | none |

## 7. Readiness matrix (what can be built now)
| Capability | Ready? |
|---|---|
| setup, review, finalization, funding_setup, disbursement (record-only), monitoring importers | Yes, using direct model writes + ScoreAverager/RubricCalculator/RoundHistoryService |
| Fields / custom answers / compute_match_score | No (feature absent) |
| Disbursement landing with partially released milestones | Blocked until Q-ESCROW answered |
| AI mapping | Yes via existing provider stack; heuristic fallback always |
| Google Form URL | Partly: reuse/extend GoogleFormReader (Q-EXISTING-FORM) |
| Knowledge-layer exemption | N/A (absent) |

## 8. Seams needed (for Gate 3; none applied)
1. OutboundGuard calls in `ProgramNotificationService::send` (covers notification + email for all program events; the single loop at the recipient level), plus `EmailService::send` for non-program mail (supplier welcome, invitations) and `AiReviewDispatcher::safely/dispatchFor`.
2. Scheduler skip for imported programs in `UpdateProgramStatus` (programs `published/open` only; imported programs are never `published`, so the **status-based landing alone may avoid the seam**; `round.closing_soon` hits `published` rounds, which review landings need, so a guard is required there; AI sweep too).
3. Possibly `Disbursement`/escrow correctness (Q-ESCROW).
4. Behavior-preserving extraction: not needed so far (ScoreAverager, RubricCalculator, RoundHistoryService already extracted).

## 9. Risks
- Round `published` landing is required for accept/reject/advance, but a published round makes `closing_soon` emails and applicant submissions possible; mitigated by past dates plus a scheduler guard.
- `awarded_amount` derived from milestones is 0 for awardee-only imports; `activateTemplates` compares against `total_amount_requested`.
- `currency` enum KES/USD only; other currencies cannot be imported without a core migration.
- `viewer` role listed among program team roles: module policy must make viewers read-only explicitly.
- Existing test suite has no module-on/off toggle; need CI matrix.
- Repo has no git (this workspace is not a git repo), so rollback of my own changes relies on the module being isolated.
