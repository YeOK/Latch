# Bad example plugin (audit test fixture)

**Do not enable on production.** This plugin exists only to verify `plugin-audit` and `/admin/plugins` show failures correctly.

## Expected audit result

```bash
php bin/latch plugin-audit badexample
# exit 1 — critical findings in src/AuditTrap.php
```

Typical critical flags:

| Code | Why |
|------|-----|
| `dangerous_eval` | `eval()` in plugin code |
| `network_file_get_contents` | HTTP fetch without `permissions.network` |
| `forbidden_write_target` | Write to `config/local.php` |

Enable should be blocked in admin and via `php bin/latch plugin enable badexample` unless `--force`.

## Safe to keep installed

Files stay on disk disabled by default; the trap code is in `AuditTrap.php`, which `Plugin.php` never loads.