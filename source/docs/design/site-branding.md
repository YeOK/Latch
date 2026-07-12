# Site branding (operator logo)

**Status:** v1 shipped (0.4.4.3); v2 shipped (0.4.4.4)

## Goal

Let operators customize header/footer logo without editing theme files. Works for `default`, `modern`, and future themes that use the shared `partials/brand.html.twig` contract.

## v1 scope (shipped 0.4.4.3)

| In | Out |
|----|-----|
| Admin upload SVG or PNG logo (max 512 KB) | Per-theme logos |
| Brand modes: `latch`, `custom`, `text_only` | R2/CDN hosting |
| `GET /branding/logo` | In-browser crop/editor |

## v2 scope (shipped 0.4.4.4)

| In | Out |
|----|-----|
| Favicon upload (`/branding/favicon`) | Dark favicon variant |
| OG image upload (`/branding/og`) — PNG/JPEG, max 1 MB | Per-page OG overrides |
| Dark mode logo (`/branding/logo-dark`) | `brand.render` hook |

## Settings (`settings` table)

| Key | Values |
|-----|--------|
| `brand_mode` | `latch` \| `custom` \| `text_only` |
| `brand_logo_ext` | `svg` \| `png` |
| `brand_logo_dark_ext` | `svg` \| `png` |
| `brand_favicon_ext` | `svg` \| `png` |
| `brand_og_ext` | `png` \| `jpg` |

**Default inference** (when `brand_mode` unset): site name `latch` (case-insensitive) → `latch`, else `custom`.

## Storage

```
storage/branding/
  logo.svg | logo.png
  logo-dark.svg | logo-dark.png
  favicon.svg | favicon.png
  og.png | og.jpg
```

Served at `/branding/{logo|logo-dark|favicon|og}?v={mtime}`.

## Security

- Admin-only upload; CSRF on settings form
- MIME sniff via `finfo`; PNG validated with `getimagesize`
- SVG rejected if contains `<script`, event handlers, `javascript:`, `<foreignObject`
- Path traversal blocked; only fixed filenames served

## Theme contract

Templates include `partials/brand.html.twig` only. Twig globals: `brand.mode`, `brand.logo_url`, `brand.show_mark`.

## PR plan

1. `SiteBranding` service + `/branding/logo` route + `storage/branding` in fix-perms
2. Unified brand partial + `site_icons` favicon when custom logo
3. Admin settings UI (mode + upload + remove)
4. `SiteBrandingTest` + smoke probe for `/branding/logo` 404 when empty