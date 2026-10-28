# Reviewer Payment API

The Reviewer Payment API manages the complete lifecycle of reviewer payments, from assignment through work delivery to payment settlement. Base path: `/api/v1/programs`.

## Overview

External reviewers are assigned to program rounds or site visits and are compensated for their work. The payment flow follows these stages:

1. **Assignment**: Reviewer is assigned with a fee amount
2. **Work Delivery**: Reviewer completes their work and marks it delivered
3. **Approval**: Program owner reviews and approves the work
4. **Payment Initiation**: Payment is initiated via M-Pesa STK push
5. **Payment Confirmation**: W2W transfer to reviewer's LIPR wallet confirms receipt

## Authentication

Every endpoint requires Laravel Sanctum authentication.

```http
Authorization: Bearer <token>
Accept: application/json
```

## Key Concepts

### Reviewer Types
- **Internal**: Organization team member (user_type_id = 4, role_id = 10004)
- **External**: Independent contractor (user_type_id = 6)

### Work Types
- **Round Review**: Reviewer scores applications for a round
- **Site Visit**: Reviewer conducts monitoring visits and submits reports

### Work Status States
| Status | Description | Transitions |
| --- | --- | --- |
| `assigned` | Reviewer just assigned, work not started | → `in_progress` |
| `in_progress` | Reviewer is actively working | → `delivered` |
| `delivered` | Reviewer submitted work, awaiting PO review | → `modification_requested` or `approved` |
| `modification_requested` | PO requested changes | → `delivered` |
| `approved` | PO approved; payment can be initiated | → (no transitions) |
| `rejected` | PO rejected work | → (no transitions) |

### Payment Status States
| Status | Description |
| --- | --- |
| `unpaid` | No payment attempt made |
| `pending` | M-Pesa STK push initiated, awaiting customer confirmation |
| `leg1_processing` | STK push successful; W2W transfer to reviewer in progress |
| `completed` | W2W transfer confirmed; reviewer received funds |
| `failed` | Payment failed at either stage |

## Reviewer Order Model

A `ReviewerOrder` represents a single engagement between an organization and a reviewer.

```json
{
  "id": 42,
  "organization_id": 5,
  "reviewer_id": 127,
  "program_id": 3,
  "order_type": "round_review",
  "round_id": 8,
  "site_visit_id": null,
  "fee_usd": 150.00,
  "fee_kes": 19500,
  "currency": "USD",
  "work_status": "delivered",
  "delivery_note": "All 45 applications scored...",
  "modification_note": null,
  "rejection_reason": null,
  "deadline": "2026-09-15T23:59:59Z",
  "delivered_at": "2026-09-10T14:30:00Z",
  "approved_at": null,
  "payment_status": "unpaid",
  "leg1_reference": null,
  "leg2_reference": null,
  "paid_at": null,
  "created_at": "2026-09-01T10:00:00Z",
  "updated_at": "2026-09-10T14:31:00Z"
}
```

## Endpoints

| Method | Endpoint | Description | Auth |
| --- | --- | --- | --- |
| GET | `/reviewer/orders` | List reviewer's own orders | Reviewer |
| GET | `/rounds/{round}/reviewer-orders` | List all orders for a round | PO |
| POST | `/reviewer-orders/{order}/deliver` | Mark work as delivered | Reviewer |
| POST | `/reviewer-orders/{order}/request-modification` | Request changes | PO |
| POST | `/reviewer-orders/{order}/approve` | Approve work and prepare for payment | PO |
| GET | `/reviewer-orders/{order}/payment-status` | Check payment status | Reviewer/PO |

---

## GET `/reviewer/orders`

List all reviewer orders for the authenticated reviewer (must be user_type 6 or 7).

**Authentication:** Required (reviewer)

**Request:**
```http
GET /api/v1/programs/reviewer/orders
Authorization: Bearer <token>
Accept: application/json
```

**Response (200 OK):**
```json
{
  "data": [
    {
      "id": 42,
      "program_id": 3,
      "round_id": 8,
      "site_visit_id": null,
      "fee_usd": 150.00,
      "order_type": "round_review",
      "work_status": {
        "value": "delivered",
        "color": "warning"
      },
      "payment_status": {
        "value": "unpaid",
        "color": "info"
      },
      "delivered_at": "2026-09-10T14:30:00Z",
      "...": "other fields"
    }
  ]
}
```

---

## GET `/rounds/{round}/reviewer-orders`

List all reviewer orders for a specific round. Only program owner can access.

**Authentication:** Required (program owner)

**Request:**
```http
GET /api/v1/programs/rounds/8/reviewer-orders
Authorization: Bearer <token>
Accept: application/json
```

**Response (200 OK):**
```json
{
  "data": [
    {
      "id": 42,
      "reviewer_id": 127,
      "reviewer": {
        "id": 127,
        "first_name": "Jane",
        "last_name": "Doe",
        "email": "jane@example.com"
      },
      "fee_usd": 150.00,
      "work_status": {
        "value": "delivered",
        "color": "warning"
      },
      "payment_status": {
        "value": "unpaid",
        "color": "info"
      }
    }
  ]
}
```

**Errors:**
- `403 Unauthorized` if requester is not the program owner

---

## POST `/reviewer-orders/{order}/deliver`

Mark a reviewer's work as delivered. Only the assigned reviewer can call this.

**Authentication:** Required (assigned reviewer)

**Request:**
```http
POST /api/v1/programs/reviewer-orders/42/deliver
Authorization: Bearer <token>
Content-Type: application/json

{
  "delivery_note": "All 45 applications scored. Scoring criteria applied consistently. Summary report attached in deal room."
}
```

**Parameters:**
| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `delivery_note` | string | No | Max 1000 chars. Notes about the submission. |

**Response (200 OK):**
```json
{
  "message": "Work marked as delivered. Program owner notified.",
  "data": {
    "id": 42,
    "work_status": "delivered",
    "delivered_at": "2026-09-10T14:30:00Z",
    "...": "full order object"
  }
}
```

**Errors:**
- `403 Unauthorized` if requester is not the assigned reviewer
- `422 Unprocessable Entity` if work_status is not one of `assigned`, `in_progress`, `modification_requested`
- `422` for validation errors

**Side Effects:**
- Program owner receives email notification

---

## POST `/reviewer-orders/{order}/request-modification`

Request the reviewer to make changes to their submitted work. Only program owner can call this.

**Authentication:** Required (program owner)

**Request:**
```http
POST /api/v1/programs/reviewer-orders/42/request-modification
Authorization: Bearer <token>
Content-Type: application/json

{
  "modification_note": "Please re-review applications 12 and 18 — scores seem inconsistent with the rubric for Business Viability."
}
```

**Parameters:**
| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `modification_note` | string | Yes | Max 1000 chars. Requested changes. |

**Response (200 OK):**
```json
{
  "message": "Modification requested. Reviewer notified.",
  "data": {
    "id": 42,
    "work_status": "modification_requested",
    "modification_note": "Please re-review applications 12 and 18...",
    "...": "full order object"
  }
}
```

**Errors:**
- `403 Unauthorized` if requester is not the program owner
- `422 Unprocessable Entity` if work_status is not `delivered`
- `422` for validation errors

**Side Effects:**
- Reviewer receives email with modification request

---

## POST `/reviewer-orders/{order}/approve`

Approve reviewer's work and mark it ready for payment. Only program owner can call this. Typically called after `work_status === 'delivered'` and no further modifications are needed.

**Authentication:** Required (program owner)

**Request:**
```http
POST /api/v1/programs/reviewer-orders/42/approve
Authorization: Bearer <token>
Accept: application/json
```

**Response (200 OK):**
```json
{
  "message": "Work approved. Proceed to initiate payment.",
  "data": {
    "id": 42,
    "work_status": "approved",
    "payment_status": "unpaid",
    "approved_at": "2026-09-11T10:00:00Z",
    "...": "full order object"
  }
}
```

**Errors:**
- `403 Unauthorized` if requester is not the program owner
- `422 Unprocessable Entity` if work_status is not `delivered`
- `422` if already paid

**Side Effects:**
- Reviewer receives email that work is approved and payment is being processed

---

## GET `/reviewer-orders/{order}/payment-status`

Poll the payment status of a reviewer order. Both reviewer and program owner can access.

**Authentication:** Required

**Request:**
```http
GET /api/v1/programs/reviewer-orders/42/payment-status
Authorization: Bearer <token>
Accept: application/json
```

**Response (200 OK):**
```json
{
  "payment_status": "leg1_processing",
  "message": "Payment received. Transferring to reviewer...",
  "updated_at": "2026-09-11T14:30:00Z"
}
```

**Status Values:**
- `unpaid`: Not initiated
- `pending`: STK push in progress
- `leg1_processing`: STK confirmed, W2W transfer in progress
- `completed`: Funds transferred
- `failed`: Payment failed

**Errors:**
- `403 Unauthorized` if requester is neither the reviewer nor the program owner

---

## Reviewer Payment Initiation

The authenticated LIPR initiation endpoint is `POST /api/v1/lipr/initiate-payment`.

**Request:**
```http
POST /api/v1/lipr/initiate-payment
Authorization: Bearer <token>
Content-Type: application/json

{
  "purpose": "reviewer_payment",
  "listing_id": 42,
  "acc_number": "254712345678",
  "amount": 150,
  "purpose_text": "Reviewer payment"
}
```

**Parameters:**
| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `purpose` | string | Yes | Must be `reviewer_payment` |
| `listing_id` | integer | Yes | ReviewerOrder ID |
| `acc_number` | string | Yes | Program owner's M-Pesa phone (254-format) |
| `amount` | number | Yes | Required by shared validation; the backend calculates the order fee instead |
| `purpose_text` | string | Yes | Required by shared validation |

**Validation:**
- Order must exist and belong to the authenticated user's program
- Order must have `work_status` in `['delivered', 'approved']`
- Reviewer must have `lipr_wallet` set
- Order must not already be `payment_status === 'completed'`
- Payment amount is resolved from the order; do not use the client `amount` as the source of truth.

**Response (200 OK):**
```json
{
  "liprResponse": {
    "success": true,
    "reference": "STK-20260911143000-abc123",
    "message": "STK push sent to customer"
  }
}
```

---

## Round Finalization & Round Reviewer Payments

Round finalization uses `POST /api/v1/programs/rounds/{round}/finalize`. It processes advancement and automatically attempts LIPR wallet transfers only for round-review orders that are approved, have a positive fee, meet the paid-application minimum, and have both owner/reviewer wallets and enough program-wallet balance. Other orders are not marked pending just because the round finalized. Use `GET /api/v1/programs/rounds/{round}/finalize-preview` to see the blockers and payment readiness, then `POST /api/v1/programs/rounds/{round}/reviewer-payments` to retry eligible unpaid/failed round orders after finalization. `payment_status: pending` means the transfer is in progress, not settled.

---

## Callbacks (Public Routes)

The payment flow triggers two callbacks:

### Leg 1: STK Push Confirmation
```
POST /api/lipr-callback/reviewer-payment
```
Called after customer approves M-Pesa STK. Records the payment and initiates W2W transfer to reviewer's wallet.

### Leg 2: W2W Confirmation
```
POST /api/lipr-callback/reviewer-payment-leg2
```
Called after W2W transfer completes. Marks order as `payment_status === 'completed'` and sends success notification to reviewer.

---

## Example Round Review Flow

1. The owner creates an assignment with `POST /api/v1/programs/rounds/{round}/reviewers`, including `user_id`, `reviewer_type`, and fee terms. The reviewer accepts with `POST /api/v1/programs/rounds/{round}/reviewers/accept`.
2. The owner assigns applications when the round uses manual assignment; reviewers submit scores through the scoring endpoints.
3. Once the review period ends and paid application thresholds are met, the owner approves the reviewer order with `POST /api/v1/programs/reviewer-orders/{order}/approve`.
4. The owner previews and finalizes the round. Finalization attempts eligible round-review payments directly from the program LIPR wallet to the reviewer's LIPR wallet. If needed, retry with `POST /api/v1/programs/rounds/{round}/reviewer-payments`.
5. Both participants can poll `GET /api/v1/programs/reviewer-orders/{order}/payment-status`. `completed` confirms settlement; `pending` means processing.

For site-visit reviewer orders, use the reviewer order approval/payment flow described above; do not assume round-review auto-payment rules apply to site visits.

---

## Error Handling

### Validation Errors
```json
{
  "message": "Validation failed.",
  "errors": {
    "modification_note": ["The modification note field is required."]
  }
}
```

### Authorization Errors
```json
{
  "error": "Unauthorized",
  "status": 403
}
```

### Resource Not Found
```json
{
  "message": "No query results for model [ReviewerOrder].",
  "status": 404
}
```

### Business Logic Errors
```json
{
  "error": "Cannot deliver from current status: approved",
  "status": 422
}
```

---

## Status Codes

| Code | Meaning |
| --- | --- |
| `200` | Success |
| `201` | Created |
| `400` | Bad request |
| `401` | Unauthenticated |
| `403` | Unauthorized |
| `404` | Not found |
| `422` | Unprocessable entity (validation or business logic) |
| `500` | Server error |

---

## Email Notifications

The system sends the following emails:

| Event | To | Subject |
| --- | --- | --- |
| Reviewer work delivered | PO | Reviewer Work Submitted for Review |
| Modification requested | Reviewer | Modification Requested — Action Required |
| Work approved | Reviewer | Work Approved — Payment Processing |
| Payment initiated | Reviewer | Payment Initiated |
| Payment completed | Reviewer | Payment Received Successfully |
| Payment failed | PO | Reviewer Payment Failed — Action Required |
| Scoring complete | PO | Round Scoring Complete — Ready to Finalize |

---

## Rate Limiting

All endpoints are rate-limited per authenticated user. Refer to the main API rate limiting policy.

---

## Testing

See `/tests/Feature/ReviewerPaymentTest.php` for comprehensive test coverage of all endpoints and workflows.

---

## Current round assignment and payment notes

This section records the current backend contract verified in `routes/api/v1/programs.php`, `RoundReviewerController`, `ReviewerOrderController`, and `ProgramRoundController` (the older examples above do not include all current routes).

- Reviewer assignment supports `reviewer_type: internal|external|third_party`. `third_party` is a service provider already added under organization provider management, and requires `reviewer_fee > 0`.
- Fee configuration accepts `fee_type: flat|per_application` or the UI field `reviewer_payment_basis: flat_round_fee|per_application`. A paid flat fee requires `minimum_apps_to_get_paid`; the legacy `reviewer_payment_max_applications` is accepted as its fallback alias. Per-application mode defaults its paid minimum to 1. The minimum cannot exceed `max_apps_assigned`.
- Invitations begin as `acceptance_status: pending`; the reviewer must accept before the owner can manually assign applications. Current routes include `POST /rounds/{round}/reviewers/accept`, `POST /rounds/{round}/reviewers/decline`, `POST /rounds/{round}/reviewers/{reviewer}/assign-applications`, `DELETE /rounds/{round}/applications/{application}/assignment`, `POST /rounds/{round}/applications/{application}/start-review`, `GET /rounds/{round}/my-applications`, `GET /reviewer/assigned-requests`, and `GET /reviewer/assignment-counts`.
- `GET /rounds/{round}/finalize-preview` reports blockers, advancement decisions and reviewer payment readiness. `POST /rounds/{round}/reviewer-payments` is owner-only and starts ready reviewer payments after round finalization.
- Reviewer order rejection is available at `POST /reviewer-orders/{order}/reject`; round-review work can be rejected only after the review period ends and requires `rejection_reason` (max 1000).
- Reviewer payment is initiated at `POST /api/v1/lipr/initiate-payment` with `purpose: reviewer_payment`, not `/checkout/initiate-payment`. `listing_id` is the ReviewerOrder ID. The order amount is calculated by the backend. Current public LIPR callback paths are `POST /api/lipr-callback/reviewer-payment` and `POST /api/lipr-callback/reviewer-payment-leg2` (no `/v1` prefix). Poll the authenticated `GET /api/v1/programs/reviewer-orders/{order}/payment-status` endpoint for status; an initiated request does not confirm settlement.
