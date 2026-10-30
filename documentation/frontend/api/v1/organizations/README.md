## Organizations and Third-Party Providers

All listed routes are under `/api/v1` and require Sanctum authentication.

### Organization providers

These routes manage the service-provider directory available to an organization; they do not alter a provider's account.

| Method | Endpoint | Access |
|---|---|---|
| GET | `/organizations/providers` | Organization member |
| POST | `/organizations/providers` | Organization super admin |
| DELETE | `/organizations/providers/{provider}` | Organization super admin |
| GET | `/organizations/{organization}/reviewers` | Organization reviewer management |
| PATCH | `/organizations/{organization}` | Update organization |

`POST /organizations/providers` body is `{"user_id":123}`. The ID must exist and identify a Tujitume service-provider account (`user_type_id` 3). Success is `201` with a message; an already-added provider returns `200` and `already_added: true`. Non-provider IDs return `422`; non-super-admins return `403`. If the provider directory migration is absent, the endpoint returns `503`.

`GET /organizations/providers` returns `{ "data":[{"id":123,"name":"A. Provider","email":"provider@example.com","image":null,"added_at":"..."}] }`. Removing a provider returns `{ "message":"Removed from your list. Their account is not affected." }`.

Organizations can search all service providers for review/audit or supplier work through `GET /api/v1/programs/third-party-providers?search=&page=1&per_page=10`. It is organization-only. Result rows include `id`, `name`, `email`, `phone`, `image`, `location`, `added`, and `services[]`; pagination is in `meta` (`page`, `per_page`, `has_more`). Search matches name/display name, email or city.

Service providers see assigned work through `GET /api/v1/programs/provider/work?type=all|review|inspection|audit|supplier` (default `all`). Only user type 3 can call it. It returns a combined `data` array with `kind`, `id`, `reference`, `program_id`, `owner_id`, `program`, `business`, `milestone`, `detail`, `location`, `support_type`, `amount`, `status`, and `due`; supplier rows also include `payment_route`. The `type` filter selects review invitations, site inspections, MPRV audits, or supplier assignments.

Inspection work rows also include `order_id`, `amount`, `currency`, `work_status`, `payment_status`, `fee_status`, and `proposed_fee` from the linked reviewer order. Audit rows include null values for order/payment fields. `GET /api/v1/programs/third-party-providers` includes service location, coordinates, listed price, and each service's own price and coordinates.
