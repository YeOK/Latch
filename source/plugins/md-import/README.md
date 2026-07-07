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

HTML `<img>` / `<picture>` tags and markdown images on other hosts are converted during import to a placeholder:

`![description (replace image)](https://your-cdn/.md-import/REPLACE-ME.png)`

The placeholder uses your **image-upload** CDN host when configured, so the import passes validation and renders as a broken image you can swap via **Insert image** in the editor. Relative paths and external URLs are not uploaded automatically.

## Long documents

Files larger than the post body limit (~64 KiB) are split on `##` headings into a topic plus follow-up replies.