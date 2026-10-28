## Direct Messages

All endpoints are under `/api/v1`, require Sanctum authentication and return JSON. Message sends broadcast a best-effort realtime notification; the saved message remains successful if broadcasting fails.

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/messages` | List conversation partners, unread counts and latest message page |
| GET | `/messages/thread/{partnerId}` | Load an older page from one conversation |
| POST | `/messages` | Send a direct message |
| POST | `/messages/mark-read` | Mark incoming messages from one partner as read |
| POST | `/messages/protection-check` | Check contact details/profanity before displaying a preview |

### Conversation pages

`GET /messages` returns `{ "messages": [...] }`. Each entry includes partner `id`, `fname`, `lname`, `image`, `sender`, `email`, `unread_count`, `has_more`, and `messages`. Each message includes the stored message fields plus `sender` (`me` for messages whose conversation sender is the current user) and `author` (nullable object with `id`, `name`, `image`, `is_me`). Listing messages does not mark them read.

`GET /messages/thread/{partnerId}` returns `{ "messages": [...], "has_more": boolean }`, newest first. Query `before` is the ID of the oldest message already loaded; `limit` defaults to 20 and accepts 1–50. A `partnerId` must be a numeric user ID. The endpoint returns only the conversation between the authenticated user and that partner.

### Send and read

```json
{"to_id":127,"msg":"Hello"}
```

Both fields are required; `to_id` must be an existing user and `msg` is text up to 2000 characters. Sending to yourself returns `422`. The server censors emails, phone numbers and abusive words in the saved text. If an organization/capital team member sends on behalf of the owner, the conversation is stored under the owner and the returned thread identifies the actual writer in `author` (requires the `sent_by_id` column). Success returns `{ "message":"Message Sent!", "text":"..." }`.

To mark a thread read, send `{"partner_id":127}` to `/messages/mark-read`. This clears unread messages received from that partner; `GET /messages` itself does not clear them.

### Protection check and realtime

`POST /messages/protection-check` accepts `{"text":"..."}` (`text` required, max 2000) and returns `emails`, `phones`, `abusiveWordsFound`, and `abusiveCensored`.

Successful sends attempt a `ChatNotification` broadcast to public channel `msg-notification.{recipientUserId}` with event name `NewMessage`. The event exposes `userId`, `data`, and `payload`; `payload` is `{ "user_id": recipientId, "data": {"message":"Encrypted!"}, "ts": unixTimestamp }`, while `data` is `{"message":"Encrypted!"}`. This event is only a notification; fetch the thread endpoint for the message contents. Websocket delivery is best effort and is not a send acknowledgment.
