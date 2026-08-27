## Rounds

`GET /{program}/rounds`, `POST /{program}/rounds`, `GET /rounds/{round}`, `PATCH /rounds/{round}`, `DELETE /rounds/{round}`, `POST /rounds/{round}/publish`, `GET /rounds/{round}/applications`, `GET /rounds/{round}/applications/active`, `GET /rounds/{round}/applications/advanced`, `GET /rounds/{round}/applications/not_selected`, `GET /rounds/applications/{application}`, `POST /applications/{application}/current-round/submit`, `GET /applications/{application}/rounds`, `GET /applications/{application}/rounds-history`, and `GET /rounds/{round}/rounds-history`.

Create/update payloads configure names, dates, rubric, criteria, reviewer assignment, and advancement rules. Only owners may write. Missing required configuration returns `422`.
