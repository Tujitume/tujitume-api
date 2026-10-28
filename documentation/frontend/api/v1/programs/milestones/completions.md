## Milestone Completions

All routes are under `/api/v1/programs` and require Sanctum authentication. The applicant submits completion after the milestone's funds have been fully released; only the program owner can approve or reject it.

| Method | Endpoint | Purpose |
|---|---|---|
| POST | `/milestones/{milestone}/completions` | Submit completion report and proof |
| GET | `/milestones/{milestone}/completions` | List completion submissions |
| PATCH | `/completions/{completion}/approve` | Approve a pending submission |
| PATCH | `/completions/{completion}/reject` | Reject a pending submission |

Submission uses multipart form data when uploading proofs. `completion_report` is required text. `delivery_notes` is optional text; `proof_files[]` is optional, with each file limited to 5 MB. Final-approval fields configured in the milestone are also validated from the v1 JSON in `delivery_notes`; required file questions must have a corresponding uploaded proof. See [Verifications](verifications.md) for the answer JSON format.

Success is `201` with the completion submission in `data`. The submission starts with decision `pending`; the milestone enters `completed` while awaiting owner review. The owner can approve with optional `decision_notes` or reject with required `decision_notes`. A decision can be made only once; a repeat returns `422`. Rejection changes the milestone to `completion_rejected`, allowing a corrected submission. Approval changes it to `completion_approved` and can unlock the next milestone. Validation failures return `422` with `message` and `errors`; unauthorized actors receive `403`.
