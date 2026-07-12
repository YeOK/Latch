# Site branding (operator logo)

**Status:** v1 — locked for implementation (2026-07-12)

## Goal

Let operators customize header/footer logo without editing theme files. Works for `default`, `modern`, and future themes that use the shared `partials/brand.html.twig` contract.

## v1 scope

| In | Out |
|----|-----|
| Admin upload SVG or PNG (max 512 KB) | Favicon/OG upload (v2) |
| Brand modes: `latch`, `custom`, `text_only` | Per-theme logos |
| `GET /branding/logo` (versioned, cached) | R2/CDN hosting |
| Replace `site.name == 'latch'` hack | In-browser crop/editor |
| Guest cache bust on change | `brand.render` hook (v2) |

## Settings (`settings` table)

| Key | Values |
|-----|--------|
| `brand_mode` | `latch` \| `custom` \| `text_only` |
| `brand_logo_ext` | `svg` \| `png` (empty = no upload) |

**Default inference** (when `brand_mode` unset): site name `latch` (case-insensitive) → `latch`, else `custom`.

## Storage

```
storage/branding/logo.svg   # or logo.png
```

Served at `/branding/logo?v={mtime}` — same cache pattern as `/assets/*`.

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