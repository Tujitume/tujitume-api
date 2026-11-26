# AI usage and cost

All routes below are protected by the existing `auth:sanctum` middleware and live under `/api/v1/programs`. Users can read only their own usage, except for application usage, which follows the existing program-owner/team and assigned-reviewer access checks. Costs are estimates from the pricing snapshot stored per request. No route accepts user IDs or cost values from the client.

## Current user's totals

`GET /api/v1/programs/ai/usage/me` returns lifetime, current calendar month, and current day totals for the authenticated user. Daily usage uses the configured application timezone.

## Current user's request history

`GET /api/v1/programs/ai/usage/history` returns that user's paginated request records. Optional query parameters: `from`, `to`, `provider`, `model`, `application_id`, `operation`, and `per_page` (1–100).

Example: `GET /api/v1/programs/ai/usage/history?provider=groq&operation=review&per_page=25`.

## Application usage

`GET /api/v1/programs/applications/{application}/ai-usage` returns request-level records, totals, and provider/model breakdown for an application. Program owners/team members and assigned reviewers may access it; applicants and unrelated users receive 403.

Example: `GET /api/v1/programs/applications/482/ai-usage`.

All routes are GET and accept no JSON body. Successful responses use `{ "success": true, "data": ... }`; invalid filters return the standard 422 validation response, and unauthenticated requests are rejected by Sanctum middleware.

Example current-usage response:

```json
{
  "success": true,
  "data": {
    "currency": "USD",
    "lifetime": {
      "total_requests": 3,
      "successful_requests": 2,
      "failed_requests": 1,
      "applications_reviewed": 2,
      "input_tokens": 1200,
      "output_tokens": 350,
      "cached_tokens": 100,
      "total_tokens": 1550,
      "total_cost": "0.000180000000"
    },
    "current_month": {},
    "current_day": {}
  }
}
```
