## Disbursements and Fund Release

All routes are under `/api/v1/programs` and require Sanctum authentication. Program owners initiate and manage releases. Applicants may read disbursement data for their application.

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/milestones/{milestone}/disbursement-data` | Load milestone, application, wallet, suppliers, budget, verifications and disbursements |
| GET/POST | `/milestones/{milestone}/disbursements` | List/create disbursement records |
| GET/PUT/PATCH/DELETE | `/disbursements/{disbursement}` | Resource routes (controller CRUD handlers are not implemented) |
| PATCH | `/disbursements/{disbursement}/mark-completed` | Owner records completed settlement; optional receipt/reference |
| PATCH | `/disbursements/{disbursement}/mark-failed` | Owner records failure |
| PATCH | `/disbursements/{disbursement}/reverse` | Owner reverses a disbursement |
| POST | `/milestones/{milestone}/release-funds` | Initiate payment for an approved milestone |

`GET .../disbursement-data` returns `milestone`, `pitch` (application), `wallet`, and `budget_summary` (`milestone_amount`, `total_allocated`, `remaining_amount`, `item_count`).

### Initiate release

```http
POST /api/v1/programs/milestones/31/release-funds
Content-Type: application/json

{"type":"pay_from_escrow"}
```

`type` is required: `pay_from_escrow` or `pay_from_wallet`. The caller must own the program. The milestone must have `fund_release_status: approved`, be releasable in sequence, and have an assigned supplier. Escrow release additionally requires the application escrow flag and a completed escrow payment record. Failure of a precondition returns `422`; non-owner returns `403`.

The transfer uses the assigned supplier's `payment_route`. `direct_to_supplier` pays the supplier; `direct_to_applicant` sends to the applicant. Although assignment accepts `split`, the current release controller has no split branch and returns `422` for it. Do not present `split` as a working release option until the backend confirms its behavior.

Success means the transfer was initiated, not settled:

```json
{"message":"Funds release initiated successfully.","reference":"TX-123"}
```

The milestone moves to `disbursing` / `processing`. Settlement callbacks update the record separately. The public callback URLs are outside `/v1`: `POST /api/lipr-callback/disbursement-direct`, `POST /api/lipr-callback/transfer-to-supplier`, and `POST /api/lipr-callback/disbursement-escrow`. These are payment-provider callbacks, not frontend calls. Check the `disbursements` and milestone data to display settlement state; a successful activation or initiation response is not confirmation that payment completed.

To poll a release, call `POST /api/v1/lipr/disbursement-status` with `{"milestone_id":31,"referenceId":"TX-123"}`. It returns `pending` while the callback or release is incomplete, `disbursing` while the escrow-funded transfer is processing, `completed` after the milestone is released, or `failed` when the payment failed. This endpoint is for milestone disbursements; do not use the wallet withdrawal status endpoint for this reference.

### Mark completed

Only the program owner may mark a pending or processing disbursement completed. Optional `receipt_file` is PDF/JPG/JPEG/PNG up to 10 MB; optional `referenceId` is a string up to 100 characters. When `referenceId` is provided, it must identify a payment that is ready for crediting. Once every disbursement for the milestone is complete, `fund_release_status` becomes `released`. Validation errors return `422` with `message` and `errors`.
