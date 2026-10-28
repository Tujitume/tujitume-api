## Program Wallets

All routes are under `/api/v1/programs` and require Sanctum authentication. Wallets are managed by the program owner.

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/{program}/wallets` | Get the program wallet and program |
| POST | `/wallets/{wallet}/deposit` | Initiate an M-Pesa deposit to the program wallet |
| POST | `/wallets/{wallet}/deposit-status` | Check a deposit reference |
| POST | `/wallets/{wallet}/transfer` | Move funds from the owner's main wallet into the program wallet |

Deposit requires `amount` (numeric, minimum 10), `acc_number`, and `purpose`. Deposit status requires `reference_id`; a not-yet-found payment returns `status: pending`. These endpoints use the LIPR deposit flow.

Main-wallet transfer body is `{"amount":1000,"purpose":"Program reserve"}`. `amount` is numeric and at least 10; optional `purpose` is text up to 255 characters. Success returns `message: Transfer successful.` and `new_balance`. Transfer failure does not mean the program wallet is funded.

Payment previews use `GET /api/v1/lipr/preview`; the current path and partner response behavior are documented in [Payments](../payments/README.md). Funding releases use [Milestone Disbursements](milestones/disbursements.md).
