# Field dictionary

Generated from `ImportTypeRegistry` (the single source of truth). Do not edit by hand; regenerate after registry changes.
Headers in templates are the **Field** keys. Dates `YYYY-MM-DD` (or the batch `date_format`). Lists separate items with `;`.

## `program`: Program

Depends on: nothing

| Field | Label | Type | Required | Allowed values / default |
|---|---|---|---|---|
| `program_ref` | Program reference | string | no |  |
| `program_title` | Program title | string | yes |  |
| `program_description` | Description | text | no |  |
| `total_program_amount` | Total program amount | money | yes |  |
| `funding_per_business` | Funding per business | money | yes |  |
| `disbursement_type` | Disbursement type | enum | yes | supplier, hybrid, beneficiary |
| `program_type` | Program type | enum | yes | single_round, multi_round |
| `total_rounds` | Total rounds | int | yes | default 1 |
| `program_industry` | Industry (id or name) | string | yes |  |
| `currency` | Currency | enum | yes | KES, USD |
| `application_start_date` | Application start date | date | yes |  |
| `application_deadline` | Application deadline | date | yes |  |
| `max_awardees` | Max awardees | int | no |  |
| `require_kyc_verification_to_apply` | Require KYC to apply | bool | no | default false |
| `match_score_limit` | Match score limit | int | no |  |

## `rounds`: Rounds

Depends on: `program`

| Field | Label | Type | Required | Allowed values / default |
|---|---|---|---|---|
| `program_ref` | Program reference | string | no |  |
| `round_ref` | Round reference | string | no |  |
| `round_number` | Round number | int | yes |  |
| `round_name` | Round name | string | yes |  |
| `open_date` | Open date | date | no |  |
| `close_date` | Close date | date | no |  |
| `review_period_end` | Review period end | date | no |  |
| `announcement_date` | Announcement date | date | no |  |
| `rubric_mode` | Rubric mode | enum | no | weighted, simple_total, pass_fail; default "simple_total" |
| `scoring_criteria` | Scoring criteria (name:weight:max, ...) | list | no |  |
| `knockout_questions` | Knockout questions | list | no |  |
| `required_documents` | Required documents | list | no |  |
| `assignment_type` | Assignment type | enum | no | owner_only, internal, external, both; default "owner_only" |
| `assignment_method` | Assignment method | enum | no | manual, round_robin, load_balanced; default "manual" |
| `advancement_mode` | Advancement mode | enum | no | manual, score_threshold, fixed_quota; default "manual" |
| `score_threshold` | Score threshold | decimal | no |  |
| `max_advancing` | Max advancing | int | no |  |
| `tie_breaker_rule` | Tie breaker | enum | no | allow_over_cap, secondary_metric, manual |

## `questions`: Round questions

Depends on: `rounds`

| Field | Label | Type | Required | Allowed values / default |
|---|---|---|---|---|
| `round_ref` | Round reference | string | yes |  |
| `question_ref` | Question reference | string | yes |  |
| `question_text` | Question | text | yes |  |
| `question_type` | Type | enum | yes | text, paragraph, choice, checkbox, scale, date |
| `is_required` | Required | bool | no | default false |
| `options` | Options | list | no |  |
| `help_text` | Help text | text | no |  |

## `fields`: Custom fields

Depends on: `program`

| Field | Label | Type | Required | Allowed values / default |
|---|---|---|---|---|
| `program_ref` | Program reference | string | no |  |
| `field_ref` | Field reference | string | no |  |
| `label` | Label | string | yes |  |
| `audience` | Audience | enum | no | application, info; default "application" |
| `type` | Type | enum | yes | text, textarea, number, boolean, date, select, multi_select, file, url |
| `is_required` | Required | bool | no | default false |
| `options` | Options | list | no |  |
| `help_text` | Help text | text | no |  |
| `scoring_match_type` | Suggested match type (never applied automatically) | enum | no | in_set, range, equals, provided |
| `scoring_weight` | Suggested weight | decimal | no |  |
| `scoring_target` | Suggested target (list, or min..max, or true/false) | text | no |  |
| `info_value` | Value (info fields only) | text | no |  |

## `reviewers`: Business Coaches (roster only)

Depends on: `rounds`

| Field | Label | Type | Required | Allowed values / default |
|---|---|---|---|---|
| `round_ref` | Round reference | string | yes |  |
| `email` | Email of an existing account | email | yes |  |
| `reviewer_type` | Type | enum | no | internal, external; default "external" |
| `expertise_tags` | Expertise | list | no |  |
| `max_apps_assigned` | Max applications | int | no |  |

## `applications`: Applications

Depends on: `program`, `rounds`

| Field | Label | Type | Required | Allowed values / default |
|---|---|---|---|---|
| `application_ref` | Application reference | string | yes |  |
| `program_ref` | Program reference | string | no |  |
| `round_number` | Current round number | int | no | default 1 |
| `startup_name` | Business name | string | yes |  |
| `contact_person_name` | Contact person | string | no |  |
| `email` | Applicant email | email | no |  |
| `phone` | Phone | phone | no |  |
| `sector` | Sector | string | yes |  |
| `headquarters_location` | Headquarters location | string | yes |  |
| `total_amount_requested` | Amount requested | money | yes |  |
| `stage` | Stage | string | no |  |
| `revenue_last_12_months` | Revenue (12 months) | money | no |  |
| `traction_kpis` | Traction | text | no |  |
| `social_impact_areas` | Social impact areas | list | no |  |
| `pitch_deck_file` | Pitch deck (link or file name in the attachments ZIP) | link_or_file | no |  |
| `pitch_video` | Pitch video link | url | no |  |
| `business_plan_file` | Business plan (link or file name in the attachments ZIP) | link_or_file | no |  |
| `match_score` | Match score (snapshot) | decimal | no |  |
| `outcome` | Outcome | enum | no | pending, rejected |
| `knockout_result` | Knockout result | enum | no | passed, failed |

## `scores`: Scores

Depends on: `applications`

| Field | Label | Type | Required | Allowed values / default |
|---|---|---|---|---|
| `application_ref` | Application reference | string | yes |  |
| `round_number` | Round number | int | no |  |
| `total_score` | Total score | decimal | no |  |
| `criterion` | Criterion | string | no |  |
| `criterion_score` | Criterion score | decimal | no |  |
| `comment` | Comment | text | no |  |

## `winners`: Winners (awardees)

Depends on: `program`

| Field | Label | Type | Required | Allowed values / default |
|---|---|---|---|---|
| `application_ref` | Application reference | string | no |  |
| `program_ref` | Program reference | string | no |  |
| `startup_name` | Business name | string | no |  |
| `email` | Applicant email | email | no |  |
| `awarded_amount` | Awarded amount | money | yes |  |
| `awarded_at` | Award date | date | no |  |
| `sector` | Sector | string | no |  |
| `headquarters_location` | Headquarters location | string | no |  |
| `planning_mode` | Planning mode | enum | no | locked, hybrid; default "locked" |

## `suppliers`: Suppliers

Depends on: nothing

| Field | Label | Type | Required | Allowed values / default |
|---|---|---|---|---|
| `supplier_ref` | Supplier reference | string | yes |  |
| `legal_name` | Legal name | string | yes |  |
| `email` | Email | email | no |  |
| `phone` | Phone | phone | no |  |
| `payment_method` | Payment method | enum | no | mpesa_mobile, mpesa_paybill, mpesa_till, bank_transfer, other |
| `payment_details` | Payment details | text | no |  |

## `milestones`: Milestones

Depends on: `winners`

| Field | Label | Type | Required | Allowed values / default |
|---|---|---|---|---|
| `application_ref` | Application reference | string | yes |  |
| `milestone_ref` | Milestone reference | string | yes |  |
| `sequence_order` | Sequence | int | yes |  |
| `title` | Title | string | yes |  |
| `description` | Description | text | no |  |
| `amount` | Amount | money | yes |  |
| `status` | Status | enum | no | pending, approved, completion_approved; default "pending" |
| `fund_release_status` | Fund release status | enum | no | locked, approved, released |
| `due_date` | Due / estimated completion date | date | no |  |
| `completed_at` | Completed on | date | no |  |
| `mprv_required` | Needs a payment-release verification (MPRV) | bool | no | default false |
| `mid_milestone_required` | Needs a mid-milestone verification | bool | no | default false |
| `final_approval_required` | Needs a final approval | bool | no | default false |

## `milestone_suppliers`: Milestone supplier assignments

Depends on: `milestones`, `suppliers`

| Field | Label | Type | Required | Allowed values / default |
|---|---|---|---|---|
| `milestone_ref` | Milestone reference | string | yes |  |
| `supplier_ref` | Supplier reference | string | yes |  |
| `amount` | Amount | money | yes |  |

## `disbursements`: Disbursements (record only)

Depends on: `milestones`

| Field | Label | Type | Required | Allowed values / default |
|---|---|---|---|---|
| `milestone_ref` | Milestone reference | string | yes |  |
| `amount` | Amount | money | yes |  |
| `disbursed_at` | Paid on | date | yes |  |
| `payment_reference` | Payment reference | string | no |  |
| `payment_method` | Payment method | enum | no | mpesa_paybill, mpesa_mobile, mpesa_till, bank_transfer, pochi, other; default "other" |
| `supplier_ref` | Supplier reference | string | no |  |
| `recipient_type` | Recipient type | enum | no | supplier, business_owner; default "supplier" |

## `checkpoints`: M&E checkpoints

Depends on: `winners`

| Field | Label | Type | Required | Allowed values / default |
|---|---|---|---|---|
| `application_ref` | Application reference | string | yes |  |
| `checkpoint_ref` | Checkpoint reference | string | yes |  |
| `checkpoint_name` | Name | string | yes |  |
| `type` | Type | enum | yes | monitoring, reporting, meeting, site_visit, milestone_review, financial_audit, kpi_assessment, impact_evaluation, disbursement_approval, closure_review |
| `due_date` | Due date | date | no |  |
| `requirement` | Requirement | text | no |  |
| `kpis_to_track` | KPIs to track | list | no |  |
| `evidence_required` | Evidence required | list | no |  |
| `require_site_visit` | Requires site visit | bool | no | default false |

## `submissions`: Checkpoint submissions (historic)

Depends on: `checkpoints`

| Field | Label | Type | Required | Allowed values / default |
|---|---|---|---|---|
| `checkpoint_ref` | Checkpoint reference | string | yes |  |
| `written_report` | Written report | text | no |  |
| `kpi_actual_values` | KPI actual values (kpi:value, ...) | list | no |  |
| `submitted_at` | Submitted on | date | no |  |

## `site_visits`: Site visits already done (record only)

Depends on: `checkpoints`

| Field | Label | Type | Required | Allowed values / default |
|---|---|---|---|---|
| `checkpoint_ref` | Checkpoint reference | string | yes |  |
| `reviewer_email` | Visitor email (existing account, optional) | email | no |  |
| `inspector` | Inspector name | string | no |  |
| `start_date` | Visit date | date | yes |  |
| `location` | Location | string | no |  |
| `objective` | Objective | text | no |  |
| `observed_actions` | Observed actions | text | no |  |
| `evidence_found` | Evidence found | text | no |  |
| `risk_notes` | Risk notes | text | no |  |
| `recommendation_notes` | Recommendation | text | no |  |
| `visit_comments` | Comments | text | no |  |

