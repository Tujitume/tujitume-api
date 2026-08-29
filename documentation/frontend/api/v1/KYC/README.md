# KYC / KYB API

The KYC API prepares and submits verification for the authenticated account. Base path: `/api/v1/kyc`. It supports business owners (`user_type_id` 1), service providers (3), and organization users (4). Requests use JSON except document uploads, which use multipart form data.

## Authentication

Every endpoint requires Laravel Sanctum authentication.

```http
Authorization: Bearer <token>
Accept: application/json
```

Organization users must belong to an organization. An organization owner can manage its KYC; an active member needs `kyc` in their role's `access_types`. Other user types receive `403`.

## Lifecycle

`draft` → `submitted` → `under_review` → `verified`. A review workflow can also set `rejected`.

| Status | Description | Editable |
| --- | --- | --- |
| `draft` | Being prepared. | Yes |
| `submitted` | Sent for verification. | No |
| `under_review` | Being reviewed. | No |
| `verified` | Successfully verified. | No |
| `rejected` | Rejected by review. | Yes; updating resets it to `draft`. |

There is one KYC record per user and applicable flow. `POST /kyc` returns the existing record; it cannot restart a verified record.

## Endpoints

| Method | Endpoint | Description |
| --- | --- | --- |
| GET | `/kyc` | Get current KYC record. |
| GET | `/kyc/status` | Get current KYC status. |
| POST | `/kyc` | Start KYC. |
| PATCH | `/kyc` | Update KYC. |
| POST | `/kyc/documents` | Upload a document. |
| DELETE | `/kyc/documents/{document}` | Delete a document. |
| POST | `/kyc/submit` | Submit KYC. |

## Response formats

Mutating endpoints use the common envelope:

```json
{"success":true,"message":"KYC draft updated.","data":{}}
```

| Field | Type | Nullable | Description |
| --- | --- | --- | --- |
| `success` | boolean | No | Whether the mutation succeeded. |
| `message` | string | No | Result message. |
| `data` | object/null | Yes | Endpoint-specific response data. |

`GET /kyc` is a Laravel JSON resource and is wrapped in `data`.

```json
{
  "data": {
    "id": 12,
    "verification_type": "entrepreneur",
    "status": "draft",
    "submitted_at": null,
    "rejection_reason": null,
    "details": {},
    "people": [],
    "documents": [],
    "created_at": "2026-08-28T10:00:00.000000Z",
    "updated_at": "2026-08-28T10:00:00.000000Z"
  }
}
```

| Field | Type | Nullable | Description |
| --- | --- | --- | --- |
| `id` | integer | No | KYC record ID. |
| `verification_type` | string | No | `entrepreneur`, `service_provider`, or `organization`. |
| `status` | string | No | Lifecycle status. |
| `submitted_at` | datetime | Yes | Submission timestamp. |
| `rejection_reason` | string | Yes | Returned only when status is `rejected`. |
| `details` | object | Yes | Flow-specific fields. |
| `people` | array | No | People records. |
| `documents` | array | No | Safe document metadata. No URL or storage path is exposed. |

## GET `/kyc`

Returns the authenticated user's record, details, people, and document metadata.

**Authentication:** Required. **Success:** `200 OK`, with the KYC resource above. If KYC has not started, it returns:

```json
{"success":false,"message":"KYC has not been started.","errors":null}
```

## GET `/kyc/status`

Returns lightweight state, including when KYC has not started.

```json
{
  "success": true,
  "message": "KYC status retrieved.",
  "data": {"started": true, "status": "draft", "verification_type": "entrepreneur"}
}
```

| Field | Type | Nullable | Description |
| --- | --- | --- | --- |
| `started` | boolean | No | Whether a KYC record exists. |
| `status` | string | Yes | Current status; `null` when not started. |
| `verification_type` | string | Yes | Applicable flow; `null` when not started. |

## POST `/kyc`

Starts a KYC record or returns the existing one, pre-filling known onboarding values where available. The request body is empty.

**Authentication:** Required. **Success:** `201 Created`.

**Example JSON body:**

```json
{}
```

```json
{"success":true,"message":"KYC draft prepared.","data":{"id":12,"verification_type":"entrepreneur","status":"draft","details":{},"people":[],"documents":[]}}
```

## PATCH `/kyc`

Updates a `draft` or `rejected` record. Start KYC first. All fields are optional during an update; submission validates the required fields for the applicable flow. Sending a field not applicable to the flow returns `422`.

```http
PATCH /api/v1/kyc
Authorization: Bearer <token>
Accept: application/json
Content-Type: application/json
```

### Business Owner KYC

| Field | Type | Required on submit | Validation / allowed values |
| --- | --- | --- | --- |
| `legal_name` | string | Yes | Max 255. |
| `id_type` | string | Yes | `national_id`, `passport`, `drivers_license`, `other`. |
| `id_number` | string | Yes | Max 100. |
| `id_issuing_country` | string | Yes | Exactly 2 characters. |
| `id_expiry_date` | date | Yes | After today. |
| `nationality` | string | Yes | Exactly 2 characters. |
| `physical_address` | string | Yes | Max 255. |
| `county_region` | string | Yes | Max 255. |
| `tax_pin` | string | Yes | Max 100. |
| `is_registered_business` | boolean | No | Enables registered-business requirements. |
| `business_legal_name` | string | Conditional | Required when registered. |
| `business_registration_number` | string | Conditional | Required when registered. |
| `registration_country` | string | Conditional | Exactly 2 characters; required when registered. |
| `legal_structure` | string | Conditional | Required when registered; structure enum below. |

### Service Provider KYC

| Field | Type | Required on submit | Validation / allowed values |
| --- | --- | --- | --- |
| `legal_name` | string | Yes | Max 255. |
| `id_type` | string | Yes | Identity type enum. |
| `id_number` | string | Yes | Max 100. |
| `phone` | string | Yes | Max 50. |
| `email` | string | Yes | Valid email, max 255. |
| `physical_address` | string | Yes | Max 255. |
| `tax_pin` | string | Yes | Max 100. |
| `operates_through_business` | boolean | No | Enables entity requirements. |
| `business_legal_name` | string | Conditional | Required when operating through a business. |
| `business_type` | string | Conditional | Structure enum; required when operating through a business. |
| `business_registration_number` | string | Conditional | Required when operating through a business. |
| `requires_professional_licence` | boolean | No | Requires `professional_licence` at submission when true. |

### Organization KYB

| Field | Type | Required on submit | Validation / allowed values |
| --- | --- | --- | --- |
| `legal_name` | string | Yes | Max 255. |
| `registration_number` | string | Yes | Max 100. |
| `registration_country` | string | Yes | Exactly 2 characters. |
| `legal_structure` | string | Yes | Structure enum. |
| `tax_pin` | string | Yes | Max 100. |
| `physical_address` | string | Yes | Max 255. |
| `county_region` | string | Yes | Max 255. |
| `authorized_representative` | object | Yes | See below. |

Structure values: `sole_proprietor`, `partnership`, `limited_company`, `ngo`, `foundation`, `cooperative`, `other`.

### Request body example: business owner

```json
{
  "legal_name": "Jane Doe",
  "id_type": "passport",
  "id_number": "P1234567",
  "id_issuing_country": "KE",
  "id_expiry_date": "2028-01-01",
  "nationality": "KE",
  "physical_address": "1 Main Street",
  "county_region": "Nairobi",
  "tax_pin": "A123456789Z",
  "is_registered_business": true,
  "business_legal_name": "Jane Doe Enterprises",
  "business_registration_number": "BN-12345",
  "registration_country": "KE",
  "legal_structure": "sole_proprietor",
  "people": [
    {
      "full_legal_name": "Jane Doe",
      "relationship_role": "owner",
      "is_beneficial_owner": true,
      "requires_identity_verification": false
    }
  ]
}
```

### Request body example: service provider

```json
{
  "legal_name": "Alex Consulting Ltd",
  "id_type": "national_id",
  "id_number": "12345678",
  "phone": "+254700000000",
  "email": "alex@example.com",
  "physical_address": "Nairobi",
  "tax_pin": "A123456789Z",
  "operates_through_business": true,
  "business_legal_name": "Alex Consulting Ltd",
  "business_type": "limited_company",
  "business_registration_number": "C-12345",
  "requires_professional_licence": true
}
```

### Request body example: organization

```json
{
  "legal_name": "Community Foundation",
  "registration_number": "NGO-123",
  "registration_country": "KE",
  "legal_structure": "foundation",
  "tax_pin": "A123456789Z",
  "physical_address": "Nairobi",
  "county_region": "Nairobi",
  "authorized_representative": {
    "full_legal_name": "Jane Doe",
    "role_title": "Director",
    "id_type": "passport",
    "id_number": "P1234567",
    "phone": "+254700000000",
    "email": "jane@example.com",
    "authorization_confirmation": true
  },
  "people": [
    {
      "full_legal_name": "Trustee One",
      "relationship_role": "trustee",
      "is_beneficial_owner": false,
      "requires_identity_verification": false
    }
  ]
}
```

### `authorized_representative`

```json
{"full_legal_name":"Jane Doe","role_title":"Director","id_type":"passport","id_number":"P1234567","phone":"+254700000000","email":"jane@example.com","authorization_confirmation":true}
```

| Field | Type | Required | Validation |
| --- | --- | --- | --- |
| `full_legal_name` | string | Yes | Max 255. |
| `role_title` | string | Yes | Max 255. |
| `id_type` | string | Yes | Identity type enum. |
| `id_number` | string | Yes | Max 100. |
| `phone` | string | Yes | Max 50. |
| `email` | string | Yes | Valid email, max 255. |
| `authorization_confirmation` | boolean | Yes | Must be true at submission. |

## People

`people` is an optional replacement array, maximum 50 items. Supplying it replaces the entire people list.

```json
{"full_legal_name":"Jane Doe","relationship_role":"director","ownership_percentage":50,"is_beneficial_owner":true,"nationality":"KE","id_type":"national_id","id_number":"12345678","requires_identity_verification":true}
```

| Field | Type | Required | Validation / rule |
| --- | --- | --- | --- |
| `full_legal_name` | string | Yes | Max 255. |
| `relationship_role` | string | Yes | `owner`, `partner`, `director`, `trustee`, `shareholder`, `beneficial_owner`. |
| `ownership_percentage` | number | No* | Between 0 and 100. |
| `is_beneficial_owner` | boolean | Yes | Boolean. |
| `nationality` | string | No | Exactly 2 characters. |
| `id_type` | string | No* | Identity type enum. |
| `id_number` | string | No* | Max 100. |
| `requires_identity_verification` | boolean | Yes | Boolean. |

For limited companies and partnerships, an owner, partner, shareholder, or beneficial owner requires `ownership_percentage`. A sole proprietor needs an owner; a limited company needs a director; an NGO/foundation needs a director or trustee. When `requires_identity_verification` is true, `id_type`, `id_number`, and a `person_identity` document are required to submit.

## POST `/kyc/documents`

Uploads to the current draft/rejected record. Re-uploading a document with the same type and person replaces it. Files are stored privately; the API never returns a public URL or storage path.

```http
POST /api/v1/kyc/documents
Authorization: Bearer <token>
Accept: application/json
Content-Type: multipart/form-data
```

| Form field | Type | Required | Validation |
| --- | --- | --- | --- |
| `document_type` | string | Yes | Allowed values below. |
| `person_id` | integer | Conditional | Required for `person_identity`; only valid for that type; must be a current KYC person. |
| `file` | file | Yes | PDF, JPEG, or PNG; maximum 10,240 KB. |

Allowed types: `id_passport_copy`, `proof_of_address`, `tax_pin_document`, `business_registration_certificate`, `professional_licence`, `portfolio_work_sample`, `reference`, `registration_certificate`, `tax_compliance_certificate`, `directors_trustees_document`, `authorization_letter_resolution`, `person_identity`.

### Multipart request body

This endpoint does not accept a JSON request body. Send the following fields as multipart form data:

```json
{
  "document_type": "id_passport_copy"
}
```

```json
{"success":true,"message":"KYC document uploaded.","data":{"id":45,"document_type":"id_passport_copy","original_filename":"passport.pdf"}}
```

Required at submission:

| Flow | Documents |
| --- | --- |
| Business owner | `id_passport_copy`, `proof_of_address`, `tax_pin_document`; plus `business_registration_certificate` if registered. |
| Service provider | `id_passport_copy`, `proof_of_address`; plus business certificate if operating through an entity and licence if required. |
| Organization | `registration_certificate`, `tax_compliance_certificate`, `proof_of_address`, `directors_trustees_document`, `authorization_letter_resolution`. |

`portfolio_work_sample` and `reference` are optional provider documents and do not block submission.

## DELETE `/kyc/documents/{document}`

Deletes a document owned by the current KYC record. The `document` path parameter is its integer ID. Foreign/nonexistent documents return `404`; a non-editable KYC state returns `409`.

```json
{"success":true,"message":"KYC document deleted.","data":null}
```

## POST `/kyc/submit`

Validates required flow fields, people requirements, and required documents, then changes status to `submitted`. The request body is empty.

**Example JSON body:**

```json
{}
```

### Pre-submit payload: business owner KYC

Save this payload with `PATCH /api/v1/kyc` before uploading the required entrepreneur documents and calling `POST /kyc/submit`.

```json
{
  "legal_name": "Jane Doe",
  "id_type": "passport",
  "id_number": "P1234567",
  "id_issuing_country": "KE",
  "id_expiry_date": "2028-01-01",
  "nationality": "KE",
  "physical_address": "1 Main Street",
  "county_region": "Nairobi",
  "tax_pin": "A123456789Z",
  "is_registered_business": true,
  "business_legal_name": "Jane Doe Enterprises",
  "business_registration_number": "BN-12345",
  "registration_country": "KE",
  "legal_structure": "sole_proprietor",
  "people": [
    {
      "full_legal_name": "Jane Doe",
      "relationship_role": "owner",
      "is_beneficial_owner": true,
      "requires_identity_verification": false
    }
  ]
}
```

Required uploads: `id_passport_copy`, `proof_of_address`, `tax_pin_document`, and `business_registration_certificate`.

### Pre-submit payload: service provider KYC

Save this payload with `PATCH /api/v1/kyc` before uploading documents and submitting.

```json
{
  "legal_name": "Alex Consulting Ltd",
  "id_type": "national_id",
  "id_number": "12345678",
  "phone": "+254700000000",
  "email": "alex@example.com",
  "physical_address": "Nairobi",
  "tax_pin": "A123456789Z",
  "operates_through_business": true,
  "business_legal_name": "Alex Consulting Ltd",
  "business_type": "limited_company",
  "business_registration_number": "C-12345",
  "requires_professional_licence": true
}
```

Required uploads: `id_passport_copy`, `proof_of_address`, `business_registration_certificate`, and `professional_licence`.

### Pre-submit payload: organization KYB

Save this payload with `PATCH /api/v1/kyc` before uploading documents and submitting.

```json
{
  "legal_name": "Community Foundation",
  "registration_number": "NGO-123",
  "registration_country": "KE",
  "legal_structure": "foundation",
  "tax_pin": "A123456789Z",
  "physical_address": "Nairobi",
  "county_region": "Nairobi",
  "authorized_representative": {
    "full_legal_name": "Jane Doe",
    "role_title": "Director",
    "id_type": "passport",
    "id_number": "P1234567",
    "phone": "+254700000000",
    "email": "jane@example.com",
    "authorization_confirmation": true
  },
  "people": [
    {
      "full_legal_name": "Trustee One",
      "relationship_role": "trustee",
      "is_beneficial_owner": false,
      "requires_identity_verification": false
    }
  ]
}
```

Required uploads: `registration_certificate`, `tax_compliance_certificate`, `proof_of_address`, `directors_trustees_document`, and `authorization_letter_resolution`.

```json
{"success":true,"message":"KYC submitted for review.","data":{"id":12,"verification_type":"entrepreneur","status":"submitted","submitted_at":"2026-08-28T10:15:00.000000Z"}}
```

## Errors

Validation failures use Laravel's standard shape:

```json
{"message":"The given data was invalid.","errors":{"legal_name":["This field is required before submission."]}}
```

| Status | Meaning |
| --- | --- |
| `200` | Successful GET, update, submit, or delete. |
| `201` | KYC started or document uploaded. |
| `401` | Missing/invalid authentication. |
| `403` | Unsupported account type or unauthorized organization member. |
| `404` | KYC not started, or document/person is absent or belongs to another KYC record. |
| `409` | Operation attempted while KYC is not editable, or verified KYC was started again. |
| `422` | Validation, incomplete submission, or conditional document/person rule failure. |

## Typical frontend flow

1. `POST /kyc` to create/load the draft.
2. `PATCH /kyc` with the applicable flow fields and optional `people`.
3. `POST /kyc/documents` for every required document.
4. `GET /kyc/status` to check state.
5. `POST /kyc/submit` once complete.
