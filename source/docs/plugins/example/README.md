# Example plugin

Reference plugin for Latch Phase 4. Lives under `docs/plugins/` — not auto-discovered. Copy to `plugins/example/` to try it.

## Enable (operator)

```bash
cp -a docs/plugins/example plugins/example

# List discovered plugins
php bin/latch plugin list

# Enable (writes settings.enabled_plugins JSON)
php bin/latch plugin enable example
```

Or enable in the admin UI at `/admin/plugins` (audit must pass first):

```sql
UPDATE settings SET value = '["example"]' WHERE key = 'enabled_plugins';
```

Then clear Twig/page cache if needed.

## Hooks used

| Hook | Behaviour |
|------|-----------|
| `route.register` | `GET /plugin/example` JSON status |
| `layout.footer` | Small footer note with link |

`bootstrap` is declared in the manifest for compatibility; init work can be added later.