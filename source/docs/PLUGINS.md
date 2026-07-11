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
| `bundled` | no | **`true`** — ships in the core tarball; disabled on new installs (see [Install policy](#install-policy-bundled)) |
| `permissions.filesystem` | no | Writable paths (plugin dir or `storage/plugins/{slug}/`) |
| `permissions.network` | no | Declare if plugin performs outbound HTTP |
| `permissions.config` | no | Setting keys the plugin may read (not `local.php` secrets) |

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

### Plugin catalog (future)

**[github.com/YeOK/Latch-plugins](https://github.com/YeOK/Latch-plugins)** is the home for distributable plugins that ship outside the core tarball — sources, READMEs, and release `.zip` assets via GitHub Releases. The repo is live but empty until tier 1–2 catalog plugins are ready.

Until admin browser install lands, download a release zip (or clone a plugin tree) and run `plugin install` locally. Admin install from the catalog is planned (GitHub-only; audit gate unchanged).

**Bundled in core today:** `forum-stats`, `image-upload`, `word-filter` under `plugins/`.

After enabling, purge guest page cache / Twig compile if needed (`php bin/latch maintenance --clear-cache`).

**Admin UI:** `/admin/plugins` lists discovered plugins, audit status, and enable/disable (CSRF-protected). Enable is blocked until `plugin-audit` passes. Audit results are **cached** on disk (`storage/cache/plugin-audits/`); the admin page reuses the cache when plugin files are unchanged, otherwise scans once and refreshes the cache.

**Audit schedule:** `cron daily` re-scans all non-ignored plugins and updates the cache. Manual `plugin-audit` always forces a fresh scan.

**Ignored plugins (CLI only):** `php bin/latch plugin ignore <slug>` writes `"ignored": true` to `plugin.json`, disables the plugin, and removes it from discovery, admin UI, and audits. Use for seasonal plugins you want to keep on disk without maintenance overhead. Restore with `plugin unignore <slug>`. List ignored plugins: `plugin list --all`.

**Audit gate:** `plugin enable` (CLI and admin) runs a fresh audit first. Critical findings block enable unless CLI `--force` (logged to `audit_log` as `plugin.enable_forced`).

## Bundled plugins

**`forum-stats`**, **`image-upload`**, and **`word-filter`** ship under `plugins/` (manifest `"bundled": true`) and are discovered by `plugin list`. Reference and audit fixtures live under `docs/plugins/` — install with `php bin/latch plugin install docs/plugins/{slug}` when you want to try or test locally.

### Install policy (bundled)

| Event | `enabled_plugins` behaviour |
|-------|----------------------------|
| **New install** | `[]` — all bundled plugins **disabled** until you audit and enable |
| **Upgrade** | Unchanged — operator choices are preserved; new bundled plugins in the tarball are **not** auto-enabled |

Fresh sites run migration `028_plugins.sql` (`INSERT OR IGNORE` → empty array). Re-running migrations never overwrites an existing list. Enable explicitly via **Admin → Plugins** or `php bin/latch plugin enable <slug>`.

### `forum-stats` (distributable reference)

Bundled at `plugins/forum-stats/` — posts, topics, and registered member counts on the home page via `home.after_boards`. Copy the directory to distribute; enable after `plugin-audit` passes.

### `image-upload` (R2 / CDN post images)

Bundled at `plugins/image-upload/` — **Insert image** toolbar button; presigned direct upload to **Cloudflare R2**; markdown `![](https://your-cdn/…)` in posts. Credentials in `config/local.php` → `plugins.image_upload` (see `plugins/image-upload/README.md`). Uses `editor.compose`, `post.format.image_host`, `csp.img_src`, `csp.connect_src`, `post.before_save`.

### `word-filter` (profanity filter)

Bundled at `plugins/word-filter/` — blocks or masks profanity in post bodies and new topic titles via `post.before_save`. Ships a basic blocked-word list in `data/blocked-words.txt`; extend via `storage/plugins/word-filter/settings.json` (see `plugins/word-filter/README.md`). Uses Aho-Corasick matching; staff bypass by default.

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