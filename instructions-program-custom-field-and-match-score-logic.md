\# TASK: Program Custom Fields \& Configurable Match-Score Engine



Laravel 10+, PHP 8.2+, MySQL 8, Sanctum, S3, Pest. The old "grant" module is now "program". The word "grant" must not appear in any new code, route, doc or test.



\## 0. READ FIRST (mandatory, before changing anything)



Do not guess. Where this prompt conflicts with the real code, follow the real code and list the conflict in your final report.

\- Program migrations, model, factory, resources; ProgramController (store, update, index, public\_programs, get\_program, duplicate, visibility, destroy) and every validation rule in them.

\- MatchController::score and everything that computes or consumes match\_score or score\_breakdown (store\_application, application resources, analytics, notifications, exports).

\- Every read of these legacy programs columns across app/, resources/views (emails), routes, jobs, console commands/crons, tests, factories, documentation/, Postman collections: eligibility\_criteria, required\_documents, program\_focus, regions, startup\_stage\_focus, impact\_objectives, social\_impact\_areas, bonus\_points, evaluation\_criteria, funder\_name, funder\_type, program\_brief\_pdf, start\_date, end\_date, mid\_milestone\_required, program\_industry\_id. Classify each usage as behavior-driving (logic, cron, status transitions, analytics, email variables) or purely descriptive.

\- Round custom questions and round answers (separate feature; do NOT merge it with this one), ApplicationRound/RoundQuestion controllers, program\_applications schema and controller, file-upload service and HasS3Files-style URL logic, ApiResponseResource, config/status.php, ProgramTestCase, factories, existing docs.

\- If an import suite (imports/\*, ImportTypeRegistry) already exists, find where it touches program fields and list required follow-ups; do not rewrite it in this task.



*** NON-NEGOTIABLE TASKS:

\## 1. GOAL



A program owner (PO) fills in only the required core fields. Everything else is a PO-defined custom field, stored as rows (never as new columns). Any application field can be flagged as a match-score factor with PO-defined targets. The match score is computed on the server from those definitions.



Principles:

1\. No runtime DDL. Field keys are never used as column names or in raw SQL.

2\. The server is the single source of truth for match scores. Client-sent match\_score and score\_breakdown are ignored.

3\. Applicants never see scoring targets or weights.

4\. Historic scores are immutable snapshots.

5\. Existing program data is migrated, not lost.



\## 2. programs TABLE AFTER THIS CHANGE



Keep (system / required core): id, user\_id, organization\_id, program\_industry\_id, program\_title, total\_program\_amount, available\_amount, funding\_per\_business, disbursement\_type, program\_type, total\_rounds, max\_awardees, currency, application\_start\_date (NEW, date), application\_deadline, require\_kyc\_verification\_to\_apply (NEW, boolean default false), match\_score\_limit (NEW, nullable unsigned tinyint 1-100), status, visible, timestamps. Plus any column you classified as behavior-driving (expected: mid\_milestone\_required). List every extra column you keep in the report with the code that depends on it, for human sign-off.



Remove after data migration (section 8): every other legacy column listed in section 0, unless classified behavior-driving. Fix the program\_industry\_id definition if invalid (proper FK, nullOnDelete).



store()/update(): validate only the core fields above plus optional `custom\_fields` (definitions, create only; see 6.3). Remove validation, assignments and fillable entries for removed columns. Fix the syntax errors in the current validation (missing quotes and comma on require\_kyc\_verification\_to\_apply). Rules: application\_deadline after application\_start\_date, application\_start\_date today or later on create, total\_rounds required when program\_type=multi\_round (1 when single\_round), funding\_per\_business <= total\_program\_amount.



\## 3. DATA MODEL (additive migrations first; correct order)



`program\_custom\_fields`

id, program\_id (FK cascade), key (string 64, slug from label, unique per program, IMMUTABLE), label (150), help\_text (text null), audience enum(application, info), type enum(text, textarea, number, boolean, date, select, multi\_select, file, url), is\_required bool, options json null (list of {value,label}, select and multi\_select only), validation json null (e.g. {min,max,max\_length,mimes,max\_kb}), is\_scoring bool default false, scoring json null (see section 4), is\_bonus bool default false, display\_order int, visibility enum(public, owner\_only) default public (info fields), is\_archived bool default false, created\_by (FK users), timestamps. Unique (program\_id, key). Index (program\_id, audience, is\_archived, display\_order).



`program\_custom\_field\_values`

id, field\_id (FK cascade), entity\_type enum(program, application), entity\_id, value\_text (text null), value\_number (decimal 20,4 null), value\_date (date null), value\_bool (bool null), value\_json (json null), timestamps. Unique (field\_id, entity\_type, entity\_id). Index (entity\_type, entity\_id). Files store the S3 path in value\_text. Multi-select stores a JSON list in value\_json. Create a service layer (CustomFieldValueService) that reads/writes the correct typed column; nothing else touches these columns directly. Add Eloquent relations (Program->customFields, Program->infoValues, ProgramApplication->customAnswers).



Limits (config/program\_fields.php): max 50 active fields per program, max 100 options per field, label 150 chars, text answers 5000 chars, file max 20 MB (default mimes pdf, doc, docx, png, jpg).



\## 4. SCORING CONFIG (scoring json, validated server-side)



Only application-audience fields can be scoring fields. text/textarea/url can use only `provided`.



```json

{ "enabled": true, "weight": 20, "match\_type": "in\_set|range|equals|provided",

&#x20; "target": "...", "mode": "any|proportional|all" }

```



| match\_type | allowed types | target | credit |

|---|---|---|---|

| in\_set | select, multi\_select | list of option values (non-empty, subset of options) | select: 1 if answer in target else 0. multi\_select with mode any: 1 if overlap>=1; proportional: |answer∩target|/|target|; all: 1 only if target ⊆ answer |

| range | number, date | {min?, max?} at least one required, min<=max | 1 if inside the range (inclusive), else 0 |

| equals | boolean | true or false | 1 if equal |

| provided | any | none | 1 if a non-empty answer or file exists |



weight: number greater than 0, max 1000 (relative weight; the engine normalizes). `is\_bonus=true`: the field's weight is added AFTER normalization as bonus points (weight = points), only if base score >= program.match\_score\_limit (if null, always), final score capped at 100.



\## 5. MATCH ENGINE (app\\Service\\AiScore\\MatchScore.php@program(), pure and unit-testable)



compute(Program $program, array $answers): returns {score: int|null, breakdown\[], bonus\[], eligible\_for\_bonus}

\- Uses only active (non-archived), is\_scoring application fields.

\- base = Σ(weight\_i × match\_i) / Σ(weight\_i) × 100 over non-bonus scoring fields. Unanswered = match 0 but kept in the denominator. If there are no non-bonus scoring fields, score is null (no match score for this program).

\- Round half-up to an integer 0 to 100, apply bonus, clamp to 100.

\- breakdown item: {field\_id, key, label, match\_type, weight, normalized\_weight, match (0 to 1), earned\_points, answer, target}. Store the whole breakdown as JSON on program\_applications.score\_breakdown, and the integer in match\_score.

\- No eval, no dynamic code. Table-driven strategies per match\_type (one class each).


\## 6. API (all under /api/v1/programs, program middleware; PO endpoints require org owner/admin/editor of the program's organization, reuse the existing role check. Mutations return ApiResponseResource. Watch route collisions: register `fields/{field}` style static routes before generic `/{program}/...` patterns and verify with route:list)



6.1 Field management (PO)

\- GET `{program}/fields` returns all fields incl. scoring config, archived flag, usage counts, and a `scoring\_summary` (normalized weights, whether any scoring field exists).

\- POST `{program}/fields` creates a field. Key is generated from the label (slug, de-duplicated) and immutable.

\- PATCH `fields/{field}`: always allowed: label, help\_text, display\_order, visibility. Allowed only while the program has no submitted applications: type, options, validation, is\_required, is\_scoring, scoring, is\_bonus. After applications exist: only appending options, relaxing is\_required, and archiving. Otherwise 422 with a clear reason.

\- DELETE `fields/{field}`: hard delete if it has no values, else archive (is\_archived=true). Archived fields disappear from the applicant form and engine but stay visible on historic applications.

\- POST `{program}/fields/reorder` with {ordered\_ids:\[...]}.

\- PUT `{program}/info-values` with {values:{key:value}} sets values for info fields (files via multipart).



6.2 Applicant-facing

\- GET `get\_program/{id}` and `public-programs` include `application\_fields` (public resource: label, key, type, options, is\_required, help\_text, validation; NO is\_scoring, scoring, weight, targets) and `info` (public info values with signed URLs for files).

\- POST `match-score/{program\_id}`: body {custom\_answers:{...}}; read-only preview returning {match\_score, breakdown\_for\_applicant}. The applicant breakdown shows per-field match status only (matched, partial, not matched), never weights or targets. Throttle it.
- match score should now compare applicant provided data against program data (custom fields \& scoring factor fields included)



6.3 Program create

store() accepts optional `custom\_fields` (array of definitions, same rules as 6.1) created in the same transaction through the same service. update() does not accept custom\_fields (use 6.1).

duplicate(): also clones definitions (not values or answers) and info values.



6.4 Applications

store\_application: build validation dynamically from the program's active application fields (required, type, options membership, min/max/max\_length, file mimes/size). Payload: `custom\_answers` keyed by field key (scalar, array for multi\_select, file for file type; multipart `custom\_answers\[key]`). Inside one transaction: validate, compute the score with the engine, save answers via CustomFieldValueService, save match\_score and score\_breakdown on the application. Silently ignore any client-sent match\_score/score\_breakdown (remove their validation rules). Application show/list resources return custom\_answers \[{field\_id, key, label, type, value, value\_url}] and the full score\_breakdown to the PO only; the applicant sees match\_score and the applicant-safe breakdown.

Remove every remaining use of legacy program fields in MatchController, store\_application and analytics (the old sector/geo/stage/impact/revenue/documents scoring is replaced by the engine).



\## 7. REMOVING LEGACY USAGE



Prefer deleting dead code. Where removal depends on an open decision, leave a one-line `// TODO(custom-fields): ...` and list it in the report. Update the model $fillable/$casts, ProgramFactory, seeders, API resources, validation, email templates and blade variables that referenced removed columns, and any Pest tests and docs that referenced them. Nothing may reference a removed column when you finish (grep to prove it).



\## 8.1. LEGACY DATA MIGRATION (expand and contract)



Release 1 (migrations): create the new tables and columns (sections 2 and 3). Add artisan command `programs:migrate-legacy-fields {--dry-run}` (idempotent, chunked, transactional per program). Mapping, per program, only when the legacy value is non-empty:



| Legacy | New field (audience, type) | Scoring (legacy-equivalent) |

|---|---|---|

| program\_focus | Business sector (application, multi\_select; options and target = the list) | in\_set any, weight 30 |

| regions | Region (application, select; options and target = the list) | in\_set, weight 15 |

| startup\_stage\_focus | Business stage (application, select) | in\_set, weight 10 |

| social\_impact\_areas | Social impact areas (application, multi\_select) | in\_set any, weight 10 |

| funding\_per\_business | Revenue last 12 months (application, number) | range {min: funding\_per\_business}, weight 10 |

| required\_documents (list) | one file field per entry | provided, weight 5 split equally |

| bonus\_points | inspect its real structure; map to is\_bonus fields if it fits, else an info field + report | |

| eligibility\_criteria, impact\_objectives, evaluation\_criteria, funder\_name, funder\_type, program\_brief\_pdf, start\_date, end\_date | info fields (textarea/text/select/file/date) with the PO value | none |



Do not backfill answers on already-submitted applications; their stored match\_score stays as-is. Existing applications are unaffected.

Release 2 (contract, a separate migration): before dropping, export the dropped columns per program to storage/app/backups/programs\_legacy\_{timestamp}.json, then drop. down() re-adds the columns as nullable. Do NOT drop columns until `programs:migrate-legacy-fields` has run on the current database in the test run; add a guard that aborts the contract migration if unconverted programs exist.

8.2. Legacy Fields:

bottom-line: *** NON-NEGOTIABLE
- remove all legacy fields from 'programs' table, update the programs table accordingly
- remove all legacy field usage from entire codebase as they don't exist, and remove any any logic involving them, all logics or calculation involving them in entire codebase should work without them.
-update all Models, Controller methods, MatchScore@program method(), store_application, etc accrodingly



\## 9. SECURITY AND INTEGRITY



Policy checks on every endpoint (PO of the owning organization only; cross-org access returns 403/404). Strip HTML from text answers. Validate file extension, MIME and size. Option values are validated against stored options, never trusted. Never expose scoring config or weights through any applicant or public resource (test the serialized JSON). Throttle the preview endpoint. No PII in ErrorLogService input.



\## 10. TESTS (Pest)



Unit: MatchScoreEngine table-driven per match\_type and mode (full, partial, zero), normalization with weights not summing to 100, unanswered optional field, bonus gated by match\_score\_limit, cap at 100, no scoring fields (null score), rounding half-up, archived field ignored.

Feature: program store/update with only core fields (and each core validation rule); store with inline custom\_fields; fields CRUD, key immutability, lock rules before and after applications, archive vs delete, reorder, limits (51st field rejected), authorization and cross-org; info-values with files (Storage::fake('s3')); applicant resources never leak scoring; store\_application with required, typed and file custom answers, validation errors per type, server ignores forged match\_score, score and breakdown snapshot saved, historic score unchanged after the PO edits scoring; match-score preview; duplicate clones definitions; legacy migration command (dry-run, idempotent, mapping table, bonus\_points edge) and the contract guard. Update every existing test and factory that used removed fields; php artisan test must pass.



\## 11. DOCUMENTATION



Update documentation/frontend/api/v1/Programs/ (programs.md, applications.md) and add custom-fields.md and match-score-engine.md in the same Stripe-style depth (request tables with required/type/notes, full JSON examples, error tables, business rules). Include: the three examples below as full create-field payloads and responses, the match-type matrix, the worked scoring example, the lock rules, the applicant-vs-PO resource differences, and a migration notes page for existing frontend (removed program fields, new application payload, ignored client match score). Also create a how-program-application-matchScore-works.md and write full example of the flow of 1.How to create a program with example paylod, 2.How to apply with example payload, 3.how match score working for that application



Examples to document:

\- "Interested places": multi\_select, options \[Niagara, NY, London], scoring in\_set, mode proportional, target \[Niagara, London], weight 20.

\- "Business age (years)": number, scoring range {min:2, max:10}, weight 30.

\- "Business plan PDF": file, required, validation {mimes:\[pdf], max\_kb:5120}, scoring provided, weight 10.



\## 12. DELIVERY ORDER AND ACCEPTANCE



1\. Migrations, models, CustomFieldValueService, field CRUD. 2. MatchScore with unit tests. 3. store\_application and the match-score preview. 4. Program store/update/duplicate/resources and removal of legacy usage. 5. Legacy data migration command. 6. Contract migration. 7. Docs.

Final report must list: files changed, columns kept and why, every TODO(custom-fields), deviations from this prompt, places where an import suite or the frontend needs follow-up, and test results.

Acceptance: php artisan test passes; no references to removed columns anywhere; a program can be created with only core fields; a PO can define custom and scoring fields for the three examples and a submitted application's score and breakdown match hand calculation; forged client scores are ignored; applicant/public JSON never contains scoring config.

