## Pre-agreements

All endpoints are under `/api/v1/programs` and require Sanctum authentication. The applicant and owning program organization may read and comment. Only the owner can approve or reject. `{type}` is `mprv`, `mid_milestone`, or `final_approval`.

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/milestones/{milestone}/agreements` | List agreements, comments and read receipts |
| POST | `/milestones/{milestone}/agreements/{type}/comment` | Add an applicant/owner message or applicant response |
| POST | `/milestones/{milestone}/agreements/{type}/approve` | Owner accepts a submitted agreement |
| POST | `/milestones/{milestone}/agreements/{type}/reject` | Owner returns a submitted agreement |
| POST | `/milestones/{milestone}/agreements/{type}/read` | Save this participant's last-read comment |

### Read agreements

`GET` returns `{ "agreements": [...], "all_agreed": boolean }`. Each agreement includes comments with users, the applicant and `reads`. The agreement `status` is decorated as `{ "value": "submitted", "color": "..." }`; read receipts contain `user_id`, `last_read_comment_id`, `read_at` and timestamps. `all_agreed` is true only when at least one agreement exists and every returned agreement is agreed.

### Post a comment or response

```json
{"comment":"Please see my response."}
```

`comment` is required text, maximum 1000 characters. The value is stored as a comment; the backend does not validate its JSON shape. The funding setup frontend uses these JSON comment conventions:

```json
{"tj":"agree","v":1,"r":2,"q":[{"f":"field-id","k":"later","n":"Reason"}],"m":"Message to the owner"}
```

Here `q[].k` may be `later`, `remove`, `alternative`, or `other`; `f` identifies a configured field and `n` carries the reason. Owner review metadata is stored in the milestone's `*_notes` configuration. A quoted field chat message uses `{"tj":"msg","v":1,"f":"field-id","t":"Message text"}`. Plain text comments are also accepted. The server censors detected contact details and abusive words in comments, agreement messages and reasons.

An applicant comment creates/submits the agreement or reopens a rejected one for owner review. An already-agreed agreement cannot be reopened by an applicant except during `awaiting_applicant_revision`. Owner comments do not approve an agreement.

### Owner approval and rejection

Approval body is optional; when supplied, `comment` is a string up to 1000 characters. It requires an existing agreement in `submitted` state. Success sets status to `agreed`, records `reviewed_by` and `reviewed_at`, and returns the agreement with comments.

Rejection requires `{"rejection_reason":"Please correct this item."}`. The reason is required text up to 1000 characters. It changes status to `rejected`, increments `rejection_count`, and returns the count and agreement. Repeated rejection does not create a `final_rejected` state in the current implementation; an applicant can respond and resubmit.

Both decision endpoints return `404` if no agreement exists, `422` if it is not currently submitted, `403` for a non-owner, and `422` for validation errors.

### Read receipts

Send the latest comment ID displayed by the client:

```http
POST /api/v1/programs/milestones/31/agreements/mprv/read
Content-Type: application/json

{"last_read_comment_id":84}
```

Only the application participant and program owner can write a receipt. The ID must belong to that agreement. The endpoint returns `204 No Content`; a receipt can only move forward. A `404` means there is no agreement for the milestone/type; `422` means the comment does not belong to it or validation failed. This endpoint requires the `milestone_agreement_reads` migration.
