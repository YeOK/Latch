# Architecture (for contributors)

Latch is a PHP 8.2+ front controller with Twig themes and one SQLite file. This page is the map a new contributor needs; operator install is in [INSTALL.md](INSTALL.md) and [DOCKER.md](DOCKER.md).

## Request lifecycle

Only `source/public/` is the web root. Every HTTP request hits `source/public/index.php`:

1. **Site lock** — if `bin/latch lock on` is active, most routes return 503 (`SiteLock`). Unlock CLI still works.
2. **Theme assets** — `/assets/*` is served by `ThemeAssetServer` and **exits**. No session, plugins, or `Application`.
3. **Plugin static files** — `/plugin/{slug}/*.{css,js,…}` under that plugin’s `assets/` is served by `PluginAssetServer` the same way.
4. **Kernel** — `Application` constructs services (order is load-bearing: settings → theme → plugins → security headers), boots enabled plugins, then routes to a controller.
5. **Render** — Twig + `SecurityHeaders`. Guest **page cache** is applied in `Application::render()` *after* the controller has already queried. A cache hit still paid that SQL; skipping the controller on hit is an open performance leftover (see [PERFORMANCE.md](PERFORMANCE.md)).

```text
Apache/nginx → public/index.php
  ├─ /assets/*        ThemeAssetServer (no kernel)
  ├─ /plugin/*.{css,…} PluginAssetServer (no kernel)
  └─ Application::run()
       ├─ PluginLoader::boot()     skip incompatible; fail-closed on register gates
       ├─ Router → Controller
       └─ Application::render()    guest HTML cache (post-controller)
```

## Tree

| Path | Role |
|------|------|
| `source/public/` | Web root (`index.php`, `.htaccess`) |
| `source/app/Controllers/` | HTTP actions — thin; CSRF/auth checks here |
| `source/app/Core/` | Kernel, auth, plugins, cache, markup |
| `source/app/Models/` | Repositories — **bound parameters only** for SQL |
| `source/themes/` | Twig + CSS/JS packs (`default`, `modern`, …) |
| `source/plugins/` | Installed plugins (catalog zips land here) |
| `source/database/migrations/` | Core SQLite only — plugins never add files here |
| `source/lang/` | UI strings (`en`, `es`, `de`, `fr`, `ar`) |
| `source/bin/latch` | Operator CLI |
| `packaging/` | Fedora RPM, fail2ban, Apache snippets |
| `docker/` | Image entrypoint + vhost (not the COPR prod path) |

Secrets live in `source/config/local.php` (gitignored). Defaults and comments: `source/config/default.php` and `local.php.example`.

## Where to put a change

- **New forum behaviour that needs core schema, ACL, or notifications** (e.g. follow user) — core migration + repository + controller. Not a plugin: there is no general `user.follow` hook.
- **Optional operator feature** (word filter, invite codes, signatures) — [Latch-plugins](https://github.com/YeOK/Latch-plugins) catalog plugin using existing hooks. Declare `min_latch_version`. Core **hides** catalog entries that need a newer Latch; install/enable still error if forced.
- **Theme / CSS** — pack under `source/themes/{name}/`. Do not put secrets in Twig. `|raw` is only for HTML the server already sanitized.
- **CLI** — add a command in `source/bin/latch` and document it in [CLI.md](CLI.md).

## Security defaults contributors must not weaken

- Failed login **HTTP 200**, success **302** (fail2ban).
- CSRF on every mutating form; staff **step-up** TOTP for sensitive admin POSTs.
- Founder (user id `1`) cannot be demoted/banned by other admins.
- Plugins are high trust: `plugin-audit` is a static gate, not a sandbox.
- Bound SQL parameters; no string-built queries with request data.

## Tests

From `source/`: `php bin/latch test` (full PHPUnit), `test --smoke`, `test --security`. Clone Latch-plugins as `../Latch-plugins` or set `LATCH_PLUGINS_CATALOG`. Details: [TESTING.md](TESTING.md), [CONTRIBUTING.md](../../CONTRIBUTING.md).
