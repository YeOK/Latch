# Design: phpBB → Latch Import CLI

| Field | Value |
|-------|-------|
| **Status** | Proposed (plan only — no implementation in this phase) |
| **Authors** | Systems architecture (Phase 6) |
| **Date** | 2026-07-03 |
| **Scope** | `bin/latch import phpbb`, `app/Import/Phpbb/` |
| **Related** | PLAN.md Phase 6, `PostFormatter`, `docs/THEMING.md` post markup |

---

## Overview

Operators migrating from **phpBB** need a deterministic path into Latch **SQLite** without hand-editing SQL or running one-off scripts. Latch core team has **no production phpBB operator experience** — design and tests rely on **fixture bundles** (sanitized JSON) committed to the repo, built once from a disposable Docker phpBB install.

**Target source:** **phpBB 3.3.x** on **MySQL or MariaDB** — the current supported stable line and the version most forums in the wild run today. phpBB 3.2.x is legacy (security maintenance only); treat as a **secondary** reader path if a real dump demands it. phpBB 3.4+ is out of scope until stable.

```mermaid
flowchart LR
    A[phpBB 3.3 MySQL] --> B[export step]
    B --> C[latch-import-bundle.json]
    C --> D[bin/latch import phpbb]
    D --> E[Latch SQLite]
```

---

## Goals

1. **`bin/latch import phpbb`** — CLI with `--dry-run`, `--confirm`, `--json`, documented exit codes (same ergonomics as `migrate`, `restore`, `plugin-audit`).
2. **Two-phase pipeline** — optional **export** from live/dumped MySQL; repeatable **import** from a portable JSON bundle.
3. **Latch-native storage** — post bodies converted to markup `PostFormatter` already renders (`**bold**`, `[quote]`, `[code]`, `[url]`, lists, fenced code).
4. **Fixture-first testing** — PHPUnit on converter + importer without a permanent phpBB install.
5. **Import report** — counts, role mapping, BBCode warnings, skipped entities (attachments, bots).

## Non-Goals (v1)

| Item | Reason |
|------|--------|
| Import into non-empty DB (`--merge`) | Dangerous; v2 after empty-DB path proven |
| phpBB password hash port (phpass) | Force password reset; simpler and safer |
| Attachments, polls, DMs, avatars | Different storage/models; report + defer |
| Executing phpBB `bbcode_tpl` HTML at import | Fragile; convert to Latch markup or plain text |
| PostgreSQL phpBB installs | v1 MySQL/MariaDB only |
| Admin UI import wizard | CLI + docs for v1 |

---

## Architecture

Implementation under `app/Import/Phpbb/` — not a standalone root script.

| Class / module | Role |
|----------------|------|
| `BbcodeConverter` | phpBB BBCode → Latch raw markup |
| `PhpbbReader` | Read MySQL (export mode) or parse bundle JSON |
| `PhpbbImporter` | Map entities, write via repositories, `import_map` |
| `ImportReport` | Human + JSON summary (warnings, skips) |

**CLI shape:**

```bash
# Export (once, on old server or from mysqldump-connected host)
php bin/latch import phpbb --export --from-mysql='mysqli://user:pass@host/db' --out=forum-bundle.json

# Import (repeatable)
php bin/latch import phpbb --bundle=forum-bundle.json --dry-run
php bin/latch import phpbb --bundle=forum-bundle.json --confirm
```

Wire into `bin/latch` like other subcommands; document in `source/docs/CLI.md`.

---

## Entity mapping (v1)

| phpBB table | Latch | Notes |
|-------------|-------|-------|
| `phpbb_forums` | `boards` | Auto-slug from title or operator slug map in bundle meta |
| `phpbb_topics` | `topics` | `created_at`, `last_post_at`, locked, pinned |
| `phpbb_posts` | `posts` | First post = topic body; replies in order |
| `phpbb_users` | `users` | Skip bots (`user_type`); unusable random password |
| `phpbb_groups` + membership | `users.role` | Administrators → `admin`, moderators → `mod`, else `member` |
| — | `import_map` | `source='phpbb'`, `entity`, `source_id`, `target_id` |

**ID preservation:** Do not force phpBB IDs into Latch autoincrement. Store mapping in `import_map`. Optional `redirects.json` in bundle output (`old_topic_id` → `/topic/{new_id}`) for nginx/Apache rewrite rules.

**Write order:** preflight → users → boards → topics → posts (BBCode converted before INSERT). Transactions per board or per N topics. Post-import: `search-reindex`, cache clear.

### Deferred (v2+)

| phpBB | Latch | Notes |
|-------|-------|-------|
| Attachments | links / image-upload plugin | Paths + permissions |
| Polls | — | No Latch polls |
| Private messages | `dm_messages` | Different model |
| Topic tags | `tags` / `topic_tags` | Nice follow-on |
| Post edit history | `post_revisions` | v1: latest body only |
| Custom avatars | Gravatar note in report | Core = Gravatar + identicon |
| Forum permission matrix | `board_acl` | Defaults + manual admin tuning |

---

## BBCode conversion

phpBB stores **BBCode** (sometimes with HTML remnants). `BbcodeConverter` outputs text `PostFormatter` understands (see `source/docs/THEMING.md` § Post content markup).

### Built-in phpBB 3.3 → Latch

| phpBB | Latch storage |
|-------|----------------|
| `[b]…[/b]` | `**…**` |
| `[i]…[/i]` | `*…*` |
| `[u]…[/u]` | plain text (no underline in Latch) |
| `[quote="user"]…[/quote]` | same |
| `[code]…[/code]` | `[code]…[/code]` or fenced ` ``` ` |
| `[url=…]…[/url]`, `[url]…[/url]` | same |
| `[img]…[/img]` | HTTPS URL on its own line; else `[image blocked]` note |
| `[list]`, `[*]` | `- item` lines |
| `[size]`, `[color]`, `[font]` | strip tags, keep inner text |
| `[youtube]`, `[video]`, etc. | plain URL or `[url]` |

### Custom BBCode (`phpbb_bbcodes`)

Three-tier handling:

1. **Known custom tags** — `config/import-phpbb-bbcodes.php` or JSON beside bundle (`strategy`: `fenced`, `strip`, `latch_quote`, etc.).
2. **Export includes `phpbb_bbcodes`** — bundle lists what the source board used.
3. **Unknown tags** — strip wrapper, keep inner text; log warning in import report per post.

Do **not** evaluate phpBB HTML templates at import time.

### Validation

After conversion, optional dry render via `PostFormatter::format()` in tests; never persist raw HTML from phpBB.

---

## Passwords and staff

- phpBB **phpass** (`$H$…`) → **not ported**. Import users with random unusable password; migrated users use **Forgot password** (document in operator guide).
- phpBB admins/mods → Latch `admin` / `mod`; Latch **admin 2FA (TOTP)** still applies after first login.

---

## Testing without a live phpBB board

| Layer | Artifact | Purpose |
|-------|----------|---------|
| Unit | `tests/PhpbbBbcodeConverterTest.php` | String pairs: nested quote+code, broken tags, custom tags |
| Fixtures | `scripts/fixtures/phpbb/minimal-bundle.json`, `edge-case-bundle.json` | CI import integration |
| Integration | `tests/PhpbbImportTest.php` | Temp SQLite, assert counts + `import_map` + one rendered topic |
| Manual | Import bundle into local DB | Browser smoke (optional) |

**Fixture creation (once):**

1. Docker phpBB **3.3** + MySQL locally.
2. Seed forums, users, posts with intentional BBCode torture tests.
3. `bin/latch import phpbb --export --from-mysql=… --out=edge-case-bundle.json`
4. Sanitize emails/passwords; commit JSON; discard Docker stack.

---

## Operator workflow (production)

Requires **Phase 5** site lock + backup primitives.

```bash
php bin/latch lock on --message="Forum import"
php bin/latch backup

# On old server (once):
php bin/latch import phpbb --export --from-mysql='mysqli://…' --out=source-forum.json

# On Latch server:
php bin/latch import phpbb --bundle=source-forum.json --dry-run
php bin/latch import phpbb --bundle=source-forum.json --confirm
php bin/latch search-reindex
php bin/latch lock off
```

`--dry-run` prints counts, BBCode warnings, skipped attachments, role mapping — **no writes**.

---

## Rollout plan

**Gate:** Ship after Phase 5 OSS test gate is green (import touches production DB; operators need `db-check` / `restore` rollback path).

| PR | Title | Primary files |
|----|-------|---------------|
| **PR-1** | `BbcodeConverter` + fixture bundles + unit tests | `app/Import/Phpbb/BbcodeConverter.php`, `scripts/fixtures/phpbb/`, `tests/PhpbbBbcodeConverterTest.php` |
| **PR-2** | `PhpbbReader` + export mode | `PhpbbReader.php`, `bin/latch`, MySQL read + JSON schema |
| **PR-3** | `PhpbbImporter` + `import_map` + CLI import/dry-run | `PhpbbImporter.php`, migration for `import_map`, `tests/PhpbbImportTest.php` |
| **PR-4** | Custom BBCode config + import report + docs | `config/import-phpbb-bbcodes.php`, `docs/CLI.md`, operator guide |
| **PR-5** | *(optional)* tags, attachments, `redirects.json` | Based on real source forum needs |

**Suggested order:** PR-1 → PR-2 → PR-3 → PR-4. PR-5 optional.

**Test gate:** PR-1 unit tests green; PR-3 integration test imports `minimal-bundle.json` into temp DB with expected counts.

---

## Key decisions

1. **Target phpBB 3.3.x (MySQL/MariaDB)** — primary supported source; 3.2.x secondary reader only if needed.
2. **Empty DB only for v1** — no `--merge` into existing latch.network content.
3. **Bundle-based testing** — no dependency on operator phpBB experience or a permanent phpBB host.
4. **Password reset required** — do not port phpass hashes in v1.
5. **Store Latch markup, not HTML** — `PostFormatter` remains single render path.
6. **Phase 6, not Phase 3/5 optional** — import is a dedicated migration milestone after OSS hardening.

---

## Open questions (resolve during PR-1 fixtures)

| # | Question | Default if unknown |
|---|----------|-------------------|
| 1 | Old URL redirects needed? | Clean break; optional `redirects.json` in PR-5 |
| 2 | Which custom BBCodes on source forum? | Seed from `phpbb_bbcodes` in export; warn on unknown |
| 3 | Attachments must-have? | v1 skip with report |
| 4 | Import reputation / reaction counts? | v1 skip; recompute from imported posts if reputation enabled |

---

## References

| Path | Relevance |
|------|-----------|
| `source/app/Core/PostFormatter.php` | Target markup render rules |
| `source/docs/THEMING.md` | Post markup reference for themes |
| `source/bin/latch` | CLI entry pattern |
| `docs/design/db-recovery-and-update.md` | Site lock + backup before import |
| `PLAN.md` | Phase 6 checklist |