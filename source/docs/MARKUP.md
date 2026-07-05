# Post markup reference

Latch stores posts as **plain text** in the database. The server renders them to safe HTML with `Latch\Core\PostFormatter` on every view (and in the compose **Preview** tab via `POST /preview`).

This document is the **author and integrator** reference. Theme authors styling `.post-content` should also read [THEMING.md](THEMING.md) § Post content markup.

> **Operator note — not published yet**  
> This file exists in the git tree under `source/docs/MARKUP.md` but is **not** on the latch.network Documentation board or in a public release announcement. Publish when ready:
>
> ```bash
> # After adding an entry to deploy/forum-data/documentation-posts.json (operator tree)
> php scripts/post-documentation.php deploy/forum-data/documentation-posts.json
> ```
>
> Suggested board topic title: **Post markup**.

---

## Design principles

- **Write markup, not HTML** — raw `<script>`, `<img>`, and arbitrary tags are escaped or stripped.
- **One formatter** — topic view, RSS excerpts, search snippets, previews, and imports (e.g. phpBB) all target the same rules.
- **HTTPS links** — external URLs must use `https://` (or safe relative paths starting with `/`).
- **Images are gated** — markdown images `![alt](url)` only render when the host is allowed (core: none by default; `image-upload` plugin adds CDN hosts).

---

## Quick examples

````markdown
**Bold** and *italic* and `inline code`.

Visit [Latch](https://latch.network) or [url=https://latch.network]Latch[/url].

[quote author="alice"]
Previous message here.
[/quote]

```php
echo "Hello";
```

- first item
- second item

:smile: Thanks @alice for the help!
````

---

## Block elements

Separate blocks with a **blank line** (double newline). A single newline inside a paragraph becomes `<br>`.

| Syntax | Rendered as |
|--------|-------------|
| Plain paragraph | `<p>…</p>` |
| `- item` (one or more lines) | `<ul><li>…</li></ul>` |
| ` ```lang` … ` ``` ` fenced code | `<pre class="code-block"><code class="language-lang">…</code></pre>` |
| `[code]…[/code]` | Same as fenced code (optional `=` language suffix) |
| `[quote]…[/quote]` | `<blockquote class="post-quote">…</blockquote>` |
| `[quote author="name"]…[/quote]` | Quote with `<cite class="quote-author">` |
| `[quote="name"]…[/quote]` | Same as `author=` |
| Line starting with `> ` | Treated as quote line (email-style) |
| `# Heading` / `##` / `###` | `<h2>` / `<h3>` / `<h4 class="post-heading">` (single-line only) |
| Markdown table (`\| col \| …`) | `<table class="post-table">` inside `.post-table-wrap` |
| `[spoiler]hidden[/spoiler]` | `<details class="post-spoiler">` |
| `[spoiler="Label"]hidden[/spoiler]` | Spoiler with custom summary |

### Code blocks

Fenced form (preferred in the editor toolbar):

````markdown
```sql
SELECT 1;
```
````

BBCode-style (common in imports):

```markdown
[code]
SELECT 1;
[/code]
```

Citation-style fences (line numbers from docs) are also accepted:

````markdown
```12:15:app/Core/PostFormatter.php
// snippet
```
````

### Quotes

Multi-line block form:

```markdown
[quote author="alice"]
Line one
Line two
[/quote]
```

Quotes can contain other inline markup; nested formatting is rendered inside the blockquote.

---

## Inline elements

| Syntax | Output |
|--------|--------|
| `**text**` | **Bold** (`<strong>`) |
| `*text*` | *Italic* (`<em>`) |
| `` `text` `` | `<code class="inline-code">` |
| `[label](https://example.com)` | Markdown link (`rel="nofollow ugc"`, `target="_blank"` for external) |
| `[url=https://…]label[/url]` | BBCode-style external link |
| `[url]https://…[/url]` | Auto-linked URL as label |
| `[https://…]` | Shorthand auto-link |
| `[label](/board/general)` | Relative link (same site, no `target="_blank"`) |
| `![alt](https://host/image.png)` | Image **only if** host is allowlisted; else `[image blocked]` |
| `@username` | Link to `/user/username` (`<a class="mention">`) when username is 3–32 chars |
| `:smile:` etc. | Emoji via `<span class="smiley">` (see table below) |
| `[spoiler]text[/spoiler]` | Inline spoiler (`post-spoiler-inline`) |

### Smileys

Typed shortcodes (picker order in the editor):

| Code | Emoji |
|------|-------|
| `:smile:` | 😊 |
| `:thumbsup:` | 👍 |
| `:laugh:` | 😂 |
| `:rofl:` | 🤣 |
| `:heart:` | ❤️ |
| `:fire:` | 🔥 |
| `:wink:` | 😉 |
| `:thinking:` | 🤔 |
| `:sad:` | 😢 |
| `:cool:` | 😎 |
| `:eyes:` | 👀 |
| `:clap:` | 👏 |
| `:party:` | 🎉 |
| `:100:` | 💯 |
| `:skull:` | 💀 |
| `:sparkles:` | ✨ |

### Mentions

`@alice` links when `alice` matches username rules (not inside email addresses). Used for notifications when the user exists.

---

## What is not allowed

| Input | Behaviour |
|-------|-----------|
| Raw HTML `<b>`, `<script>`, etc. | Escaped — shown as literal text |
| `http://` links (non-TLS) | Not auto-linked as external URLs |
| `javascript:` URLs | Stripped / not linked |
| Arbitrary `![alt](url)` images | Blocked unless host allowlist permits (plugin) |
| `//evil.com` paths | Rejected |

User-supplied content is HTML-escaped before inline formatting is applied. Links and images go through explicit allow rules.

---

## Special markers (operator / system)

| Marker | Purpose |
|--------|---------|
| `<!-- latch-md-import -->` at post start | Wraps rendered output in `<div class="post-md-import">` (md-import plugin) |
| `<!-- latch-announcement:YYYY-MM-DD-slug -->` | Stripped from plain-text excerpts; used for changelog-style topics |

Do not add these manually unless you operate imports or announcement scripts.

---

## Storage vs display

| Layer | Format |
|-------|--------|
| Database `posts.body` | Raw markup string |
| Topic HTML | `PostFormatter::format()` → Twig `{{ post.body\|format_post }}` |
| Plain excerpts (RSS, search) | `PostFormatter::plainText()` — markup stripped |
| API read endpoints | Raw body in JSON; clients must not treat as HTML |

---

## Imports and other sources

**phpBB import** (`bin/latch import phpbb`) converts BBCode to this markup before insert — see [CLI.md](CLI.md) § import phpbb. Examples:

| phpBB | Latch storage |
|-------|----------------|
| `[b]…[/b]` | `**…**` |
| `[i]…[/i]` | `*…*` |
| `[quote="user"]…[/quote]` | `[quote author="user"]…[/quote]` |
| `[code]…[/code]` | `[code]…[/code]` or fenced `` ``` `` |
| `[url=…]…[/url]` | `[url=…]…[/url]` |
| `[list][*]a[*]b[/list]` | `- a` / `- b` bullet lines |

---

## For theme and plugin authors

- Style output classes in `theme.css` — see [THEMING.md](THEMING.md).
- Plugins may extend image hosts via `post.format.image_host` and CSP `img-src` hooks (`image-upload`).
- Plugins may adjust body before save via `post.before_save`; rejected bodies return a flash error.

---

## Implementation reference

Source of truth: `app/Core/PostFormatter.php`  
Tests: `tests/PostFormatterTest.php`, `tests/PhpbbBbcodeConverterTest.php`

When this doc and the formatter disagree, **the formatter wins** — please file a doc fix or a formatter bug.