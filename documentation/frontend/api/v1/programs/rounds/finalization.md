## Finalization

`POST /rounds/{round}/finalize`, `POST /applications/{application}/advance`, and `POST /applications/{application}/reject`.

Finalization is owner-only and may run once. Automatic advancement respects the configured threshold or quota and awardee limit; manual advancement is allowed only for manual rounds.
