# Hot-path query notes

Operator-focused query counts for pages that affect daily perceived speed. Lighthouse on staging is useful; **N+1 patterns on board/topic pages matter more** on production with real data.

## Home (`/`)

| Query | Count (typical) | Notes |
|-------|-----------------|-------|
| Board list | 1 | `BoardRepository::all()` |
| Activity summaries | 1 | `TopicRepository::activitySummariesForBoards()` — grouped aggregate |
| Recent topics per board | **1** | `TopicRepository::recentTopicsForBoards()` — `ROW_NUMBER() OVER (PARTITION BY board_id …)` (was N+1 per board) |
| Plugin hooks / settings | 1–2 | Cached per request |

**Before fix:** 1 + N boards for recent topics (e.g. 5 boards → 6 queries).  
**After fix:** 3 SQL round-trips for board data regardless of board count.

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
php bin/latch benchmark --url=https://latch.network --iterations=10
```

For SQL profiling, enable SQLite trace in a dev copy or wrap `Database::pdo()` in a test harness. Production goal: **no per-row loops that hit SQL** on home and board list paths.

## Related

- Guest page cache: Phase 1.5 (`Cache` tags on home/board/topic)
- Phase 5: Lighthouse / Web Vitals budget in CI
- Cron `ANALYZE`: weekly job keeps planner stats fresh