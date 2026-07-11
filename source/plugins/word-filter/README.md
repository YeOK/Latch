# Word filter

Blocks or masks profanity in post bodies and new topic titles via the `post.before_save` hook.

## Enable

```bash
php bin/latch plugin-audit word-filter
php bin/latch plugin enable word-filter
```

## Behaviour

- **Block** (default) — rejects the post with a generic message (the matched word is never revealed).
- **Mask** — replaces matched words in the **post body** with asterisks (topic titles still block only).
- **Staff bypass** — admins and moderators skip the filter by default.
- **Code samples** — fenced and inline code are ignored (same approach as `image-upload` BodyGuard).

Bundled words ship in `data/blocked-words.txt`. Configure in **Admin → Plugins → Word filter → Settings**, or edit `storage/plugins/word-filter/settings.json` directly.

## Performance

Matching uses an Aho-Corasick automaton over the blocked-word list — one pass over the post body regardless of list size.