# Warn example plugin (audit test fixture)

**Do not enable on production.** This plugin exists only to verify `plugin-audit` and `/admin/plugins` show **warnings** correctly while still passing the enable gate.

## Expected audit result

```bash
php bin/latch plugin-audit warnexample
# exit 0 — passed with warnings in src/WarnTrap.php and assets/warn.js
```

Typical warning flags:

| Code | Source |
|------|--------|
| `markup_script_tag` | `<script>` in `src/WarnTrap.php` |
| `markup_inline_event_handler` | `onerror=` in `src/WarnTrap.php` |
| `markup_javascript_url` | `javascript:` URL in `src/WarnTrap.php` |
| `js_eval` | `eval()` in `assets/warn.js` |
| `js_document_cookie` | `document.cookie` in `assets/warn.js` |
| `js_fetch_external` | `fetch('https://…')` in `assets/warn.js` |

Enable is **allowed** (no critical findings). Use `badexample` to test blocked enable.

## Safe to keep installed

Files stay on disk disabled by default; trap code is not referenced from `Plugin.php`.