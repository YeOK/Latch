# Latch theming guide

This document is for authors building or customizing Latch themes. The default theme (`themes/default/`) is the reference implementation.

**Goals:** fast first paint, stable layout, accessible controls, and markup that matches server-rendered posts.

---

## Theme layout

```
themes/
  my-theme/
    theme.json              ← manifest (name, version, assets)
    layouts/
      base.html.twig        ← site shell (required pattern)
      admin.html.twig       ← optional; extend base for /admin/*
    partials/               ← reusable fragments
    assets/
      css/theme.css         ← single primary stylesheet
      js/theme.js           ← global deferred script
      js/editor.js          ← compose pages only
      img/                  ← SVG-first; logo, icons, board tiles
```

### Activation

Set the active theme in `config/default.php` (or override in `config/local.php`):

```php
'theme' => [
    'active' => 'default',
    'asset_version' => '1',   // bump when CSS/JS changes ship
],
```

Twig resolves templates from `themes/{active}/` first, then falls back to `themes/default/`.

Static assets are served from `/assets/…` (mapped to `themes/{active}/assets/`). Do not reference files outside the theme `assets/` directory.

### `theme.json`

```json
{
    "name": "Latch Default",
    "version": "2.1.0",
    "author": "Latch",
    "description": "Modern default theme with light and dark palettes",
    "supports_color_modes": true,
    "assets": {
        "css": ["css/theme.css"],
        "js": ["js/theme.js", "js/editor.js"]
    }
}
```

| Field | Purpose |
|-------|---------|
| `supports_color_modes` | Theme defines light + dark tokens (required for bundled themes) |
| `assets.css` | Declarative list for future loader / plugin integration |
| `assets.js` | Global scripts; page-specific scripts belong in `{% block scripts %}` |

Phase 4 plugins may register extra assets via hooks. Until then, add scripts explicitly in Twig blocks.

---

## Layout contract

`layouts/base.html.twig` must provide:

| Piece | Requirement |
|-------|-------------|
| `<html data-theme="…" data-theme-mode="…">` | Color mode state |
| FOUC blocker | Small **nonce’d** inline script in `<head>` before CSS (see default theme) |
| `color-scheme` meta | `light dark` so form controls match palette |
| `csrf-token` meta | Required for AJAX (`staff-actions.js`, editor preview) |
| `theme.css` | One primary stylesheet with `?v={{ asset_version }}` |
| `theme.js` | Single global script, **`defer`**, versioned |
| `{% block scripts %}` | Page-specific deferred JS only |
| `csp_nonce` | All inline scripts must use `nonce="{{ csp_nonce }}"` |

Extend `base.html.twig`; do not duplicate the shell. Admin pages use `layouts/admin.html.twig`, which extends base and adds the sidebar.

### Shared template variables

Available in every view (from `Application::sharedViewData()`):

- `site`, `user`, `is_admin`, `is_mod`
- `theme_mode`, `theme_effective`
- `members_only`, `allow_registration`, `gdpr_enabled`
- `csrf_token`, `csp_nonce`, `asset_version`
- `locale`, `locale_dir` (`ltr` / `rtl`), `locale_catalog`
- `current_path`, `flash_error`, `flash_success`
- `report_queue` (mods only)

### Internationalization (i18n)

User-facing strings use PHP translation files in `lang/{locale}.php` (dot keys). In Twig:

```twig
{{ trans('nav.sign_in') }}
```

| Mechanism | Behaviour |
|-----------|-----------|
| Guest locale | `latch_locale` cookie or site default (`settings.default_locale`) |
| Member locale | `users.locale` column; profile form + `POST /profile/locale` |
| Quick switch | `POST /locale` (CSRF + `locale` field) sets cookie and redirects back |
| RTL | Arabic (`ar`) sets `dir="rtl"` on `<html>`; use logical CSS where possible |
| Fallback | Missing keys fall back to `lang/en.php` |

Supported catalog codes: `en`, `es`, `de`, `fr`, `ar` (see `Latch\Core\Locale`). **`ar` is partial** (falls back to English). Full template coverage, complete Arabic, and RTL layout polish are **Phase 7** (after public git) — see `PLAN.md`. Plugins can merge strings via the `locale.translations` filter hook — see `docs/PLUGINS.md`.

---

## Color modes (light / dark / system)

Use **semantic CSS custom properties** only — never hard-code `#fff` / `#000` on components.

```css
:root,
[data-theme="light"] {
    color-scheme: light;
    --bg: #f4f6f8;
    --surface: #ffffff;
    --text: #1a1f2e;
    --muted: #5c667a;
    --accent: #2f6fed;
    /* … */
}

[data-theme="dark"] {
    color-scheme: dark;
    --bg: #0f1419;
    --surface: #1a212b;
    /* … */
}
```

| Mode | Behaviour |
|------|-----------|
| `light` / `dark` | Fixed palette on `<html data-theme>` |
| `system` | `theme.js` follows `prefers-color-scheme` |
| Persistence | Logged-in: `users.theme_mode`; guests: `latch_theme` cookie + `localStorage` |

Third-party themes should set `supports_color_modes: true` in `theme.json` if both palettes are complete.

---

## Performance budgets

Server-side cache (Phase 1.5) does not replace a lean theme. Phase 5 runs Lighthouse on key URLs; design for these **default-theme targets**:

| Asset | Budget (uncompressed) | Default theme (Jul 2026) |
|-------|----------------------|--------------------------|
| Primary CSS | ≤ 110 KB | ~103 KB (`theme.css`) |
| Global JS (`theme.js`) | ≤ 12 KB | ~11 KB |
| Compose JS (`editor.js`) | ≤ 32 KB | ~25 KB |
| Syntax highlight (`highlight.min.js`) | ≤ 130 KB | ~125 KB (compose/read topic pages only) |
| Staff JS (`staff-actions.js`) | ≤ 20 KB | ~17 KB (admin/mod pages only) |
| Tags JS (`tags.js`) | ≤ 4 KB | ~2 KB (compose/mod pages only) |
| Webfonts | **0 KB** (default) | System stack only |
| Decorative PNG/JPG in chrome | **0** | SVG logo + board icons only |

Budgets are **uncompressed source size** (what you see on disk). Gzip is much smaller — e.g. `editor.js` ~4.5 KB, `highlight.min.js` ~42 KB over the wire.

**Total JS on a typical board page:** one deferred file (`theme.js`). Topic compose adds `editor.js` + `highlight.min.js`; mod/admin pages add `staff-actions.js` as needed. The editor is allowed a larger budget: it is the main authoring surface (live preview, markup toolbar, code blocks, reply flow).

### CSS rules

1. **One primary stylesheet** — no `@import` chains (blocks rendering).
2. **Design tokens** — variables for colors, radius, shadow; components reference tokens.
3. **`content-visibility: auto`** on post cards and long lists (see `.post` in default theme).
4. **Responsive width** — use `--content-max` / `--admin-content-max` with breakpoints (1200 → 1920px), not fixed pixel layouts.
5. **Avoid expensive selectors** — prefer classes over deep nesting.

### JavaScript rules

1. **`defer` on all theme scripts** — never block HTML parse.
2. **Load conditionally** — editor, tags, and staff scripts only on pages that need them (`{% block scripts %}`).
3. **No heavy frameworks** — vanilla JS; no React/Vue build step for core themes.
4. **highlight.js on compose pages only** — `highlight.min.js` + `highlight.css` loaded in `{% block scripts %}` on new-topic and reply pages; `editor.js` calls `hljs.highlightElement` after preview fetch.
5. **Same-origin scripts only** — CSP does not allow third-party script CDNs in core.
6. **Respect `prefers-reduced-motion`** — menus and toggles must work without animation.

### Images

| Type | Rule |
|------|------|
| Logo, icons, board tiles | **SVG** — `currentColor` where possible for dark mode |
| UI chrome | No decorative raster images |
| Avatars | Gravatar (when enabled) or identicon; see `partials/avatar.twig` — no arbitrary external URLs in core |
| Avatar `<img>` | `width`, `height`, `loading="lazy"`, `decoding="async"` |
| Identicon fallback | CSS gradient + initial letter (no image request) |

### Cache busting

Bump `theme.asset_version` in config when shipping CSS/JS changes. All theme URLs should append `?v={{ asset_version }}`.

---

## Icon controls

Use the shared icon partial for toolbar and moderation actions:

```twig
<button type="button" class="btn btn-small btn-icon" title="Search" aria-label="Search forum">
    {% include 'partials/icon.twig' with { name: 'search' } %}
</button>
```

Available icon names are defined in `partials/icon.twig` (search, edit, trash, flag, shield, ban, rss, etc.). Add new icons there rather than inlining one-off SVGs.

Staff/moderation actions use `staff-actions.js` with `.staff-action-panel` popovers — follow `admin/user_show.html.twig` or `admin/reports.html.twig` as examples.

---

## Post content markup

Posts are stored as **raw text** and rendered server-side by `Latch\Core\PostFormatter`. The Twig filter `format_post` wraps this for templates.

**Editor preview** (`POST /preview`) uses the same formatter. Theme CSS for `.post-content` must style the HTML structures below — do not assume different preview markup.

### Block types

| Input | Output |
|-------|--------|
| Paragraph (blank line separated) | `<p>…</p>` |
| Single newlines inside a paragraph | `<br>` via `nl2br` |
| `- item` list | `<ul><li>…</li></ul>` |
| ` ```lang … ``` ` or `[code]…[/code]` | `<pre class="code-block"><code>…</code></pre>` |
| `[quote="author"]…[/quote]` | `<blockquote class="post-quote"><cite class="quote-author">…</cite>…</blockquote>` |

### Inline markup

| Input | Output |
|-------|--------|
| `**bold**` | `<strong>` |
| `*italic*` | `<em>` |
| `` `code` `` | `<code class="inline-code">` |
| `[url=https://…]label[/url]` | `<a rel="nofollow ugc" target="_blank">` |
| `[url]https://…[/url]` | Auto-linked URL |
| `[https://…]` | Auto-linked URL |
| `:smile:` etc. | `<span class="smiley">` + emoji |

Links are **https only**. Style these classes in `theme.css`:

- `.post-content`, `.post-content p`, `.post-content ul`
- `.inline-code`, `.code-block`
- `.post-quote`, `.quote-author`
- `.smiley`

---

## Key partials (reuse, don’t fork)

| Partial | Purpose |
|---------|---------|
| `partials/avatar.twig` | Sized avatar with lazy load or identicon |
| `partials/icon.twig` | Inline SVG icons |
| `partials/topic_list.html.twig` | Board/tag/search topic rows |
| `partials/composer.twig` | Write/preview tabs |
| `partials/username_link.twig` | Links to `/user/{username}` |
| `partials/rss_link.twig` | RSS icon + tooltip |
| `partials/rss_bar.twig` | Context-aware RSS bar (included from base layout) |

---

## Board icons

Built-in board tiles live in `assets/img/board-icons/*.svg`. `BoardIconRegistry` maps slug/name to an icon key; admins can set `boards.icon_key` via the visual picker on `/admin/boards` (migration `009`).

Custom themes may ship their own SVG pack in the same directory structure. Plugins register icons via the `board.icons` hook and `BoardIconRegistry`.

---

## Accessibility checklist

- [ ] Icon-only buttons have `title` **and** `aria-label`
- [ ] Form inputs have associated `<label>` or `aria-label`
- [ ] Color contrast meets WCAG AA for text on `--surface` and `--bg`
- [ ] Focus states visible on links, buttons, and menu items
- [ ] Menus close on Escape (`theme.js` pattern)
- [ ] `prefers-reduced-motion` respected for animations
- [ ] Post quotes and code blocks readable in both color modes

---

## Performance checklist (before shipping a theme)

- [ ] Primary CSS ≤ 110 KB uncompressed
- [ ] No `@import` in CSS
- [ ] Global JS ≤ 12 KB; `editor.js` ≤ 32 KB; `highlight.min.js` compose-only
- [ ] All scripts use `defer` and `?v={{ asset_version }}`
- [ ] No webfonts unless justified (document download size)
- [ ] No raster images in header, footer, or buttons
- [ ] Avatars use explicit dimensions + `loading="lazy"`
- [ ] Long topic lists use `content-visibility` or equivalent
- [ ] Post markup styles match `PostFormatter` output
- [ ] Light and dark palettes tested on home, board, topic, profile, admin
- [ ] `theme.asset_version` bumped in config for this release
- [ ] No inline scripts except nonce’d FOUC blocker in `<head>`

---

## Testing your theme

1. **Visual** — home, board list, topic (long thread), compose, search, profile, admin users, reports queue.
2. **Color modes** — light, dark, and system; toggle while on page.
3. **Mobile** — sticky header, collapsible search, user menu, reply panel.
4. **Guest cache** — with `members_only` off, confirm board pages return cacheable HTML for guests (no personalised content in cached output).
5. **Phase 5 (later)** — `bin/latch test --perf` / Lighthouse CI on `/`, `/board/{slug}`, `/topic/{id}`.

---

## Anti-patterns

| Avoid | Why |
|-------|-----|
| jQuery / Bootstrap CDN | CSP, weight, unused CSS |
| Multiple CSS files on every page | Extra round trips |
| Inline `<style>` blocks | CSP; hard to cache |
| Non-deferred `<script>` in `<head>` | Blocks LCP |
| Uploading avatars to `storage/` | Out of scope |
| Custom avatar HTTPS URLs | Phase 4 plugin (`avatar.resolve`); core ships Gravatar + identicon only |
| Custom post HTML in templates | XSS risk; breaks preview parity |
| `parts[N]` in Twig without `\|default` | Strict variables throw 500 (see `rss_bar.twig`) |

---

## Related docs

- `INSTALL.md` — deployment and paths
- `SECURITY.md` — CSP, cookies, auth
- `../../PLAN.md` — roadmap and UI performance design notes