## Program Rounds

All endpoints are under `/api/v1/programs` and require Sanctum authentication. Owners configure rounds; applicants see and submit to published rounds. Reviewers work only on assigned rounds/applications.

`GET /{program}/rounds`, `POST /{program}/rounds`, `GET /rounds/{round}`, `PATCH /rounds/{round}`, and `DELETE /rounds/{round}` manage rounds. `POST /rounds/{round}/configuration-steps` records a wizard step the owner has confirmed. `POST`/`PATCH` round mutations require the owner; validation and lifecycle failures return `422`.

`POST /rounds/{round}/configuration-steps` body is `{"step":"setup"}`. Allowed values: `setup`, `questions`, `reviewers`, `notifications`. It returns `configuration_progress`; a step that is not complete yet returns `422` and the current progress. Use the returned `confirmed_steps`/`configuration_progress` when restoring wizard progress; saved data alone does not mean a step was confirmed.

`POST /rounds/{round}/publish` publishes a configured round. A later round cannot be published until the prior round is finalized. Applications and status history are available from `GET /rounds/{round}/applications`, `/applications/{application}/rounds`, `/applications/{application}/rounds-history`, and `/rounds/{round}/rounds-history`.

Applicant eligibility scoring can include a program-level nullable `match_score_limit` (integer 1–100). Null means no threshold; the program create/update endpoint accepts it only when the current program schema supports the field. The limit affects whether match-score bonus points count; it is not a round advancement threshold.

See [Reviewers](reviewers.md), [Publish](publish.md), and [Finalization](finalization.md) for assignments and end-of-round decisions.
