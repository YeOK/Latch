# Docker (homelab / demo)

One-command Latch for a laptop or homelab. **Fedora production installs stay on COPR** (`dnf install latch`) — this image is the try-out path, not a replacement for the RPM.

Published images live on GitHub Container Registry: **[ghcr.io/yeok/latch](https://github.com/YeOK/Latch/pkgs/container/latch)** (`linux/amd64` and `linux/arm64`). TLS is not in the image. Put Caddy, Traefik, nginx, or Cloudflare Tunnel in front if you need HTTPS. See [CLOUDFLARE.md](CLOUDFLARE.md).

## Quick start (published image)

```bash
docker pull ghcr.io/yeok/latch:0.5.6.0
```

With a checkout of [YeOK/Latch](https://github.com/YeOK/Latch) (or a copy of `docker-compose.yml`):

```bash
# Optional: pin a version; default matches VERSION in the tree
# export LATCH_IMAGE_TAG=0.5.6.0
docker compose pull
docker compose up -d
```

Then open **http://localhost:8080**. `latest` tracks the newest tagged release.

```bash
docker pull ghcr.io/yeok/latch:latest
```

## Quick start (build from git)

From a git checkout, if you want to build locally instead of pulling:

```bash
docker compose up --build
```

On first boot the entrypoint runs `php bin/latch install`. If you do not set `LATCH_ADMIN_PASS`, a random password is printed in the `latch` container logs (and written to `/persist/config/docker-bootstrap.secret` on the volume). Sign in as `admin` and enrol TOTP.

```bash
# Optional: pin the founder password
export LATCH_ADMIN_PASS='choose-a-long-password'
docker compose up -d
```

Stop with `Ctrl+C` or `docker compose down`. Named volumes keep the SQLite database and `config/local.php`. `docker compose down -v` wipes the forum.

## What you get

| Piece | Role |
|-------|------|
| `latch` | PHP 8.2 + Apache, `DocumentRoot` = `source/public` |
| `latch-cron` | Same image; hourly / daily / weekly `bin/latch cron` |
| Volume `latch-storage` | `source/storage` (SQLite, logs, backups, cache, plugin data) |
| Volume `latch-config` | Persisted `local.php` (URL, `encryption_key`, secrets) |
| Healthcheck | `GET /health` |

Composer dependencies are baked in at build time (`composer install --no-dev`). Tests, operator rsync scripts, and design notes stay out of the image.

## Environment

| Variable | Default | Purpose |
|----------|---------|---------|
| `LATCH_HTTP_PORT` | `8080` | Host port published to Apache :80 |
| `LATCH_URL` | `http://localhost:8080` | Public site URL written to `local.php` (must match how you browse) |
| `LATCH_NAME` | `Latch` | Forum name |
| `LATCH_ADMIN_USER` | `admin` | Founder username (first run only) |
| `LATCH_ADMIN_EMAIL` | `admin@localhost` | Founder email |
| `LATCH_ADMIN_PASS` | *(generated)* | Founder password, min 8 characters |
| `LATCH_ENCRYPTION_KEY` | *(generated)* | Optional stable TOTP key; leave empty on first run |
| `LATCH_TRUST_CLOUDFLARE` | off | Set `1` only when the container is reached solely through Cloudflare (Tunnel or authenticating proxy). Direct `:8080` must stay off so clients cannot spoof `CF-Connecting-IP`. |
| `LATCH_IMAGE_TAG` | tree `VERSION` | GHCR tag to pull (`0.5.6.0`, `latest`, …). Ignored when you `docker compose up --build`. |

A generated founder password is printed once and stored at `/persist/config/docker-bootstrap.secret` (outside DocumentRoot), not in `storage/logs/`.

Put these in a `.env` next to `docker-compose.yml` if you want them to stick. After the first install, URL/name changes belong in `config/local.php` (`docker compose exec latch php bin/latch configure`) — the entrypoint will not re-run `install` while the database exists.

If you change the published port, set **both** `LATCH_HTTP_PORT` and `LATCH_URL` before the first boot.

## Upgrades

Published image:

```bash
export LATCH_IMAGE_TAG=0.5.6.0   # or latest
docker compose pull
docker compose up -d
```

Build from git:

```bash
git pull
docker compose build
docker compose up -d
```

The entrypoint runs `php bin/latch migrate` on start. Storage and `local.php` stay in the volumes. For a lock → backup → migrate rehearsal, exec into the app container:

```bash
docker compose exec latch php bin/latch lock on --message="update"
docker compose exec latch php bin/latch backup
docker compose exec latch php bin/latch update --skip-lock --skip-backup --assume-files-ready
docker compose exec latch php bin/latch lock off
```

See [UPGRADE.md](UPGRADE.md).

## Bind mounts (optional)

Named volumes are the default. To keep data in the git checkout:

```yaml
volumes:
  - ./data/storage:/var/www/latch/source/storage
  - ./data/config:/persist/config
```

Create the directories on the host first so Docker does not turn a missing path into a directory-by-mistake. `./data/` is gitignored.

## Reverse proxy / TLS

Point the proxy at `127.0.0.1:8080` (or the compose published port). Set `LATCH_URL` to the public `https://…` URL **before first install**, or run `php bin/latch configure` afterwards.

Behind a TLS terminator that sets `X-Forwarded-Proto`, add to persisted `local.php`:

```php
'security' => [
    'trust_forwarded_proto' => true,
],
```

Only do that when the container is not reachable directly from the internet.

## Not in this image

- Fedora COPR / `dnf install latch` (production path — [INSTALL-FEDORA.md](INSTALL-FEDORA.md))
- fail2ban (host jail, or your proxy’s WAF)
- Packagist `create-project`
- Operator plugins (`git-release`, `md-import`) — install from the [Latch-plugins](https://github.com/YeOK/Latch-plugins) catalog after boot

## Image tags

| Tag | Meaning |
|-----|---------|
| `ghcr.io/yeok/latch:0.5.6.0` | Version matching a git tag / GitHub Release |
| `ghcr.io/yeok/latch:latest` | Newest tagged release |

Images are built by `.github/workflows/docker.yml` on `v*` tags (and can be dispatched by hand). Compose defaults to the tree `VERSION` via `LATCH_IMAGE_TAG`.
