#!/usr/bin/env python3
# Copyright (c) 2026 Latch contributors
# SPDX-License-Identifier: MIT
"""Add MIT copyright headers to first-party Latch source files."""

from __future__ import annotations

import sys
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parents[1]

PHP_BLOCK = """/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */

"""

SHELL_LINES = (
    "# Copyright (c) 2026 Latch contributors\n"
    "# SPDX-License-Identifier: MIT\n"
)

JS_BLOCK = (
    "/**\n"
    " * Copyright (c) 2026 Latch contributors\n"
    " * SPDX-License-Identifier: MIT\n"
    " */\n\n"
)


def has_copyright(text: str) -> bool:
    return "SPDX-License-Identifier: MIT" in text or "Copyright (c) 2026 Latch contributors" in text


def add_php(text: str) -> str | None:
    if has_copyright(text):
        return None

    if text.startswith("#!"):
        shebang_end = text.find("\n") + 1
        if not text[shebang_end:].startswith("<?php"):
            return None
        after_php = shebang_end + len("<?php")
        if text[after_php : after_php + 1] != "\n":
            return None
        rest = text[after_php + 1 :]
        if rest.startswith("\ndeclare(strict_types=1);\n"):
            insert = shebang_end + len("<?php\n\ndeclare(strict_types=1);\n")
            return text[:insert] + "\n" + PHP_BLOCK + text[insert:]
        insert = shebang_end + len("<?php\n")
        return text[:insert] + "\n" + PHP_BLOCK + text[insert:]

    if text.startswith("<?php\n\ndeclare(strict_types=1);\n"):
        insert = len("<?php\n\ndeclare(strict_types=1);\n")
        return text[:insert] + "\n" + PHP_BLOCK + text[insert:]

    if text.startswith("<?php\n"):
        insert = len("<?php\n")
        return text[:insert] + "\n" + PHP_BLOCK + text[insert:]

    return None


def add_shell(text: str) -> str | None:
    if has_copyright(text):
        return None
    if not text.startswith("#!"):
        return None
    shebang_end = text.find("\n") + 1
    return text[:shebang_end] + SHELL_LINES + text[shebang_end:]


def add_js(text: str) -> str | None:
    if has_copyright(text):
        return None
    return JS_BLOCK + text


def iter_targets() -> list[Path]:
    paths: list[Path] = []

    for base in (
        REPO_ROOT / "source" / "app",
        REPO_ROOT / "source" / "tests",
        REPO_ROOT / "source" / "plugins",
        REPO_ROOT / "source" / "config",
        REPO_ROOT / "source" / "lang",
        REPO_ROOT / "source" / "public",
        REPO_ROOT / "source" / "docs" / "plugins",
        REPO_ROOT / "source" / "themes",
        REPO_ROOT / "source" / "bin",
    ):
        if not base.exists():
            continue
        for path in sorted(base.rglob("*")):
            if not path.is_file():
                continue
            if "vendor" in path.parts:
                continue
            if path.name == "local.php":
                continue
            if path.suffix == ".php":
                paths.append(path)

    bin_dir = REPO_ROOT / "source" / "bin"
    if bin_dir.is_dir():
        for path in sorted(bin_dir.iterdir()):
            if path.is_file() and (path.suffix == ".php" or path.name == "latch"):
                paths.append(path)

    scripts = REPO_ROOT / "scripts"
    if scripts.is_dir():
        for path in sorted(scripts.iterdir()):
            if path.is_file() and path.suffix in {".sh", ".php"} and path.name != "add-copyright-headers.py":
                paths.append(path)

    theme_assets = REPO_ROOT / "source" / "themes" / "default" / "assets"
    if theme_assets.is_dir():
        for path in sorted(theme_assets.rglob("*")):
            if not path.is_file():
                continue
            if path.name in {"highlight.min.js", "highlight.css"}:
                continue
            if path.suffix in {".js", ".css"}:
                paths.append(path)

    for assets_root in (
        REPO_ROOT / "source" / "plugins",
        REPO_ROOT / "source" / "docs" / "plugins",
    ):
        if not assets_root.is_dir():
            continue
        for plugin_assets in assets_root.rglob("assets/*"):
            if plugin_assets.is_file() and plugin_assets.suffix in {".js", ".css"}:
                paths.append(plugin_assets)

    return paths


def main() -> int:
    updated = 0
    skipped = 0

    for path in iter_targets():
        text = path.read_text(encoding="utf-8")
        new_text: str | None = None

        if path.suffix == ".php" or path.name == "latch":
            new_text = add_php(text)
        elif path.suffix == ".sh":
            new_text = add_shell(text)
        elif path.suffix in {".js", ".css"}:
            new_text = add_js(text)

        if new_text is None:
            skipped += 1
            continue

        path.write_text(new_text, encoding="utf-8")
        updated += 1
        print(path.relative_to(REPO_ROOT))

    print(f"\nUpdated {updated} files ({skipped} already had headers or unsupported).")
    return 0


if __name__ == "__main__":
    sys.exit(main())