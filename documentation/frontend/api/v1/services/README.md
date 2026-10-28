## Service Listing Creation

`POST /api/v1/business/create-service` is authenticated and protected by the business middleware. It creates a service listing; it is separate from the public service catalogue endpoints.

The endpoint accepts multipart form data. Required fields:

| Field | Type / validation |
|---|---|
| `name` | string, max 255 |
| `image` | JPG/JPEG/PNG/WEBP, max 2 MB |
| `identification`, `document`, `pin` | PDF or DOCX, max 2 MB each |
| `price` | integer |
| `category` | string, max 155 |
| `details` | string, max 1000 |
| `location` | string, max 300 |

Optional fields: `video` (MP4/MOV/AVI/WEBM/MPG/MPEG/WMV, max 50 MB), `link` (URL; checked for reachability when no video file is uploaded), `sub_category` (string max 155), `lat`/`lng` (strings max 100), `social_impact_areas[]` (strings max 255), and `business_sector_focus[]`.

The provider must have a payment method configured before creating a service. Inappropriate text or image content returns `422`; missing payment setup returns `403` with a `payment_setup_required` code. Validation errors use `{ "message":"Validation failed.","errors":{...} }`. Success returns `{ "message":"Service created successfully." }` with HTTP `200`.

The controller auto-creates a service milestone when `category` is `project_management` or the legacy string `0`; other category values do not trigger that behavior.
