# Markdown import (operator)

**Private operator plugin** — not bundled in public releases. Publish docs to the forum without hand-copying into the composer.

## Enable

```bash
php bin/latch plugin-audit md-import
php bin/latch plugin enable md-import
```

## Use

1. **Admin → Import markdown** (sidebar; loads in the admin panel without a full page change).
2. Pick a board, upload a `.md` file (or paste markdown).
3. Optional title override; first `# Heading` or filename is used otherwise.
4. Submit — creates a topic (and extra reply posts if the file exceeds the post size limit).

Imported posts are rendered with GitHub-style formatting via `post-md-import` CSS.

## Front matter (optional)

```markdown
---
title: Release notes 0.3.1
tags: docs, release
---

# Release notes 0.3.1

Body...
```

## Images

If **image-upload** is enabled, markdown images must use your configured CDN host (same rule as normal posts). Relative image paths are not uploaded automatically.

## Long documents

Files larger than the post body limit (~64 KiB) are split on `##` headings into a topic plus follow-up replies.