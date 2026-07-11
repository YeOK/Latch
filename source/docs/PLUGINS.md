# Latch plugins (Phase 4)

Customize Latch **without editing core** (`app/`, migrations, router). Plugins live in `plugins/{slug}/` and are enabled explicitly by the operator.

## Do not hack core

Upgrades replace `source/app/`, `source/bin/`, and `source/database/migrations/`. Put site-specific behaviour in:

- **Plugins** — PHP hooks and optional routes
- **Themes** — Twig/CSS/JS under `themes/`
- **`config/local.php`** — secrets and environment only

## Layout

```
plugins/
  example/
    plugin.json       # manifest (required)
    src/Plugin.php    # bootstrap class
    README.md
```

## Manifest (`plugin.json`)

| Field | Required | Notes |
|-------|----------|-------|
| `name` | yes | Display name |
| `slug` | yes | Directory name (e.g. `example`) |
| `version` | yes | Semver |
| `min_latch_version` | yes | e.g. `0.3.0` — matched against `config` `app.version` |
| `hooks` | yes | Subset of [hooks](#hooks) the plugin uses |
| `description` | no | Short summary |
| `bundled` | no | Legacy **`true`** — shipped in older core tarballs; catalog plugins omit this field |
| `permissions.filesystem` | no | Writable paths (plugin dir or `storage/plugins/{slug}/`) |
| `permissions.network` | no | Declare if plugin performs outbound HTTP |
| `permissions.config` | no | Setting keys the plugin may read (not `local.php` secrets) |
| `database.enabled` | no | **`true`** — use `storage/plugins/{slug}/plugin.sqlite` + `migrations/*.sql` (see [Plugin database](#plugin-database)) |
| `settings_schema` | no | Admin-editable fields → `storage/plugins/{slug}/settings.json` |
| `secrets_schema` | no | `local.php` key paths (display-only in admin; values never stored in DB/JSON) |
| `cache` | no | Guest page / fragment behaviour for collect hooks (see [Guest cache](#guest-cache)) |

Bootstrap class convention:

- File: `plugins/{slug}/src/Plugin.php`
- Class: `Latch\Plugins\{StudlySlug}\Plugin` (e.g. `Latch\Plugins\Example\Plugin`)
- Must implement `Latch\Core\Plugins\PluginInterface`

## Hooks

Hooks are registered in `Plugin::register()` via `$context->hooks()->add(...)`. Lower **priority** runs first (default `10`).

Three invocation styles:

| Style | Method | Use when |
|-------|--------|----------|
| **dispatch** | `dispatch($hook, ...$args)` | Side effects only; no return value |
| **collect** | `collect($hook, ...$args)` | Gather strings, URLs, or arrays from callbacks (`null`/`''` skipped) |
| **filter** | `filter($hook, $value, ...$args)` | Each callback receives the current value and returns the next |

Declare every hook you use in `plugin.json` → `hooks`.

### Hook reference

| Hook | Style | Status | When / args | Return / effect |
|------|-------|--------|-------------|-----------------|
| `bootstrap` | dispatch | live | After routes and `route.register`; `($app)` | — |
| `route.register` | dispatch | live | During boot; `($router, $app)` | Register routes on `$router` |
| `board.icons` | dispatch | live | During boot; `($boardIconRegistry)` | Call `$registry->register($key, $svg)` |
| `theme.assets` | collect | live | Each page render; `($app)` | CSS URL string(s) → `<link>` in layout |
| `layout.footer` | collect | live | Each page render; `($app)` | HTML snippet(s) above footer meta |
| `home.after_boards` | collect | live | Home page; `($app)` | HTML after board list |
| `post.before_save` | dispatch | live | Before topic/reply/edit persist; `($ctx)` | Mutate `$ctx->body` or `$ctx->reject($reason)` |
| `post.after_save` | dispatch | live | After successful save; `($ctx)` | Side effects (webhooks, analytics) |
| `user.register` | dispatch | live | After new account created; `($user, $app)` | Welcome email, defaults |
| `admin.menu` | collect | live | Admin layout; `($app)` | Nav items: `{label, href, match?}` |
| `editor.compose` | collect | live | Compose toolbar; `($app)` | HTML for extra toolbar buttons |
| `post.format.image_host` | filter | live | Markdown `![](url)` render; `($allowed, $host)` | Return `true` to allow host |
| `csp.img_src` | collect | live | Response headers (after plugin boot); `($app)` | Extra `img-src` hostnames (no scheme) |
| `csp.connect_src` | collect | live | Response headers (after plugin boot); `($app)` | Extra `connect-src` hostnames for `fetch` (e.g. R2 presigned PUT) |
| `theme.scripts` | collect | live | Each page render; `($app)` | Deferred `<script src>` URLs appended in layout |
| `avatar.resolve` | filter | live | Avatar URL build; `($url, $email, $size)` | Final avatar URL string |
| `locale.translations` | filter | live | Translator boot; `($strings, $locale)` | Merged translation array for active locale |

Twig globals from collect hooks: `plugin_theme_assets`, `plugin_theme_scripts`, `plugin_footer_html`, `plugin_home_after_boards_html`, `plugin_admin_menu_items`, `plugin_composer_toolbar`.

## Guest cache

Collect hooks (`theme.assets`, `layout.footer`, `home.after_boards`, etc.) can declare how their HTML is cached for **guests** via the manifest `cache` object. Logged-in users always bypass guest page and fragment caches.

```json
"cache": {
  "guest_page": "bake",
  "invalidate_on": ["site"],
  "fragment": null,
  "client": null
}
```

| Field | Values | Meaning |
|-------|--------|---------|
| `guest_page` | `bake` (default), `fragment`, `client`, `bypass` | How hook HTML is served to guests |
| `invalidate_on` | `["site"]`, `["plugin"]`, `["site","plugin"]` | Extra cache tags; core merges with hook placement defaults |
| `fragment` | hook name e.g. `home.after_boards` | Required when `guest_page` = `fragment` — per-plugin fragment cache keyed by `tagPlugin:{slug}` |
| `client` | route e.g. `/plugin/git-release/widget.json` | Required when `guest_page` = `client` — page cache stores a placeholder; browser loads JSON later |

| `guest_page` mode | Use when | Guest behaviour |
|-------------------|----------|-----------------|
| **`bake`** | Static/slow-changing HTML (forum-stats) | Hook runs on page cache miss; HTML inside full page cache |
| **`fragment`** | Plugin block should refresh independently | Plugin HTML in fragment cache; bust via `tagPlugin:{slug}` |
| **`client`** | External/live data (git-release, GitHub API) | Minimal shell in page cache; browser loads JSON endpoint |
| **`bypass`** | Must never cache plugin output | Guest page cache disabled for the whole site while enabled (rare) |

**Invalidation:**

- Enable/disable plugin → `tagPlugin:{slug}` plus Twig/page cache clear (admin and CLI).
- Post/topic/board changes → core calls `invalidateCacheTags()` and merges `tagPlugin:{slug}` for plugins with `invalidate_on` including `"plugin"`.
- `user.register` → core busts `tagSite` (e.g. forum-stats member counts with `invalidate_on: ["site"]`).

**Audit:** `plugin-audit` flags `fragment` without `cache.fragment`, `client` without `cache.client`, `cache.client` paths that are not same-origin (must start with `/`), or a `cache.fragment` hook not listed in `hooks`.

**Example (forum-stats):**

```json
"cache": {
  "guest_page": "bake",
  "invalidate_on": ["site"]
}
```

Omit `cache` entirely to use the same defaults (`bake` + `invalidate_on: ["site"]`).

### `post.before_save` / `post.after_save`

`PostSaveContext` is passed to both hooks:

| Field | Notes |
|-------|-------|
| `$ctx->body` | Mutable post body (plugins may sanitize) |
| `$ctx->user` | Author row |
| `$ctx->board` | Board row |
| `$ctx->topic` | Topic row (`null` when creating a new topic) |
| `$ctx->post` | Saved post row (`null` before save; set on `after_save`) |
| `$ctx->kind` | `topic`, `reply`, or `edit` |
| `$ctx->topicTitle` | Set for new topics only |
| `$ctx->reject($reason)` | Blocks save; reason shown as flash error |

```php
use Latch\Core\Plugins\HookName;
use Latch\Core\Plugins\PostSaveContext;

$hooks->add(HookName::POST_BEFORE_SAVE, static function (PostSaveContext $ctx): void {
    if (preg_match('/badword/i', $ctx->body)) {
        $ctx->reject('Please remove prohibited language.');
    }
}, 10);
```

### `admin.menu`

Return associative arrays (or an array of them from one callback). Register the page under `/admin/…` (via `route.register`) so `account-panel.js` loads it in-place in the admin shell instead of a full navigation.

```php
$hooks->add(HookName::ADMIN_MENU, static fn (): array => [
    'label' => 'My plugin',
    'href' => '/admin/my-plugin',
    'match' => '/admin/my-plugin',  // optional prefix for active state
]);
```

### `post.format.image_host` + `csp.img_src`

Markdown images (`![alt](https://host/…)`) render only when a plugin allows the host. Pair with `csp.img_src` so browsers can load the image:

```php
$hooks->add(HookName::POST_FORMAT_IMAGE_HOST, static fn (bool $allowed, string $host): bool =>
    $allowed || $host === 'imagedelivery.net'
);
$hooks->add(HookName::CSP_IMG_SRC, static fn (): string => 'imagedelivery.net');
```

### `avatar.resolve`

Filter starts from core’s Gravatar URL (or `''` when Gravatar is disabled). Return a custom HTTPS URL or pass through the default:

```php
$hooks->add(HookName::AVATAR_RESOLVE, static function (string $url, string $email, int $size): string {
    // return custom URL or $url
    return $url;
});
```

### `editor.compose`

Return safe HTML inserted into the compose toolbar (e.g. an “Insert image” button for an upload plugin):

```php
$hooks->add(HookName::EDITOR_COMPOSE, static fn (): string =>
    '<button type="button" class="composer-btn" data-action="image">Image</button>'
);
```

### Examples

```php
$context->hooks()->add(HookName::LAYOUT_FOOTER, static fn (): string => '<p class="muted">Hello</p>', 10);

$context->hooks()->add(HookName::ROUTE_REGISTER, function ($router, $app): void {
    $router->get('/plugin/example', static fn () => Response::json(['ok' => true]));
});
```

## Plugin database

Plugins that need their own tables use a **separate SQLite file** — never `latch.sqlite`.

| Layer | Location |
|-------|----------|
| Plugin DB | `storage/plugins/{slug}/plugin.sqlite` |
| Migrations | `plugins/{slug}/migrations/*.sql` (shipped with plugin code) |
| Settings | `storage/plugins/{slug}/settings.json` (non-secret toggles) |

Declare in `plugin.json`:

```json
"database": { "enabled": true }
```

**Lifecycle:**

1. **`plugin enable`** (CLI or admin) runs pending migrations before adding the slug to `enabled_plugins`.
2. **App boot** runs pending migrations for enabled plugins (e.g. after a plugin upgrade adds new SQL files).
3. **Disable** leaves `plugin.sqlite` on disk.
4. **`plugin remove --confirm --purge-storage`** deletes the whole `storage/plugins/{slug}/` tree.

Migration tracking uses `plugin_migrations` (per file) and `plugin_meta` (`schema_version`, `applied_at`) inside `plugin.sqlite`. Same WAL / `busy_timeout` pragmas as core.

**In plugin code:**

```php
$db = $context->database();
if ($db !== null) {
    $db->pdo()->prepare('INSERT INTO spam_log (...) VALUES (...)')->execute([...]);
}
```

Reference: `docs/plugins/dbexample/` (install with `plugin install docs/plugins/dbexample`). For reject logging, see the `spam_log` schema in that README — used by the planned **spam-bridge** plugin.

`bin/latch backup` archives all of `storage/`, including every `plugin.sqlite`.

## Enable / disable

Enabled slugs are stored in settings as JSON: `enabled_plugins` → `["example"]`.

```bash
php bin/latch plugin list
php bin/latch plugin install docs/plugins/example   # directory or .zip → plugins/{slug}/
php bin/latch plugin enable example
php bin/latch plugin disable example
php bin/latch plugin remove example --confirm       # delete plugins/example/
php bin/latch plugin remove example --confirm --purge-storage   # also delete storage/plugins/example/
```

`plugin install` copies the tree, runs `plugin-audit`, and leaves the plugin **disabled**. Critical audit findings roll back the install. Remote URLs are not supported in v1 (local directory or `.zip` only).

### Plugin catalog

**[github.com/YeOK/Latch-plugins](https://github.com/YeOK/Latch-plugins)** — tier 1 distributable plugins (sources + GitHub Release `.zip` assets). The core tarball no longer ships these under `plugins/`; install from a release zip or clone:

```bash
php bin/latch plugin install ./forum-stats-1.0.0.zip
php bin/latch plugin-audit forum-stats
php bin/latch plugin enable forum-stats
```

Catalog v1.0.0: `forum-stats`, `image-upload`, `word-filter`, `spam-bridge`, `slack-notify`. See the catalog README for bundle zip and per-plugin READMEs.

Until admin browser install lands, use `plugin install` with a local path or zip. Admin install from the catalog is planned (GitHub-only; audit gate unchanged).

**Shipped in core `plugins/` today:** `md-import` only (operator plugin — not in the public catalog).

Enable/disable (CLI and admin) invalidates `tagPlugin:{slug}` and clears guest page / Twig cache automatically.

**Admin UI:** `/admin/plugins` lists discovered plugins, audit status, and enable/disable (CSRF-protected). Enable is blocked until `plugin-audit` passes. Audit results are **cached** on disk (`storage/cache/plugin-audits/`); the admin page reuses the cache when plugin files are unchanged, otherwise scans once and refreshes the cache.

**Audit schedule:** `cron daily` re-scans all non-ignored plugins and updates the cache. Manual `plugin-audit` always forces a fresh scan.

**Ignored plugins (CLI only):** `php bin/latch plugin ignore <slug>` writes `"ignored": true` to `plugin.json`, disables the plugin, and removes it from discovery, admin UI, and audits. Use for seasonal plugins you want to keep on disk without maintenance overhead. Restore with `plugin unignore <slug>`. List ignored plugins: `plugin list --all`.

**Audit gate:** `plugin enable` (CLI and admin) runs a fresh audit first. Critical findings block enable unless CLI `--force` (logged to `audit_log` as `plugin.enable_forced`).

### Production permissions (RPM / `apache`)

Stateful plugin data lives under `storage/plugins/{slug}/` (`settings.json`, `plugin.sqlite`) and audit results under `storage/cache/plugin-audits/`. Both must be **writable by the web server user** (`apache` on Fedora/RPM).

| Symptom | Likely cause |
|---------|----------------|
| `Failed to write plugin audit cache` on `plugin enable` | CLI ran as root or a non-`apache` user; cache dir or `.json` files owned by `root` |
| Admin plugin settings do not save / `settings.json` missing | `storage/plugins/{slug}/` created by root during `plugin enable` |
| HTTP 500 after enable | Broken plugin autoload (check PHP-FPM log); disable with `sudo latch plugin disable <slug>` |

**On RPM installs**, use the `/usr/bin/latch` wrapper (re-execs as `apache`):

```bash
sudo latch plugin install ./spam-bridge-1.0.2.zip
sudo latch plugin enable spam-bridge
```

Do **not** run `php bin/latch plugin …` as root or as a deploy user unless that user is `apache`. Tarball installs: `sudo -u apache php bin/latch plugin enable <slug>`.

When `plugin enable` runs as **root**, core chowns `storage/plugins/{slug}/` to the web user automatically (next core release). Audit cache and older root-owned trees may still need a one-time fix:

```bash
sudo chown -R apache:apache /var/lib/latch/storage/plugins /var/lib/latch/storage/cache/plugin-audits
sudo chmod 2775 /var/lib/latch/storage/plugins /var/lib/latch/storage/cache/plugin-audits
```

Or: `sudo bash scripts/fix-latch-storage-perms.sh /usr/share/latch` (RPM layout; follows the `storage` symlink to `/var/lib/latch/storage`).

`sudo latch audit` and `sudo latch doctor` report root-owned plugin storage or audit-cache entries. One-shot fix: `sudo latch fix-perms`.

**Admin enable/disable** runs as `apache` and does not hit these CLI permission issues. Prefer **Admin → Plugins** for enable after `plugin install`, or use `sudo latch plugin enable`.

## Catalog plugins (Latch-plugins)

Install from [Latch-plugins](https://github.com/YeOK/Latch-plugins) releases, then enable after audit. Reference and audit fixtures remain under `docs/plugins/` in core.

### Install policy

| Event | `enabled_plugins` behaviour |
|-------|----------------------------|
| **New install** | `[]` — plugins **disabled** until you audit and enable |
| **Upgrade** | Unchanged — operator choices are preserved; newly installed plugins are **not** auto-enabled |

Fresh sites run migration `028_plugins.sql` (`INSERT OR IGNORE` → empty array). Enable explicitly via **Admin → Plugins** or `php bin/latch plugin enable <slug>`.

### `forum-stats`

Home page totals — posts, topics, registered members via `home.after_boards`. Catalog plugin; `cache.guest_page: bake`, `invalidate_on: ["site"]`.

### `image-upload` (R2 / CDN post images)

**Insert image** toolbar button; presigned direct upload to **Cloudflare R2**; markdown `![](https://your-cdn/…)` in posts. R2 secrets in `config/local.php` → `plugins.image_upload`; upload limits in **Admin → Plugins → Image upload → Settings**. Uses `editor.compose`, `post.format.image_host`, `csp.img_src`, `csp.connect_src`, `post.before_save`.

### `word-filter` (profanity filter)

Blocks or masks profanity in post bodies and new topic titles via `post.before_save`. Ships `data/blocked-words.txt`; extend via admin settings / `storage/plugins/word-filter/settings.json`. Aho-Corasick matching; staff bypass by default.

### `spam-bridge` (Akismet / Stop Forum Spam)

External spam checks on posts (`post.before_save`) and registrations (`user.register`). Akismet key in `config/local.php` → `plugins.spam_bridge.akismet_api_key`; toggles in admin. Rejections log to `storage/plugins/spam-bridge/plugin.sqlite` (`spam_log`).

### `slack-notify` (Slack / Discord incoming webhook)

Team chat pings on new topics, replies, and optionally registrations via `post.after_save` and `user.register`. Webhook URL in `config/local.php` → `plugins.slack_notify.webhook_url`; event toggles in admin.

## Reference plugins (`docs/plugins/`)

Not auto-discovered until installed under `plugins/{slug}/`.

### `example` (good)

Reference at `docs/plugins/example/`:

- `GET /plugin/example` — JSON status when enabled
- Footer note via `layout.footer`

Try locally:

```bash
php bin/latch migrate   # applies 028_plugins.sql if needed
php bin/latch plugin install docs/plugins/example
php bin/latch plugin enable example
```

### `badexample` (audit failure fixture)

Reference at `docs/plugins/badexample/` — intentional `eval`, undeclared network, and forbidden path writes in `src/AuditTrap.php`. Copy to `plugins/` only when verifying audit UI and CLI (`plugin-audit badexample` must exit 1). **Never enable on production.**

### `warnexample` (audit warning fixture)

Reference at `docs/plugins/warnexample/` — intentional markup warnings in `src/WarnTrap.php` and JS warnings in `assets/warn.js`. Copy to `plugins/` to verify audit UI shows **warnings** separately from critical (`plugin-audit warnexample` exits 0 with warnings). Enable is allowed. **Never enable on production.**

### `dbexample` (plugin database)

Reference at `docs/plugins/dbexample/` — `"database": { "enabled": true }`, `migrations/001_event_log.sql`, and footer line reading row count via `$context->database()`. Use as a template for plugins with `spam_log` or other plugin-owned tables.

```bash
php bin/latch plugin install docs/plugins/dbexample
php bin/latch plugin enable dbexample
```

## Author checklist

1. Match `slug` to directory name; declare only hooks you use.
2. Never write outside plugin dir / declared `storage/plugins/{slug}/` without manifest permission.
3. Do not read `config/local.php` — use plugin settings in DB or declared config keys.
4. Run `plugin-audit` before publishing.
5. Prefer hooks over copying core files.

## Security audit

Static scanner — no plugin code is executed during the audit.

### When scans run

| Trigger | Behaviour |
|---------|-----------|
| **Admin → Plugins** | Uses cached report if plugin files unchanged; otherwise scans once and updates cache |
| **`cron daily`** | Re-scans all non-ignored plugins; prunes stale cache files |
| **`plugin-audit` / `plugin enable`** | Always forces a fresh scan and updates cache |
| **Ignored plugins** | Never scanned (see below) |

Cache path: `storage/cache/plugin-audits/{slug}.json`. Invalidation is automatic when the plugin tree fingerprint changes (file mtimes/sizes under the plugin dir, excluding `vendor/`).

```bash
php bin/latch plugin-audit plugins/forum-stats
php bin/latch plugin-audit forum-stats      # slug under plugins/
php bin/latch plugin-audit docs/plugins/example   # reference copy (not discovered until under plugins/)
php bin/latch plugin-audit /path/to/plugin
php bin/latch plugin-audit forum-stats --json   # machine-readable report
php bin/latch plugin audit forum-stats          # alias
```

Exit code **0** = pass (no critical findings). Exit code **1** = critical issues or invalid path.

### Ignoring seasonal plugins (CLI only)

For plugins you keep on disk but do not want discovered, audited, or enabled:

```bash
php bin/latch plugin ignore md-import    # writes "ignored": true to plugin.json, disables, clears cache
php bin/latch plugin list --all          # shows ignored plugins
php bin/latch plugin unignore md-import  # restore to normal discovery
```

Ignored plugins are hidden from **Admin → Plugins** and skipped by `cron daily` audits. Not available in the admin UI by design.

### What it checks

Scans all `.php` files and `.js` / `.mjs` assets under the plugin tree (excluding `vendor/`). Non-PHP assets other than JS are size-checked only.

| Severity | Examples |
|----------|----------|
| **Critical** | `eval`, shell functions (`exec`, `system`, …), dynamic `include` from `$_GET`/`$_POST`, outbound HTTP without `permissions.network`, writes to `config/local.php` or `storage/database/`, path traversal near file ops |
| **Warning** | PHP obfuscation (`base64_decode`, …), `vendor/` without `composer.lock`, oversized files (>512 KB), suspicious markup in PHP strings, suspicious patterns in JS assets |

Structural HTML in hooks (`<button>`, `<section>`, `<a href="/…">`) is expected and not flagged. Markup warnings target script tags, inline handlers, `javascript:` URLs, embeds, and similar.

Dangerous PHP tokens inside HTML strings can still trigger **critical** findings (e.g. `'<script>eval(x)</script>'` → `dangerous_eval` + `markup_script_tag`).

#### Markup warnings (`.php` files)

| Code | Description |
|------|-------------|
| `markup_script_tag` | `<script>` tag in PHP source |
| `markup_javascript_url` | `javascript:` URL scheme |
| `markup_inline_event_handler` | Inline event handler (`onclick=`, `onerror=`, …) |
| `markup_iframe` | `<iframe>` embedding |
| `markup_object_tag` | `<object>` embedding |
| `markup_embed_tag` | `<embed>` embedding |
| `markup_srcdoc` | `srcdoc=` attribute |
| `markup_meta_refresh` | `<meta http-equiv="refresh">` redirect |
| `markup_base_tag` | `<base>` tag (relative URL hijack) |
| `markup_data_html` | `data:text/html` URI |

#### JavaScript warnings (`.js` and `.mjs` files)

| Code | Description |
|------|-------------|
| `js_eval` | `eval()` call |
| `js_function_constructor` | `new Function()` dynamic code |
| `js_document_write` | `document.write()` |
| `js_inner_html` | `.innerHTML =` assignment |
| `js_outer_html` | `.outerHTML =` assignment |
| `js_insert_adjacent_html` | `.insertAdjacentHTML()` |
| `js_javascript_url` | `javascript:` URL in string |
| `js_inline_event_handler` | Inline event handler in HTML string built from JS |
| `js_document_cookie` | `document.cookie` access |
| `js_postmessage_wildcard` | `postMessage(…, '*')` wildcard target |
| `js_dynamic_import_external` | Dynamic `import()` of `http(s)://` URL |
| `js_external_script_injection` | `<script` injection via `createElement` / `innerHTML` (coarse) |
| `js_fetch_external` | `fetch('https://…')` absolute external URL literal |
| `js_xhr_external` | `XMLHttpRequest.open(…, 'https://…')` absolute external URL literal |

### `plugin.json` fields

| Field | Required | Notes |
|-------|----------|-------|
| `name`, `slug`, `version`, `min_latch_version`, `hooks` | Yes | `slug` must match directory name |
| `description` | No | Shown in admin plugin list |
| `settings_schema` | No | Admin-tunable options → `storage/plugins/{slug}/settings.json` |
| `secrets_schema` | No | Secrets in `config/local.php` only; admin shows configured yes/no |
| `permissions` | No | See below |
| `ignored` | No | **`true`** — CLI-only via `plugin ignore`; plugin acts as not installed |

### `settings_schema` (admin UI)

Plugins with a non-empty `settings_schema` (or `secrets_schema`) get **Settings** on `/admin/plugins` and a form at `/admin/plugins/{slug}/settings`. Core validates POST input against the manifest and writes `storage/plugins/{slug}/settings.json` only.

**Field types (v1):** `boolean`, `string`, `text`, `integer`, `select`, `multiselect`, `string_list`, `secret_ref` (display-only; links to `secrets_schema`).

Plugins read merged values via `PluginSettingsStore::forPlugin($manifest, $storageRoot)->all()`.

```json
"settings_schema": [
  { "key": "mode", "type": "select", "label": "Filter mode", "default": "block",
    "options": [{ "value": "block", "label": "Block post" }, { "value": "mask", "label": "Mask words" }] },
  { "key": "extra_words", "type": "string_list", "label": "Extra words", "default": [] }
]
```

### Manifest permissions

Declare capabilities your plugin uses:

```json
"permissions": {
  "filesystem": ["storage/plugins/my-plugin/"],
  "network": true,
  "config": ["my_plugin_setting_key"]
}
```

- **`network`** — set to `true` (or a non-empty array) if the plugin calls `curl`, `file_get_contents('https://…')`, etc.
- **`filesystem`** — extra writable paths beyond the plugin directory and `storage/plugins/{slug}/`

See also: `PLAN.md` § Plugin system, `docs/CLI.md`, `docs/THEMING.md`.