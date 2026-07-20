# Hot-path query notes

Operator-focused query counts for pages that affect daily perceived speed. Lighthouse on staging is useful; **N+1 patterns on board/topic pages matter more** on production with real data.

## Request-level SQL reductions (2026-07)

| Change | Effect |
|--------|--------|
| **`SettingRepository` hydrate** | One `SELECT key, value FROM settings` per request (was one SELECT per `get()`) |
| **`Auth::user()` memo** | User + session checks once per request; `user_sessions.touch` once (was every `user()` / `isMod()` call) |
| **Revision counts batch** | Mods on topic view: one `GROUP BY post_id` (was `COUNT` per edited post) |
| **Home unread batch** | One unread-flags query for all recent topics across boards (was one per board) |
| **Topic cursor `LIMIT n+1`** | `has_more` / `has_earlier` from extra row (no separate existence queries) |

## Home (`/`)

| Query | Count (typical) | Notes |
|-------|-----------------|-------|
| Board list | 1 | `BoardRepository::all()` |
| Activity summaries | 1 | `TopicRepository::activitySummariesForBoards()` — grouped aggregate |
| Recent topics per board | **1** | `TopicRepository::recentTopicsForBoards()` — `ROW_NUMBER() OVER (PARTITION BY board_id …)` (was N+1 per board) |
| Unread flags (logged-in) | **1** | All recent topic ids in one `unreadFlagsForTopics()` |
| Settings | **1** | Full `settings` table hydrate |
| Plugin hooks | 0–2 | Per-request plugin collect |

**Before fix:** 1 + N boards for recent topics (e.g. 5 boards → 6 queries).  
**After fix:** 3 SQL round-trips for board data regardless of board count (+ 1 unread when logged in).

## Board (`/board/{slug}`)

| Query | Count (typical) | Notes |
|-------|-----------------|-------|
| Board lookup | 1 | By slug |
| Topic list | 1 | `listByBoard()` with author join + correlated post count |
| Topic count (pagination) | 1 | `countByBoard()` |
| Tags for page | 1 | `TagRepository::forTopics()` batch |
| Unread enrichment | 0–1 | Logged-in only; batch read state |

Guest cache eligible when: public board, default sort, no tag filter, `members_only` off.

## Topic (`/topic/{id}`)

| Query | Count (typical) | Notes |
|-------|-----------------|-------|
| Topic + board | 2 | `findById`, `findById` board |
| Approved-post gate | 0–1 | Guests / pending topics |
| Posts + authors | 1 | `listByTopic()` single query |
| Tags | 1 | `forTopic()` |
| Mark read | 1 write | Logged-in only |

## Measuring locally

```bash
php bin/latch benchmark --url=https://forum.example.com --iterations=10
```

For SQL profiling, enable SQLite trace in a dev copy or wrap `Database::pdo()` in a test harness. Production goal: **no per-row loops that hit SQL** on home and board list paths.

## SQLite tuning

Latch uses a single SQLite file with **WAL mode** and **foreign keys** on every connection. Additional PRAGMAs are configurable in `config/default.php` → `database.sqlite` (override in `config/local.php`):

| Key | Default | Effect |
|-----|---------|--------|
| `busy_timeout_ms` | `5000` | Wait on write lock before `SQLITE_BUSY` |
| `cache_size_kib` | `8192` | Page cache (8 MiB); `0` = SQLite default (~2 MiB) |
| `mmap_size` | `0` | Memory-mapped reads (bytes); `0` = disabled |

See [INSTALL.md](INSTALL.md#sqlite-tuning-optional) for override examples. Tuning is per-connection — no migration required.

**Planner stats:** `cron weekly` runs `ANALYZE` and `PRAGMA optimize`. **Integrity:** `db-check` and WAL-safe `backup` — see [SECURITY.md](SECURITY.md).

## Plugin audit cache

Plugin security scans are cached under `storage/cache/plugin-audits/{slug}.json` (invalidated when plugin files change). Admin **Plugins** reuses the cache; `cron daily` refreshes all non-ignored plugins. Ignored plugins (`plugin ignore`) are skipped entirely — see [PLUGINS.md](PLUGINS.md).

## Bulk topic moderation

Board bulk pin/lock/remove (`POST /mod/topics/bulk`) batches expensive side effects:

| Before (per topic) | After (per request) |
|--------------------|---------------------|
| 4 cache tag invalidations (incl. `site`) | One deduped flush at end |
| FTS remove/reindex per archived post | Deferred `removeTopic` once per source topic |
| Per-author notification | Skipped in bulk (audit log still records each topic) |

The board UI sends large selections in **chunks of 20 topics** with progress feedback (`board-mod-tools.js`), so a 80-topic delete becomes four short requests instead of one long freeze.

**Guidance:** pin/lock batches are cheap; bulk **remove** is heavier (mod-trash archive per post). For md-import teardown or 100+ topics, use chunked bulk remove or **Delete all mod trash** on Maintenance after archiving.

## Fragment cache (second layer)

Full guest pages are cached under `storage/cache/pages/`. **Fragments** cache reusable HTML slices under `storage/cache/fragments/` with the **same tag invalidation** (`board:{id}`, `site`, etc.).

| Fragment | Template | Tag(s) | Benefit |
|----------|----------|--------|---------|
| Home board panel | `partials/home_board_panel.html.twig` | `board:{id}`, `site` | Rebuild home after one board changes without re-rendering every panel |
| Board topic list | `partials/topic_list.html.twig` | `board:{id}`, `site` | Faster board page miss when list fragment still warm |

Fragments are **guest-only** (same gate as page cache). Logged-in views always render live HTML for unread badges and votes.

API: `Application::renderFragment()` — used from `HomeController` and `BoardController`.

Purge: `php bin/latch maintenance --clear-cache` removes pages **and** fragments.

## Large topics — cursor pagination

Topics with more than **`forum.topic_pagination_threshold`** posts (default **50**) paginate when sort is **Oldest first**:

| Setting | Default | Meaning |
|---------|---------|---------|
| `forum.posts_per_page` | `20` | Chunk size per page / AJAX load |
| `forum.topic_pagination_threshold` | `50` | Paginate only above this count |

Behaviour:

- First visit loads the first chunk chronologically.
- **Load more posts** fetches `GET /topic/{id}/posts?after={postId}` (JSON with HTML chunk).
- **Jump to latest posts** → `?latest=1#latest` shows the most recent chunk.
- Other sort modes (newest, top) still load all posts — acceptable for moderate thread sizes.

Override in `config/local.php` under `forum` if needed.

## SQLite scale guide

Latch targets **small to medium** self-hosted forums on a **single SQLite file** with WAL mode.

### Practical limits

| Dimension | Comfortable | Stretch | Notes |
|-----------|-------------|---------|-------|
| Concurrent readers | Hundreds | Thousands | WAL allows many readers; one writer at a time |
| Concurrent writers | 1–2 PHP-FPM workers | 3–4 short bursts | Long bulk jobs block other writes — see bulk moderation |
| Database size | &lt; 2 GiB | 5–10 GiB | Works, but backup/restore and `VACUUM` take longer |
| Posts per topic | Thousands | Tens of thousands | Use cursor pagination (above); avoid loading all posts |
| Topics per board | Tens of thousands | — | Board list is paginated (`topics_per_page`) |

### When writes queue up

Symptoms: `SQLITE_BUSY`, slow replies during bulk moderation or import.

Mitigations (in order):

1. Raise `database.sqlite.busy_timeout_ms` (default `5000`) in `local.php`.
2. Increase `cache_size_kib` (default `8192` = 8 MiB page cache).
3. Ensure `cron weekly` runs (`ANALYZE`, `PRAGMA optimize`).
4. Reduce PHP-FPM `pm.max_children` if many workers hammer the same DB file.
5. Move to a single writer process (queue) for heavy imports — operator workflow, not core.

### When to leave SQLite

Consider PostgreSQL or MySQL when **any** of these are true:

- Sustained **multiple concurrent writers** (busy forum, many mods, API bots posting).
- Database **&gt; 10 GiB** and backup windows hurt SLAs.
- You need **replication** or managed HA.

Latch has no built-in multi-DB driver today — migration would be a custom operator project.

### Backup and integrity

- Use `bin/latch backup` before upgrades (WAL-safe copy).
- `bin/latch db-check` after migrate.
- See [SECURITY.md](SECURITY.md) and [UPGRADE.md](UPGRADE.md).

## CDN / edge caching

Cloudflare (and similar) rules for static assets vs session routes: **[CDN.md](CDN.md)**.

## Related

- Guest page cache: Phase 1.5 (`Cache` tags on home/board/topic)
- Chrome Lighthouse: manual release check on guest home (`docs/TESTING.md`); dev baseline **100 / 100 / 100 / 100** (2026-07-05)
- Cron `ANALYZE`: weekly job keeps planner stats fresh