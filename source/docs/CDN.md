# CDN and reverse-proxy caching

Latch ships cache-friendly headers for **static theme assets** and **guest HTML** responses. When you terminate TLS at Cloudflare (or another CDN), configure rules so dynamic forum routes stay fresh while `/assets/*` is cached at the edge.

## What Latch sends today

| Resource | Path | Cache-Control (typical) |
|----------|------|-------------------------|
| Theme CSS/JS | `/assets/css/*`, `/assets/js/*` | `public, max-age=86400, must-revalidate` + ETag |
| Guest HTML pages | `/`, `/board/*`, `/topic/*` (eligible guests) | `public, max-age=60–120, must-revalidate` + ETag |
| Authenticated HTML | any logged-in view | `no-store` |
| API / health / admin | `/api/*`, `/health`, `/admin/*` | `no-store` |
| RSS / sitemap | `/feed.xml`, `/sitemap.xml` | short public TTL + ETag |

Guest page cache is optional (admin **Settings → Enable guest page cache**). Fragment cache (board panels, topic lists) is a second layer inside that — see [PERFORMANCE.md](PERFORMANCE.md#fragment-cache).

## Cloudflare (recommended pattern)

Use **Cache Rules** (or Page Rules on older plans). Order matters — most specific first.

### 1. Bypass dynamic routes

**Expression:**

```
(http.host eq "forum.example.com")
and (
  http.request.uri.path starts_with "/admin"
  or http.request.uri.path starts_with "/api"
  or http.request.uri.path starts_with "/login"
  or http.request.uri.path starts_with "/register"
  or http.request.uri.path starts_with "/messages"
  or http.request.uri.path starts_with "/oauth"
  or http.request.uri.path starts_with "/health"
  or http.request.method ne "GET"
)
```

**Action:** Cache eligibility → **Bypass cache**

Also bypass when session cookies are present (logged-in users):

```
(http.cookie contains "PHPSESSID")
or (http.cookie contains "latch_session")
```

(Session cookie name follows your PHP session config.)

### 2. Cache static assets

**Expression:**

```
(http.host eq "forum.example.com")
and http.request.uri.path starts_with "/assets/"
```

**Action:**

- Cache eligibility → **Eligible for cache**
- Edge TTL → **1 month** (or respect origin)
- Browser TTL → respect origin

Latch asset URLs include `?v={{ asset_version }}` — the stamp includes the active theme pack id (so switching `default` ↔ `modern` changes `?v=` even when CSS mtimes overlap). Bump `theme.asset_version` in config for manual invalidation; purge CDN `/assets/*` if an edge still serves stale CSS after a theme change.

### 3. Optional: short edge cache for guest HTML

Only if `members_only` is off and you accept brief staleness (Latch TTL is 60–120s):

```
(http.host eq "forum.example.com")
and http.request.method eq "GET"
and not http.cookie contains "PHPSESSID"
```

**Action:** Edge TTL → **2 minutes**, respect origin `Cache-Control`.

Do **not** cache HTML at the edge for members-only forums or when every page requires a session cookie.

## Origin / Apache notes

- Keep `DocumentRoot` on `source/public/` only.
- If Cloudflare **Orange-cloud** proxies the origin, enable **Full (strict)** TLS to the home server.
- WebSockets are not used by core Latch — no special upgrade rules required.

## Purge after deploy

1. `php bin/latch maintenance --clear-cache` on the server (guest + fragment cache).
2. Cloudflare **Purge Everything** or purge `/assets/*` after theme changes.
3. COPR/RPM `%posttrans` runs `update` which clears page cache when configured.

## Related

- [PERFORMANCE.md](PERFORMANCE.md) — query hot paths, SQLite scale, fragment cache
- [INSTALL.md](INSTALL.md) — TLS, vhost, cron
- [SECURITY.md](SECURITY.md) — headers, sensitive paths