# Forum stats plugin

Reference plugin for Latch — shows public forum totals on the **home page** (after the board list, before the site footer).

## Stats shown

| Stat | Source |
|------|--------|
| Posts | Non-deleted posts |
| Topics | Non-deleted topics |
| Registered members | All user accounts |

## Enable

```bash
php bin/latch plugin-audit forum-stats
sudo -u apache php bin/latch plugin enable forum-stats
sudo -u apache php bin/latch maintenance --clear-cache
```

Or use **Admin → Plugins** after audit passes.

## Hook

Uses `home.after_boards` — HTML is injected at the bottom of the board list on `/` only.

## Distribute

Copy the `forum-stats/` directory into `plugins/` on any Latch 0.3.0+ site. No extra dependencies.