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
- **Internal**: Organization team member (user_type_id = 6)
- **External**: Freelancer invited specifically for a round (user_type_id = 7)

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

## Reviewer Payment Initiation (via existing endpoint)

Payment is initiated through the existing checkout endpoint, but now with a new `purpose`.

**Request:**
```http
POST /api/v1/checkout/initiate-payment
Authorization: Bearer <token>
Content-Type: application/json

{
  "purpose": "reviewer_payment",
  "listing_id": 42,
  "acc_number": "254712345678"
}
```

**Parameters:**
| Field | Type | Required | Description |
| --- | --- | --- | --- |
| `purpose` | string | Yes | Must be `reviewer_payment` |
| `listing_id` | integer | Yes | ReviewerOrder ID |
| `acc_number` | string | Yes | Program owner's M-Pesa phone (254-format) |

**Validation:**
- Order must exist and belong to the authenticated user's program
- Order must have `work_status` in `['delivered', 'approved']`
- Reviewer must have `lipr_wallet_account` set
- Order must not already be `payment_status === 'completed'`
- Fee must be > 0

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

## Round Finalization & Automatic Payment Trigger

When a program owner finalizes a round via `PATCH /api/v1/programs/rounds/{round}/finalize`, the system automatically:

1. Processes all applications (scoring, knockout, advancement)
2. Marks all reviewer orders with `work_status` in `['delivered', 'approved']` as `payment_status === 'pending'`
3. Sends payment-initiated notifications to all reviewers

The program owner then initiates payment for each reviewer via the checkout endpoint.

---

## Callbacks (Public Routes)

The payment flow triggers two callbacks:

### Leg 1: STK Push Confirmation
```
POST /api/v1/lipr-callback-reviewer-payment
```
Called after customer approves M-Pesa STK. Records the payment and initiates W2W transfer to reviewer's wallet.

### Leg 2: W2W Confirmation
```
POST /api/v1/lipr-callback-reviewer-payment-leg2
```
Called after W2W transfer completes. Marks order as `payment_status === 'completed'` and sends success notification to reviewer.

---

## Example Flow

### 1. Assign Reviewer to Round
```
POST /api/v1/programs/rounds/8/reviewers
{
  "reviewer_type": "external",
  "name": "Dr. Alice Mwangi",
  "email": "alice@reviewfirm.co.ke",
  "max_apps_assigned": 20,
  "expertise_tags": ["agriculture", "fintech"],
  "reviewer_fee": 150,
  "fee_currency": "USD"
}
```
→ ReviewerOrder created with `work_status = assigned`, `payment_status = unpaid`

### 2. Reviewer Submits Scores
```
POST /api/v1/program/applications/{app}/scores
{
  "criterion_scores": [...],
  "overall_comment": "..."
}
```
→ After submitting first score: ReviewerOrder `work_status` → `in_progress`
→ After submitting all scores: ReviewerOrder `work_status` → `delivered`, PO notified

### 3. PO Reviews and Approves
```
POST /api/v1/programs/reviewer-orders/42/approve
```
→ ReviewerOrder `work_status` → `approved`, reviewer notified

### 4. PO Initiates Payment
```
POST /api/v1/checkout/initiate-payment
{
  "purpose": "reviewer_payment",
  "listing_id": 42,
  "acc_number": "254712345678"
}
```
→ M-Pesa STK sent to PO's phone
→ ReviewerOrder `payment_status` → `pending`

### 5. PO Approves M-Pesa STK
→ Callback triggered
→ ReviewerOrder `leg1_reference` saved, `payment_status` → `leg1_processing`
→ W2W transfer initiated to reviewer's wallet

### 6. W2W Transfer Confirmation
→ Leg 2 callback triggered
→ ReviewerOrder `payment_status` → `completed`, `paid_at` set
→ Reviewer receives payment confirmation email

### 7. Reviewer Polls Status
```
GET /api/v1/programs/reviewer-orders/42/payment-status
```
→ Returns `payment_status = completed`

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
