# Latch Default theme

`theme.json` is a **manifest for operators and packagers** (name, version, color-mode support). The PHP engine does not load it at runtime — assets are wired in `layouts/base.html.twig` and cache-busted via `Application::themeAssetStamp()`.