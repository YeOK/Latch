# Latch\Core — request kernel

Bootstrap, router, config, database, session, Twig, auth, plugins, and security headers.

## Request lifecycle

1. `source/public/index.php` — only web entry. Strips `X-Powered-By`, loads Composer.
2. **Site lock** (`Latch\Support\SiteLock`) runs *before* `Application` if `storage/` has a lock file. Unlock is `/maintenance/unlock` or `sudo latch lock off`.
3. **Theme assets** (`ThemeAssetServer`) — `/assets/…` is served here (active pack + default fallback, child `theme.css` merge). No session, plugins, or kernel. Do not replace this with a naive Apache `Alias` (theme switch + CSS merge would break).
4. `Application::__construct()` — settings → theme → session/DB → plugins → `SecurityHeaders`. Order matters.
5. `Application::run()` — match route → controller (`/assets/` is a fallback if something skipped step 3).
6. `Application::render()` — Twig + layout chrome. Guest page cache is checked **here** (controllers have already queried).

## Where things live

| Area | Role |
|------|------|
| `Core/` | Request kernel and security (Auth, Csrf, RateLimiter, BoardAcl, plugins). |
| `Models/` | SQLite repositories. Prefer batch helpers on hot paths (`countsForPosts`, `unreadFlagsForTopics`). |
| `Controllers/` | HTTP. Bound in `Application::registerRoutes()` by method name. |
| `Support/` | CLI / ops helpers (`SiteLock`, backup, restore, doctor, logs). Not the request kernel. |
| `config/local.php` | Secrets and host paths (`Config`). Never ship this file. |
| `settings` table | Operator-tunable forum options (`SettingRepository`, hydrated once per request). |

## Plugins

Hooks: `dispatch` / `filter` / `collect`. Guest-cache modes (`bake` / `fragment` / `client` / `bypass`) live in `Plugins/PluginCacheConfig.php` — read that before adding a hook that injects HTML.

Third-party plugins reach the kernel via `PluginContext::app()`. Public `Application` methods are API.

## Cache tags

Guest HTML uses tags such as `site`, `board:N`, `topic:N`, `user:N`, `plugin:slug`. Invalidate the tag, do not delete individual keys.

## More

- Operator install / Fedora / backups: `source/docs/`
- Query counts: `source/docs/PERFORMANCE.md`
- Security policy: repo `SECURITY.md` + `source/docs/SECURITY.md`
