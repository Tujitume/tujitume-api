## Round Finalization

All routes are under `/api/v1/programs` and require the owning program organization.

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/rounds/{round}/finalize-preview` | Preview blockers, advancement result and reviewer payment readiness |
| POST | `/rounds/{round}/finalize` | Finalize and process advancement |
| POST | `/applications/{application}/advance` | Manually advance an application in a manual round |
| POST | `/applications/{application}/reject` | Manually reject an application in a manual round |
| POST | `/rounds/{round}/reviewer-payments` | Start ready reviewer payments after round finalization |

### Preview before finalization

`GET /rounds/{round}/finalize-preview` is read-only. It returns `data` with `mode`, `rubric_mode`, `rule`, `is_final_round`, `destination`, `blockers`, `can_finalize`, `advanced`, `not_selected`, `undecided`, and `tie`. Application rows include `id`, `name`, and score. Manual rounds are blocked while applications still need a decision; score-threshold/fixed-quota rounds are blocked by unresolved ties. A non-final round also needs a destination round. Finalization is blocked until the review period has ended.

`data.reviewer_pay` contains `currency`, `reviewers`, `pay_now`, `wallet_balance`, and `shortfall`. Reviewer rows include `order_id`, `reviewer_id`, `work_status`, `payment_status`, `name`, `fee_type`, `rate`, `assigned`, `scored`, `minimum_apps_to_get_paid`, `amount`, `state`, and `has_wallet`. Payment state values are `paid`, `no_fee`, `ready`, `awaiting_approval`, `below_cap`, or `waiting`. The preview is the source for the finalization UI; a zero `shortfall` does not mean reviewer work has been approved or payments settled.

### Finalize and reviewer payment

`POST /rounds/{round}/finalize` applies the previewed advancement rule. It is owner-only and returns `422` when blockers remain. Manual advance/reject are allowed only for manual rounds and published state.

Reviewer payment start is owner-only and requires the round already be `finalized`. It returns `{ "message":"...", "started":number, "result":{...} }`. Reviewers must meet the configured paid-application minimum and have their work approved before payment is started. LIPR initiation and callbacks are described in [Payments](../../payments/README.md); poll each reviewer order's payment-status endpoint to confirm settlement.
