## Payments API Route Changes

Protected endpoints are under `/api/v1`, require Sanctum authentication, and use the existing payment/payment-method flows. Payment-provider callbacks are public and are not frontend endpoints.

### Current LIPR client routes

| Method | Endpoint | Purpose |
|---|---|---|
| POST | `/lipr/initiate-payment` | Initiate a supported LIPR payment; `purpose=reviewer_payment` is used for reviewer orders |
| GET | `/lipr/preview` | Proxy an authenticated payment preview to the LIPR partner API |
| POST | `/lipr/disbursement-status` | Poll program disbursement status |
| POST | `/lipr-disbursement-status-bulk` | Poll bulk program disbursements |
| POST | `/lipr-capital-disbursement-status` | Poll capital disbursement status |
| POST | `/connect/{id}` | Start Stripe Connect onboarding (changed from GET to POST) |

Status route names `/lipr-status-program`, `/lipr-status-program-bulk`, and `/lipr-status-capital` are no longer registered. Use the current paths above.

### LIPR preview

The preview endpoint accepts a type as query `type` or `endpoint_name`, or in a JSON body. The optional `payload` may be a JSON object; if omitted, the remaining body/query fields are forwarded. The backend passes the partner response body and HTTP status through unchanged.

Supported `type` values: `bank`, `mobile_money_send`, `paybill`, `till`, `pochi`, `stk`, `lipr`, `wallet_transfer`. Common partner path aliases such as `payments/mobile-money/paybill/preview` and `wallets/transfer/preview` are normalized. The payload is partner-specific and is not validated locally.

```http
GET /api/v1/lipr/preview?type=stk&payload=%7B...%7D
Authorization: Bearer <token>
```

Alternatively send `{"type":"stk","payload":{...}}` as JSON. Unsupported/missing type or invalid payload returns `422` with `message` and `errors`. LIPR connection/authentication errors return `502`; other unexpected failures return `500`.

### Reviewer order payment

Use `POST /api/v1/lipr/initiate-payment` with the existing payment payload fields `amount`, `acc_number`, `purpose`, `purpose_text`, and `listing_id`; `purpose` is `reviewer_payment` and `listing_id` is a reviewer order ID. `amount` is still required by the shared request validator, but the backend resolves the payable amount from the order's `fee_amount`. The owner must own the order's program, the reviewer must have a LIPR wallet, the order must be delivered/approved and not already completed. The response wraps the provider result in `liprResponse`.

The public callbacks now use `POST /api/lipr-callback/reviewer-payment` and `POST /api/lipr-callback/reviewer-payment-leg2` (no `/v1` prefix). They are called by LIPR; the frontend should poll `GET /api/v1/programs/reviewer-orders/{order}/payment-status` for the resulting status.

### Program wallet transfer

`POST /api/v1/programs/wallets/{wallet}/transfer` transfers from the authenticated owner's main wallet into the program wallet. Body: `{"amount":1000,"purpose":"Program reserve"}`. `amount` is required, numeric and at least 10; `purpose` is optional text up to 255. Success returns `message` and `new_balance`. This is a direct balance transfer and is separate from an M-Pesa checkout.
