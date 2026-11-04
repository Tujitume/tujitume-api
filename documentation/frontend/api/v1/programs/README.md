# Programs API v1

**Base URL:** `/api/v1/programs`

Unless a route explicitly permits public access, authenticate with Sanctum. Program API writes may return endpoint-specific envelopes; refer to the endpoint page for exact response examples.

## Endpoint index

| Area | Reference |
|---|---|
| Program CRUD and removed-field migration | [Programs](programs.md), [Migration notes](migration-notes.md) |
| Custom field definition and info values | [Custom fields](custom-fields.md) |
| Application submission and retrieval | [Applications](applications.md) |
| Scoring configuration and preview | [Match-score engine](match-score-engine.md) |
| End-to-end create/apply/score example | [How program application match score works](how-program-application-matchScore-works.md) |
| Rounds and round-specific questions | [Round documentation](rounds/rounds.md), [Questions and answers](rounds/questions-answers.md) |
| Wallet and payments | [Wallet](wallet.md), [Payments](../payments/README.md) |
| Monitoring and analytics | [Monitoring](monitoring/analytics.md) |

## User types

| ID | Role | Program permissions |
|---:|---|---|
| 1 | business_owner | Apply, submit evidence, edit permitted plans |
| 2 | investor | No program-owner operations |
| 3 | service_provider | No program-owner operations |
| 4 | organization | Own and operate programs |
| 5 | admin | Administrative access where configured |
| 6 | external_reviewer | Score assigned rounds and site visits |

## Status colors

| Value | Color | Meaning |
|---|---|---|
| draft, pending, submitted | info | Awaiting the next action |
| under_review, changes_requested, modification_requested, processing | warning | Being reviewed or changed |
| approved, awarded, completed, verified, agreed | success | Successfully accepted |
| rejected, not_selected, failed, cancelled, final_rejected | danger | Denied or stopped |

Status metadata may be represented as `{"value":"pending","color":"info"}`.

Messages, contact cards, meetings, notification reads and payment-provider changes are documented under [Communication](../communication/README.md), [Accounts](../accounts/README.md), and [Payments](../payments/README.md). Organization provider management is in [Organizations](../organizations/README.md).
