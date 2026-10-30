## Monitoring Submissions

`POST /monitoring/checkpoints/{checkpoint}/submit`, `POST /monitoring/submissions/{submission}/verify`, `POST /monitoring/submissions/{submission}/request-changes`, and `DELETE /monitoring/checkpoints/{checkpoint}/submissions`.

Applicants submit reports and optional multipart files. Verified checkpoints cannot receive another submission.

Owners may send `flagged_items` with `POST /monitoring/submissions/{submission}/request-changes`: up to 100 objects with required `field_id` (100 chars), `label` (500 chars), and `note` (240 chars). The readable `reviewer_note` remains required. Verified submissions clear flags. Applicants may remove an uploaded file using `DELETE /monitoring/submission-files/{file}` only while the submission is `changes_requested`; only the application owner may do so.
