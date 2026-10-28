## Communication APIs

These endpoints require Sanctum authentication unless otherwise noted. The protected API applies token extension, API throttling and date formatting middleware.

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/users/{id}/contact` | Get a public-safe contact card for a chat participant |
| GET | `/meeting-people?search=&page=&per_page=` | Find people to invite to a meeting |
| GET | `/meetings` | List meetings and attendee responses |
| POST | `/respond-meeting` | Accept or decline an invitation |
| POST | `/notifications/read` | Mark one received notification as read |
| GET | `/notifSetRead` | Mark all received notifications as read |

For chat paging, message sending, unread counts, protection checks and websocket notifications, see [Direct Messages](messages.md).

### Contact card

`GET /users/{id}/contact` returns `{ "status":200, "user": {"id":...,"fname":...,"lname":...,"image":...,"role":...,"affiliation":...,"city":...,"country":...,"member_since":...} }`. It does not return email or phone. A missing user returns `404`.

### Meeting people and responses

`GET /meeting-people` returns `{ "data":[{"id":123,"name":"Jane Doe","email":"jane@example.com","image":null,"role":"Entrepreneur","location":"Nairobi, Kenya"}], "meta":{"page":1,"per_page":10,"has_more":false} }`. It excludes the caller and returns user types 1–6. `search` matches first/last/display name and email. `per_page` defaults to 10 and is clamped to 1–50; `page` defaults to 1. With no search, existing meeting participants sort first.

`GET /meetings` returns `{ "meetings":[...] }`. Entries expose `is_host`, `attendees` (each with `user_id`, `name`, `response`, `is_me`), `my_response`, and a role-specific `status`; hosts also receive an attendee summary. Invitation responses are `pending`, `accepted`, `declined`, or `cancelled` (host entries use `response: host`).

`POST /respond-meeting` body is `{"id":123,"response":"accepted"}`; `id` is a meeting invitation row ID and `response` must be `accepted` or `declined`. Only the invited person can respond. Repeated answers and responses to cancelled meetings return `422`; another user's invitation returns `403`.

### Notifications

`POST /notifications/read` requires `{"id":123}` and marks only the authenticated recipient's notification read. It returns `{ "message":"success" }`; an ID not owned by the caller returns `404`. `GET /notifSetRead` marks all notifications for the caller read.
