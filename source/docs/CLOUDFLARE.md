# Cloudflare with Latch

This guide covers a practical **small-forum** setup: DNS + proxy (or Tunnel), TLS, real client IPs, Turnstile bot protection, and optional R2 images. It assumes a **Cloudflare Free** account is enough for most Latch communities; paid plans only matter if you outgrow Free limits or want enterprise features.

Official Cloudflare docs (start here for product limits and FAQs):

| Product | Docs |
|---------|------|
| Plans / Free overview | [Cloudflare plans](https://www.cloudflare.com/plans/) · [Plans FAQ](https://developers.cloudflare.com/fundamentals/concepts/accounts-and-plans/) |
| DNS / proxy (orange cloud) | [DNS records](https://developers.cloudflare.com/dns/manage-dns-records/) · [Proxy status](https://developers.cloudflare.com/dns/proxy-status/) |
| SSL/TLS modes | [SSL/TLS encryption modes](https://developers.cloudflare.com/ssl/origin-configuration/ssl-modes/) |
| Cloudflare Tunnel | [Cloudflare Tunnel](https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/) · [Get started](https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/get-started/) |
| Turnstile | [Turnstile](https://developers.cloudflare.com/turnstile/) · [Get started](https://developers.cloudflare.com/turnstile/get-started/) · [Plans / Free](https://developers.cloudflare.com/turnstile/plans/) |
| R2 (image plugin) | [R2](https://developers.cloudflare.com/r2/) |
| IP ranges (origin firewall) | [Cloudflare IP ranges](https://www.cloudflare.com/ips/) |

Latch-specific caching rules: **[CDN.md](CDN.md)**. IP trust and fail2ban: **[SECURITY.md](SECURITY.md#client-ip-behind-cloudflare)**.

---

## Free account for small sites

For a typical self-hosted Latch forum (dozens to a few thousand active members), **Free** usually covers:

- DNS + **proxied** (orange-cloud) traffic
- Shared SSL certificates
- DDoS / bot filtering at the edge (basic)
- **Turnstile** Free tier for signup/login widgets
- Optional **Cloudflare Tunnel** (Zero Trust free tier — check current [account limits](https://developers.cloudflare.com/cloudflare-one/account-limits/))

What Free does **not** replace:

- Your origin still needs backups, `encryption_key`, admin 2FA, and updates
- Heavy media hosting → use **R2** or another CDN for image-upload (not the forum PHP process)
- Edge HTML caching is optional and easy to get wrong — prefer caching **`/assets/*` only** until you know guest cache behaviour ([CDN.md](CDN.md))

Always confirm current Free limits on Cloudflare’s site; they change over time.

---

## Architecture choices

### A. DNS proxy (orange cloud) → public origin

```
Visitor → Cloudflare edge → your public IP:443/80 → Apache/nginx → Latch public/
```

**Use when:** you have a stable public IP (or port forwards) and can open HTTP/S on the origin.

1. Add the site in Cloudflare; set nameservers as instructed.
2. DNS **A/AAAA** (or **CNAME**) for `forum.example.com` → origin, **Proxied** (orange).
3. SSL/TLS mode: **Full (strict)** once the origin has a valid cert (Let’s Encrypt, etc.). Avoid **Flexible** (encrypts visitor→CF only; origin HTTP is spoofable and breaks cookie `Secure` assumptions).
4. **Firewall the origin** so only [Cloudflare IP ranges](https://www.cloudflare.com/ips/) can reach ports 80/443 (or only your tunnel — see B).

### B. Cloudflare Tunnel (recommended if no public ports)

```
Visitor → Cloudflare edge → cloudflared (on your host) → http://127.0.0.1:80 → Apache → Latch
```

**Use when:** home lab, CGNAT, no desire to expose the host on the internet, or you want Cloudflare as the only public entry.

High-level steps (details in [Tunnel docs](https://developers.cloudflare.com/cloudflare-one/networks/connectors/cloudflare-tunnel/)):

1. Create a Tunnel in Zero Trust / Cloudflare dashboard.
2. Install **`cloudflared`** on the Latch host; authenticate and run as a service.
3. Public hostname `forum.example.com` → service `http://127.0.0.1:80` (or the port Apache listens on **locally**).
4. Keep Apache bound to **localhost** (or a private interface) so nothing on the public internet can hit Latch except via the tunnel.
5. Site URL in Latch must be the public `https://…` URL (install `--url=` / `site.url` in `config/local.php`).

Tunnel traffic still presents as Cloudflare to Latch: you get `CF-Ray` / `CF-Connecting-IP` the same way as orange-cloud proxy when the zone is on Cloudflare.

---

## Latch `config/local.php`

### Trust Cloudflare client IPs (default on)

Latch trusts visitor IP from **`CF-Connecting-IP`** only when **`CF-Ray`** is present (spoof resistance). Default:

```php
'security' => [
    'trust_cloudflare' => true, // default in config/default.php
],
```

Disable only if the site is **not** behind Cloudflare:

```php
'security' => [
    'trust_cloudflare' => false,
],
```

HTTPS / secure cookies: with Cloudflare (and `CF-Ray`), Latch accepts forwarded HTTPS without enabling the generic `trust_forwarded_proto` flag. See [SECURITY.md](SECURITY.md#https-detection-behind-a-proxy).

**Check:** after a real login, `storage/logs/security.log` (or Admin → Logs → security) should show your **public** IP, not `127.0.0.1` / `::1`.

### Optional: Apache `mod_remoteip`

COPR installs `latch-remoteip.conf` so **access logs** also show client IPs. Rate limits and fail2ban already prefer `security.log` (which uses Latch’s IP logic). Details: [SECURITY.md](SECURITY.md#client-ip-behind-cloudflare).

---

## Turnstile (bot check)

Turnstile is free for typical forum volumes — create a widget in the dashboard: [Turnstile get started](https://developers.cloudflare.com/turnstile/get-started/).

### 1. Create a site

1. Cloudflare dashboard → **Turnstile** → **Add site**.
2. Hostname: your forum domain (e.g. `forum.example.com`).
3. Widget mode: **Managed** is fine for most forums.
4. Copy **Site key** and **Secret key**.

### 2. Put keys in `config/local.php` only

**Preferred (interactive, no browser):**

```bash
php bin/latch configure --section=turnstile   # or: sudo latch configure --section=turnstile
php bin/latch configure --show                # confirm set without printing secrets
```

Or edit `config/local.php` / `/etc/latch/local.php` by hand:

```php
'security' => [
    'encryption_key' => '…', // existing
    'turnstile_site_key' => '0x4AAAA…',   // public — used in the browser
    'turnstile_secret_key' => '0x4AAAA…', // secret — server verify only
],
```

Never put the secret in the database, plugin settings JSON, or git. Admin UI only shows whether keys are **configured**.

Same keys serve **registration** and **login** widgets.

### 3. Enable in Admin → Settings

| Control | Effect |
|---------|--------|
| **Require Cloudflare Turnstile on registration** | Signup form (default on once keys exist) |
| **Require Cloudflare Turnstile on login** | Sign-in form |
| **Security mode → High** | Forces login + registration Turnstile (when keys exist) and mandatory 2FA for moderators |

Without keys, Turnstile stays off even if toggles are checked.

### 4. CSP

Latch already allows Cloudflare challenges in CSP (`challenges.cloudflare.com` for scripts/frames). No extra CSP edit for stock Turnstile.

### 5. Verify

1. Log out → open `/register` or `/login` (with the matching toggle on).
2. Confirm the Turnstile widget renders.
3. Submit without solving → should fail; solve → proceed.
4. Broken keys: check site/secret pair, domain hostname match, and that `local.php` is the file the running PHP process loads.

---

## Security mode (related)

**Admin → Settings → Security mode**:

- **Standard** — admin 2FA always required; Turnstile/login/mod 2FA follow individual toggles.
- **High** — enforces login Turnstile, registration Turnstile (if keys present), and mandatory 2FA for moderators.

See release notes / Admin help text on the settings form.

---

## CDN / cache rules

Do **not** cache logged-in HTML or `/admin`, `/api`, `/login`, etc. Prefer edge-cache **`/assets/*`** with long TTL. Full expressions: **[CDN.md](CDN.md)**.

After deploys: purge Latch guest cache (`php bin/latch maintenance --clear-cache` or COPR update hook) and optionally purge Cloudflare `/assets/*`.

---

## Optional: R2 for image-upload

The **image-upload** plugin uses **presigned PUT** to Cloudflare R2 (or S3-compatible storage). Secrets stay in `local.php` under `plugins.image_upload` — see [PLUGINS.md](PLUGINS.md) and the plugin README. R2 is separate from Free “proxy only”; check [R2 pricing](https://developers.cloudflare.com/r2/pricing/).

---

## Checklist (small forum)

- [ ] Domain on Cloudflare Free; DNS for the forum hostname
- [ ] **Orange cloud** *or* **Tunnel** to origin (not both fighting for the same host without a plan)
- [ ] SSL/TLS **Full (strict)** if using public origin + origin cert
- [ ] Origin not open to the world (CF IPs only, or localhost + tunnel only)
- [ ] `site.url` is `https://your-public-host`
- [ ] `trust_cloudflare` left true; `security.log` shows real visitor IPs
- [ ] Turnstile site + secret in `local.php`; widget on register/login as desired
- [ ] Admin 2FA enabled; consider **Security mode → High** for public registration
- [ ] fail2ban on `security.log` (COPR ships filter/jail)
- [ ] Cache rules: bypass dynamic paths; cache `/assets/*` ([CDN.md](CDN.md))

---

## Related Latch docs

| Doc | Topic |
|-----|--------|
| [CDN.md](CDN.md) | Cache Rules expressions |
| [SECURITY.md](SECURITY.md) | Headers, IP trust, fail2ban, backups |
| [INSTALL.md](INSTALL.md) / [INSTALL-FEDORA.md](INSTALL-FEDORA.md) | Install paths, Apache |
| [PLUGINS.md](PLUGINS.md) | image-upload / R2 secrets |
| [EMAIL.md](EMAIL.md) | Outbound mail (independent of Cloudflare) |

When Cloudflare product UI moves, prefer the official developer docs links at the top of this page over screenshots in third-party blogs.
