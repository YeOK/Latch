# Outbound email

Latch sends plain-text email for **password reset**, **registration verification** (when enabled in Admin → Settings), **email change confirmation** (link sent to the new address after re-auth on profile), and optional **notification email copies** (replies, mentions, warnings, etc.).

Auth and account emails are sent **synchronously** on the request path. When **Queue notification emails** is enabled in Admin → Settings, notification copies are stored in `mail_queue` and drained by `cron hourly` (or `php bin/latch mail process`).

Mail is designed for self-hosting: transport and sender details are configurable without code changes.

## Configuration layers

Settings are resolved in this order (later wins):

1. **`config/default.php`** — defaults (`mail.enabled`, `mail.transport`, etc.)
2. **`config/local.php`** — server-specific paths and fallbacks (not in the admin UI)
3. **Admin → Settings → Email** — runtime overrides stored in the database

| Setting | DB key | Purpose |
|---------|--------|---------|
| Enable outbound email | `mail_enabled` | Master switch |
| Transport | `mail_transport` | `msmtp` or `mail` |
| From address | `mail_from_email` | Envelope / header sender |
| From name | `mail_from_name` | Display name in `From:` |
| msmtp config path | `mail_msmtp_config` | Optional override; auto-detect if empty |
| Queue notification emails | `mail_queue_enabled` | Off by default; enqueue notification copies for cron |
| Queue batch size | `mail_queue_batch_size` | Messages per hourly/manual drain (default 50) |
| Queue max attempts | `mail_queue_max_attempts` | Retries before a row is pruned (default 5) |

## Recommended: msmtp + SMTP relay

1. Install msmtp:

   ```bash
   sudo dnf install -y msmtp
   ```

2. Copy the example config:

   ```bash
   cp deploy/msmtp.conf.example deploy/msmtp.conf
   ```

3. Edit `deploy/msmtp.conf`:
   - Set `from` and `domain` to an address your relay accepts
   - Point `logfile` at a path writable by the web server (e.g. `storage/logs/msmtp.log`)
   - Configure `host` / `port` for your provider (Google Workspace SMTP relay, Mailgun, etc.)

4. Allow your server's **public IP** in the relay provider's allowlist.

5. Optionally set the path in `config/local.php`:

   ```php
   'mail' => [
       'transport' => 'msmtp',
       'msmtp_config' => '/var/www/latch/deploy/msmtp.conf',
       'from_email' => 'noreply@example.com',
       'from_name' => 'My Forum',
   ],
   ```

6. In **Admin → Settings**, set From email/name and enable outbound email.

### Auto-detected msmtp paths

If `mail_msmtp_config` is empty, Latch checks (first readable wins):

- Path from `config/local.php` → `mail.msmtp_config`
- `{install_root}/../deploy/msmtp.conf` (e.g. `/var/www/latch/deploy/msmtp.conf` when `source/` is the install root)

## Alternative: PHP mail()

Set transport to **PHP mail()** in admin settings. Requires a working local MTA (`sendmail`/`postfix`). Less predictable on shared hosting; msmtp is preferred.

## Verify delivery

On the server (as the web user so permissions match production):

```bash
cd /var/www/latch/source
sudo -u apache php bin/latch test-mail --to=you@example.com
```

The command prints transport status, then sends a test message. Failures are logged to `storage/logs/mail.log` (JSON lines).

msmtp also writes to the `logfile` in `msmtp.conf`.

## Security audit

`php bin/latch audit` warns if **Require email verification** is on but mail is not configured.

## Troubleshooting

| Symptom | Check |
|---------|--------|
| `msmtp config file not found` | Deploy `deploy/msmtp.conf`; set path in admin or `local.php` |
| `msmtp binary not found` | Install msmtp package |
| Relay rejects message | Provider allowlist, `from`/`domain` in msmtp.conf |
| Permission denied on log | `storage/logs/` writable by `apache` (see INSTALL.md) |
| Verification email never arrives | `storage/logs/mail.log`, `msmtp.log`; run `test-mail` |

Registration and password reset log `mail_send_failed` to the security log when delivery fails.