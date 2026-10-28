## Round Reviewers and Assignments

All routes are under `/api/v1/programs` and require Sanctum authentication. Program owners manage invitations and application assignments. A reviewer must accept an invitation before manual assignments are made.

| Method | Endpoint | Purpose |
|---|---|---|
| GET/POST | `/rounds/{round}/reviewers` | List/add reviewers |
| PATCH/DELETE | `/reviewers/{reviewer}` | Update/remove reviewer assignment |
| GET | `/reviewer/assigned-rounds` | List rounds assigned to the caller |
| GET | `/reviewer/assigned-requests` | List caller's assignment requests |
| GET | `/reviewer/assignment-counts` | Sidebar counts: pending requests and applications to review |
| POST | `/rounds/{round}/reviewers/accept` | Accept the caller's reviewer invitation |
| POST | `/rounds/{round}/reviewers/decline` | Decline the caller's invitation |
| POST | `/rounds/{round}/reviewers/{reviewer}/assign-applications` | Owner manually assigns applications |
| DELETE | `/rounds/{round}/applications/{application}/assignment` | Owner unassigns an application |
| POST | `/rounds/{round}/applications/{application}/start-review` | Reviewer starts work on an assigned application |
| GET | `/rounds/{round}/my-applications` | Reviewer's assigned applications for the round |

### Create an assignment

`POST /rounds/{round}/reviewers` requires `reviewer_type` (`internal`, `external`, or `third_party`), `order_type: "round_review"`, and `user_id` (existing user). Site visit orders are created only by assigning a reviewer to a monitoring checkpoint that requires a site visit; they do not use round assignment. Optional fields: `max_apps_assigned` (integer >= 1), `assigned_percentage` (1–100), `expertise_tags` (array), `reviewer_fee` (numeric >= 0), `fee_type` (`flat` or `per_application`), `fee_currency` (string), `reviewer_payment_basis` (`per_application` or `flat_round_fee`), `minimum_apps_to_get_paid` (integer >= 1), and legacy `reviewer_payment_max_applications` (integer >= 1; fallback alias for the same threshold).

`reviewer_payment_basis` takes precedence over `fee_type`. For a paid flat fee, `minimum_apps_to_get_paid` is required. For per-application pay, it defaults to 1 when payment is enabled. The threshold cannot exceed `max_apps_assigned`. Internal reviewers are unpaid. A `third_party` assignment must be a service provider already added to the organization's third-party provider list and must have a fee greater than zero. A round configured `owner_only` rejects reviewer assignment. Success is `201`.

If the assigned `user_id` is the authenticated program owner, the assignment is auto-accepted: both the round reviewer and reviewer order have `acceptance_status: accepted`, their accepted timestamp is set, and the stored reviewer fee/order `fee_amount` are zero. No invitation notification is sent for this self-assignment.

### Acceptance and manual assignment

Acceptance takes no body and returns `{ "message":"Assignment accepted.", "queue_position":1 }`. The caller must have a pending assignment; repeated acceptance or a declined assignment returns `422`. Decline takes no body and returns a confirmation message. Acceptance creates application assignments according to the round's assignment method.

Manual assignment body:

```json
{"application_ids":[101,102]}
```

`application_ids` is required, non-empty, and each ID must exist and belong to this round. This endpoint is available only when `assignment_method` is `manual`, the round is published, and the reviewer accepted the assignment. Otherwise it returns `422`.

`GET /reviewer/assignment-counts` returns `{ "pending_requests":0,"to_review":2,"total":2 }`. `to_review` counts assignment statuses `assigned` and `in_review`.

Round reviewer payment/order fields and payment status are documented in [Reviewer Payment](../../reviewers/README.md). Round finalization preview and payment start are documented in [Finalization](finalization.md).

Checkpoint site-visit assignment, reviewer acceptance/report submission, separate owner review actions, and payment are documented in [Site Visits](../monitoring/site-visits.md).
