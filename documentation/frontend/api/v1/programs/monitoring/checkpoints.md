## Monitoring Checkpoints

`GET|POST /monitoring/applications/{app}/checkpoints`, `PATCH|DELETE /monitoring/checkpoints/{checkpoint}`. Owners define and maintain checkpoints; request data includes name, type, due date, and reporting requirements. `checkpoint_description` is optional on create and can be updated with `PATCH /monitoring/checkpoints/{checkpoint}` as a nullable string up to 500 characters.

Checkpoint creation accepts optional boolean `send` (defaults to `true` for older clients). Set `send: false` to save a draft; drafts include `sent_at: null`, are visible only to the owner, and cannot be submitted. Owners send a draft with `POST /monitoring/checkpoints/{checkpoint}/send`. Sending is idempotent and sets `sent_at`; applicant checkpoints notify the applicant. Applicant checkpoint lists contain only sent checkpoints.
