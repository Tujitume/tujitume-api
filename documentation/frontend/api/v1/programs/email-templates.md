## Email Templates

| Method | Endpoint |
|---|---|
| GET | `/{program}/email-templates` |
| GET | `/{program}/email-templates/{event}` |
| PATCH | `/{program}/email-templates/{event}` |
| DELETE | `/{program}/email-templates/{event}` |

The owner may create or replace a template by event. PATCH accepts the configured subject and content fields; invalid event data returns `422`.
