## Questions and Answers

Organization owners configure round questions. Applicants submit and remove answers only for their own application and its current round.

| Method | Endpoint | Request fields |
|---|---|---|
| GET | `/rounds/{round}/questions` | None |
| POST | `/rounds/{round}/questions` | `question_text`, `question_type`, optional required/configuration fields |
| PATCH | `/questions/{question}` | Editable question fields |
| DELETE | `/questions/{question}` | None |
| POST | `/applications/{application_id}/rounds/{round_id}/answer` | `question_id`, `response` |
| GET | `/applications/{application_id}/rounds/{round_id}/answers` | None |
| DELETE | `/applications/{application_id}/rounds/{round_id}/answers/{question_id}` | None |

**Example Response — 200 OK**

```json
{"success":true,"message":"Answer saved successfully.","data":{"application_id":105,"round_id":7,"question_id":18,"response":"Our enterprise serves rural farmers."}}
```

Invalid IDs return 404; missing write fields return 422; attempts by another applicant or non-owner return 403.
