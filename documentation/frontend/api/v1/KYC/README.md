# KYC / KYB API

All endpoints are under `/api/v1/kyc`, require a Sanctum bearer token, and return JSON. KYC is available only to business owners (`user_type_id: 1`), service providers (`3`), and organization users (`4`). Mutations return `{ "success": true, "message": "…", "data": { … } }`.

## Endpoints

| Method | Endpoint | Purpose |
| --- | --- | --- |
| GET | `/kyc` | Read the caller’s current KYC record; 404 if not started. |
| GET | `/kyc/status` | Read `{started,status,verification_type}`. |
| POST | `/kyc` | Start or retrieve the caller’s draft (201). |
| PATCH | `/kyc` | Update a draft or rejected record. |
| POST | `/kyc/documents` | Upload/replace one private document (multipart). |
| DELETE | `/kyc/documents/{document}` | Delete a caller-owned document. |
| POST | `/kyc/submit` | Validate and submit for review. |

`GET /kyc` returns `id`, `verification_type`, `status`, `submitted_at`, rejected `rejection_reason`, `details`, `people`, and document metadata. Storage paths and URLs are never returned. Documents live on the private `kyc` disk; there is no public-download endpoint.

## Authorization and lifecycle

Lifecycle: `draft → submitted → under_review → verified` or `rejected`. Only drafts/rejected records can be changed, submitted, or have documents changed. Updating a rejected record restores draft status. Verified records cannot be overwritten. There is one record per user/flow, preventing duplicate submissions.

Organization accounts need an organization. The owner can manage KYB; an active member needs `kyc` in their role `access_types`. Callers cannot access another account’s record/document. Admin/reviewer users have no self-service KYC route permission.

## PATCH fields

Send fields only for the caller’s flow; inappropriate fields return 422. Entrepreneur: `legal_name`, `id_type` (`national_id`, `passport`, `drivers_license`, `other`), `id_number`, `id_issuing_country` (ISO-2), future `id_expiry_date`, `nationality`, `physical_address`, `county_region`, `tax_pin`, `is_registered_business`, and, when registered, `business_legal_name`, `business_registration_number`, `registration_country`, `legal_structure`.

Provider: `legal_name`, `id_type`, `id_number`, `phone`, `email`, `physical_address`, `tax_pin`, `operates_through_business`, `requires_professional_licence`; an entity needs `business_legal_name`, `business_type`, and `business_registration_number`.

Organization: `legal_name`, `registration_number`, `registration_country`, `legal_structure`, `tax_pin`, `physical_address`, `county_region`, plus `authorized_representative` containing `full_legal_name`, `role_title`, `id_type`, `id_number`, `phone`, `email`, and `authorization_confirmation`.

Structures: `sole_proprietor`, `partnership`, `limited_company`, `ngo`, `foundation`, `cooperative`, `other`. Starting pre-fills known user/organization/listing data; clients provide missing verification data only.

`people` replaces the people list (max 50). Each item has `full_legal_name`, `relationship_role` (`owner`, `partner`, `director`, `trustee`, `shareholder`, `beneficial_owner`), optional 0–100 `ownership_percentage`, `is_beneficial_owner`, optional nationality/ID, and `requires_identity_verification`. Sole proprietors need an owner, companies a director, and NGO/foundations a director or trustee. Ownership percentages are required for ownership roles in companies/partnerships but not NGO/foundations. Individual IDs/documents are required only when individual verification is requested.

Example:

```json
{"legal_name":"Jane Doe","id_type":"passport","id_number":"P1234567","id_issuing_country":"KE","id_expiry_date":"2028-01-01","nationality":"KE","physical_address":"1 Main St","county_region":"Nairobi","tax_pin":"A123456789Z","is_registered_business":false,"legal_structure":"sole_proprietor","people":[{"full_legal_name":"Jane Doe","relationship_role":"owner","is_beneficial_owner":true,"requires_identity_verification":false}]}
```

## Documents and submit

Upload multipart `document_type` and `file`; `person_id` is allowed only for `person_identity`. PDF/JPEG/PNG only, maximum 10 MB. Reuploading the same document type/person replaces it. Required on submission: entrepreneur—`id_passport_copy`, `proof_of_address`, `tax_pin_document` (+ `business_registration_certificate` if registered); provider—identity/address (+ entity certificate and `professional_licence` when applicable); organization—`registration_certificate`, `tax_compliance_certificate`, `proof_of_address`, `directors_trustees_document`, `authorization_letter_resolution`. Provider `portfolio_work_sample` and `reference` are optional and never gate identity KYC.

Example: `curl -H "Authorization: Bearer TOKEN" -F "document_type=id_passport_copy" -F "file=@passport.pdf" https://api.example.test/api/v1/kyc/documents`

Status codes: 200 success, 201 start/upload, 401 unauthenticated, 403 unauthorized type/org role, 404 absent/foreign resource, 409 lifecycle conflict, 422 validation or incomplete submit. Validation follows Laravel: `{ "message": "…", "errors": { "field": ["…"] } }`.
