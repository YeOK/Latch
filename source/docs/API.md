# Latch REST API & OAuth 2.0

Phase 3 read API for boards, topics, posts, and public user profiles. OAuth 2.0 is built in for third-party clients (mobile apps, integrations).

**Base URL:** your site URL (e.g. `https://forum.example.com`)

## Authentication

| Mode | Use case |
|------|----------|
| **No token** | Guest read access (public boards only; IP rate limit 60/min) |
| **Bearer token** | OAuth access token — guest-level (`client_credentials`) or user-delegated (`authorization_code`) |

Send tokens on API requests:

```http
Authorization: Bearer latch_at_…
```

Board ACLs, `members_only`, quarantine, and spam approval rules apply the same as the web UI.

## OAuth 2.0

### Register a client (server CLI)

```bash
php bin/latch api-client create --name="My App" --redirect=https://app.example/oauth/callback
php bin/latch api-client list
php bin/latch api-client revoke --client-id=latch_…
```

Confidential clients receive a `client_secret` once. Public clients use `--public` and PKCE only.

### Token endpoint

`POST /oauth/token` (`application/x-www-form-urlencoded`)

| Grant | Parameters |
|-------|------------|
| `client_credentials` | `client_id`, `client_secret`, `scope` (optional, default `read`) |
| `authorization_code` | `client_id`, `client_secret` (if confidential), `code`, `redirect_uri`, `code_verifier` |
| `refresh_token` | `client_id`, `client_secret` (if confidential), `refresh_token` |

Response:

```json
{
  "access_token": "latch_at_…",
  "token_type": "Bearer",
  "expires_in": 3600,
  "scope": "read",
  "refresh_token": "latch_rt_…"
}
```

`refresh_token` is only returned for authorization-code grants.

### Authorization code + PKCE

1. Redirect the user to:

```
GET /oauth/authorize?response_type=code
  &client_id=…
  &redirect_uri=…
  &scope=read%20messages:read%20messages:write
  &state=…
  &code_challenge=…
  &code_challenge_method=S256
```

2. User signs in (if needed) and approves on the consent screen.
3. Exchange the `code` at `POST /oauth/token` with the matching `code_verifier`.

## REST API v1

All responses use:

```json
{ "data": …, "meta": { … } }
```

Errors:

```json
{ "error": { "code": "not_found", "message": "…" } }
```

### `GET /api/v1`

API metadata and current auth context.

### `GET /api/v1/boards`

List boards visible to the caller.

### `GET /api/v1/boards/{slug}`

Single board.

### `GET /api/v1/boards/{slug}/topics`

Query: `page` (default 1), `per_page` (default 25, max 100).

### `GET /api/v1/topics/{id}`

Single topic.

### `GET /api/v1/topics/{id}/posts`

Posts in thread order. Quarantined bodies are omitted for non-staff.

### `GET /api/v1/users/{username}`

Public profile (no email). Requires sign-in when `members_only` is enabled.

## Scopes (v1)

| Scope | Access |
|-------|--------|
| `read` | All read endpoints above |

| `messages:read` | Direct messages — inbox, threads, mark read (**user-delegated token only**) |
| `messages:write` | Start conversations and send direct messages (**user-delegated token only**) |

`messages:*` scopes cannot be obtained via `client_credentials`. Use the authorization code + PKCE flow so the token is bound to a signed-in member.

Other write scopes (post, vote, mod) are planned for a later release.

## Direct messages API

Requires a **user-delegated** Bearer token with `messages:read` and/or `messages:write`. Request bodies are JSON (`Content-Type: application/json`).

### `GET /api/v1/messages`

List your conversations. Query: `limit` (default 50, max 100).

### `GET /api/v1/messages/{id}`

Thread messages for a conversation. Query: `after` (message id for pagination), `limit`. Marks the thread read when `after` is omitted.

### `POST /api/v1/messages`

Start a conversation. Body: `{ "username": "member", "body": "optional first message" }`. Requires `messages:write`.

### `POST /api/v1/messages/{id}/send`

Send a message. Body: `{ "body": "Hello" }`. Requires `messages:write`.

### `POST /api/v1/messages/{id}/read`

Mark conversation read. Requires `messages:read`.

Same opt-in, block, and staff rules as the web UI (`MessageService`).

## Rate limits

| Caller | Limit |
|--------|-------|
| Guest (no token) | 60 requests/minute per IP |
| OAuth client | Per-client setting (default 60/min) |

## Audit log

API requests are logged to `api_audit_log` (client id, user id, method, path, status, IP). Pruned by `php bin/latch maintenance`.

## OpenAPI sketch

```yaml
openapi: 3.1.0
info:
  title: Latch API
  version: "1.0"
servers:
  - url: https://forum.example.com
paths:
  /api/v1/boards:
    get:
      summary: List boards
      security:
        - bearerAuth: []
        - {}
      responses:
        "200":
          description: Board list
  /oauth/token:
    post:
      summary: OAuth token endpoint
      requestBody:
        content:
          application/x-www-form-urlencoded:
            schema:
              type: object
              properties:
                grant_type: { type: string }
                client_id: { type: string }
                client_secret: { type: string }
components:
  securitySchemes:
    bearerAuth:
      type: http
      scheme: bearer
```

Full OpenAPI generation is planned for Phase 5.