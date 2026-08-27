## Round Questions

`GET|POST /rounds/{round}/questions`, `PATCH|DELETE /questions/{question}`, `POST /applications/{application_id}/rounds/{round_id}/answer`, `GET /applications/{application_id}/rounds/{round_id}/answers`, `DELETE /applications/{application_id}/rounds/{round_id}/answers/{question_id}`.

Question writes are owner-only; answer writes are restricted to the application owner.
