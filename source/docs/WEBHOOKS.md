# Outbound webhooks

Latch can POST signed JSON payloads to HTTPS endpoints when forum events occur. Configure endpoints at **Admin → Webhooks** (`/admin/webhooks`).

## Events

| Event | When | `data` fields |
|-------|------|---------------|
| `post.created` | After a topic or reply is saved | `post_id`, `topic_id`, `board_id`, `board_slug`, `author_id`, `author`, `kind` (`topic` / `reply` / `edit`), `is_first_post` |
| `user.registered` | After a new account is created | `user_id`, `username`, `role` (no email) |

## Delivery

- **Method:** `POST` with `Content-Type: application/json`
- **Timeout:** 5 seconds (synchronous; request thread waits)
- **User-Agent:** `Latch-Webhooks/1.0`
- **Headers:**
  - `X-Latch-Event` — event name (e.g. `post.created`)
  - `X-Latch-Signature` — `sha256=<hmac>` where HMAC is `hash_hmac('sha256', $rawBody, $secret)`

### Payload shape

```json
{
  "event": "post.created",
  "sent_at": "2026-07-03T12:00:00+00:00",
  "data": {
    "post_id": 42,
    "topic_id": 7,
    "board_id": 1,
    "board_slug": "general",
    "author_id": 3,
    "author": "alice",
    "kind": "reply",
    "is_first_post": false
  }
}
```

Verify signatures on your receiver by recomputing HMAC over the **raw request body** with the signing secret shown once at webhook creation.

## Admin

- Endpoints must use **HTTPS**
- Enable/disable without deleting configuration
- Last delivery time and HTTP status are shown per endpoint
- Create/delete actions are recorded in the audit log

## Testing

Use a request bin (e.g. [webhook.site](https://webhook.site)) as the endpoint URL, create a webhook for `post.created`, then post a reply on the forum. Check the bin for headers and JSON body.