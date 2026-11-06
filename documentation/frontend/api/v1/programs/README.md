# Programs API v1

**Base URL:** `/api/v1/programs`

Unless a route explicitly permits public access, authenticate with Sanctum. Program owners must be authorized for the program; applicants may only submit and view their own applications.

## Endpoint index

| Area | Reference |
|---|---|
| Program creation and score settings | [Programs](programs.md) |
| Round questions and match-score configuration | [Custom fields / round questions](custom-fields.md) |
| Application payload, preview, and responses | [Applications](applications.md) |
| Scoring matrix, rules, and lifecycle | [Match-score engine](match-score-engine.md) |
| Frontend migration checklist | [Migration notes](migration-notes.md) |
| Full create/apply/score walkthrough | [How program application match score works](how-program-application-matchScore-works.md) |
| Round setup and review | [Rounds](rounds/rounds.md), [Questions and answers](rounds/questions-answers.md) |
| Wallet and payments | [Wallet](wallet.md), [Payments](../payments/README.md) |

## Match-score architecture

The score is configured on the program with `use_match_score` and `match_score_limit`, but individual scored questions belong to Round 1 in `round_custom_questions`. There is no program-level custom-field scoring endpoint or `custom_fields` create payload. Round 2 and later use reviewer rubric scores only.

Applicant-facing question resources omit `match_points` and `match_answers`; program-owner question-management responses include them. See the [match-score engine](match-score-engine.md) for all scoring and visibility rules.

## User types

| ID | Role | Program permissions |
|---:|---|---|
| 1 | business_owner | Apply, submit evidence, edit permitted plans |
| 2 | investor | No program-owner operations |
| 3 | service_provider | No program-owner operations |
| 4 | organization | Own and operate programs |
| 5 | admin | Administrative access where configured |
| 6 | external_reviewer | Score assigned rounds and site visits |
