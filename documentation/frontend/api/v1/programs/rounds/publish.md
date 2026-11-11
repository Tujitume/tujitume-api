## Publish Round

Publishes a configured round. Only the owning organization can perform this state transition. Published rounds accept submissions and cannot be published again.

**Endpoint** `POST /api/v1/programs/rounds/{round}/publish`

**Path Parameters**

| Parameter | Required | Type | Notes |
|---|---|---|---|
| `round` | Yes | integer | Existing round ID owned by the caller |

**Request Body**: none.

**Example Response — 200 OK**

```json
{"success":true,"message":"Round published successfully.","data":{"id":7,"round_name":"Application","round_number":1,"status":"published"}}
```

**Error Responses**

| Status | Condition |
|---:|---|
| 401 | No Bearer token |
| 403 | Caller is not the program owner |
| 422 | Missing dates, criteria, reviewers, advancement configuration, or invalid prior-round state |

**Business Rules**: open and close dates must be valid; scoring modes require scoring criteria; score-threshold and fixed-quota modes require their threshold or maximum. Later rounds cannot publish until the preceding round is finalized.

## AI rounds

Rounds that use AI review also need `ai_review_config` (at least one dimension), `AI_REVIEW_ENABLED`, a configured provider and the privacy gate. Missing items come back as the error keys `ai_review_config`, `ai_enabled`, `ai_provider` and `ai_privacy`. See [AI review](../ai-review.md).
