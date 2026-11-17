# Landing-state reference

Values below are written by the landing classes in `app/Modules/ProgramImport/Landing` and are verified by `tests/Feature/ProgramImport/LandingEndToEndTest.php`, which runs the owner's next steps through the existing endpoints.

## `setup`: Set up a program

A draft, hidden program with its rounds and questions. Nothing is published.

- Required data: program, rounds
- Optional data: questions, fields
- Lands: **program**: draft, hidden
- Lands: **rounds**: draft, dates exactly as given (never invented)
- Lands: **applications**: none
- Lands: **milestones**: none
- Program write values: `{"status":"draft","visible":false}`
- Application write values (round 2): `[]`
- Milestone write values (first): `[]`
- Next steps: Configure the rounds; Assign Business Coaches; Fund the program wallet; Publish
- Skipped: Rounds are not published: finish the publish checklist yourself.

## `review`: Start at review

Applications arrive already submitted; earlier rounds are marked finalized. Start scoring or deciding.

- Required data: program, rounds, applications
- Optional data: questions, fields, reviewers
- Lands: **program**: under_review, hidden
- Lands: **rounds**: earlier rounds finalized; the target round published with past dates so it can be reviewed and finalized
- Lands: **applications**: round 1: pending + submitted; round 2+: approved + submitted
- Lands: **milestones**: none
- Program write values: `{"status":"under_review","visible":false}`
- Application write values (round 2): `{"status":"approved","round_status":"submitted","knockout_status":"pending"}`
- Milestone write values (first): `[]`
- Next steps: Assign Business Coaches or score applications; Accept or reject (round 1); Finalize the round
- Skipped: Earlier-round history is synthetic; reviewers, site visits and payments are not imported.
- Skipped: The target round must have a review period end date in the past before Finalize works.

## `finalization`: Start at finalization

Applications arrive scored, with averages and eligibility computed the same way as manual scoring.

- Required data: program, rounds, applications, scores
- Optional data: questions, fields, reviewers
- Lands: **program**: under_review, hidden
- Lands: **rounds**: earlier rounds finalized; the target round published with past dates so it can be reviewed and finalized
- Lands: **applications**: scored: average score and eligibility computed exactly as manual scoring
- Lands: **milestones**: none
- Program write values: `{"status":"under_review","visible":false}`
- Application write values (round 2): `{"status":"approved","round_status":"scored","knockout_status":"pending"}`
- Milestone write values (first): `[]`
- Next steps: Finalize the round; Confirm awardees
- Skipped: Earlier-round history is synthetic; reviewers, site visits and payments are not imported.
- Skipped: The target round must have a review period end date in the past before Finalize works.

## `funding_setup`: Start at funding setup

Awardees only. The program is awarded and each awardee is ready for milestone planning.

- Required data: program, winners
- Optional data: rounds, applications, milestones
- Lands: **program**: awarded, hidden
- Lands: **rounds**: finalized (real history when given, else a synthetic "Imported awardees" round)
- Lands: **applications**: awarded, funding setup not started, planning mode locked
- Lands: **milestones**: templates (is_template) when given
- Program write values: `{"status":"awarded","visible":false}`
- Application write values (round 2): `{"status":"awarded","round_status":"advanced","planning_mode":"locked","funding_setup_status":"not_started"}`
- Milestone write values (first): `{"is_template":true,"status":"pending","fund_release_status":"locked","created_by_role":"program_owner"}`
- Next steps: Choose planning mode; Create milestone templates; Submit and approve the plan; Activate
- Skipped: Earlier-round history is synthetic; reviewers, site visits and payments are not imported.

## `disbursement`: Start at disbursement

Awardees with active milestones. Historic payments are recorded only; no money moves.

- Required data: program, winners, milestones
- Optional data: suppliers, milestone_suppliers, disbursements
- Lands: **program**: awarded, hidden
- Lands: **rounds**: finalized (real history when given, else a synthetic "Imported awardees" round)
- Lands: **applications**: awarded, funding setup completed
- Lands: **milestones**: active (not templates); first approved, others locked unless the file says released
- Program write values: `{"status":"awarded","visible":false}`
- Application write values (round 2): `{"funding_setup_status":"completed","status":"awarded","round_status":"advanced","planning_mode":"locked"}`
- Milestone write values (first): `{"is_template":false,"status":"pending","fund_release_status":"approved","created_by_role":"program_owner"}`
- Next steps: Fund escrow for the remainder; Verify milestones; Release funds; Complete milestones
- Skipped: Earlier-round history is synthetic; reviewers, site visits and payments are not imported.
- Skipped: Historic payments are recorded only: no wallet, escrow or program balance change.

## `monitoring`: Start at monitoring & evaluation

Awardees with M&E checkpoints, ready for verification and analytics.

- Required data: program, winners, checkpoints
- Optional data: milestones, disbursements, submissions, site_visits
- Lands: **program**: awarded (completed when every milestone is done), hidden
- Lands: **rounds**: finalized (real history when given, else a synthetic "Imported awardees" round)
- Lands: **applications**: awarded, funding setup completed
- Lands: **milestones**: optional
- Program write values: `{"status":"awarded","visible":false}`
- Application write values (round 2): `{"funding_setup_status":"completed","status":"awarded","round_status":"advanced","planning_mode":"locked"}`
- Milestone write values (first): `{"is_template":false,"status":"pending","fund_release_status":"approved","created_by_role":"program_owner"}`
- Next steps: Send or verify checkpoints; Review analytics
- Skipped: Earlier-round history is synthetic; reviewers, site visits and payments are not imported.
- Skipped: Historic payments are recorded only: no wallet, escrow or program balance change.

