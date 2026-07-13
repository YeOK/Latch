<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

use Latch\Core\Cache;
use Latch\Core\SecurityHeaders;

/**
 * Guest cache behaviour for plugin collect hooks (bake / fragment / client / bypass).
 */
final class PluginCacheCoordinator
{
    /**
     * Client-mode plugins hydrate these hooks in the browser; other hooks (e.g. theme.assets)
     * must still run so cached pages load CSS/JS and register routes.
     *
     * @var list<string>
     */
    private const CLIENT_PLACEHOLDER_HOOKS = [
        HookName::HOME_BEFORE_BOARDS,
        HookName::HOME_AFTER_BOARDS,
        HookName::LAYOUT_FOOTER,
        HookName::LAYOUT_HEAD,
    ];

    /** @var array<string, PluginManifest> */
    private array $manifestsBySlug = [];

    /**
     * @param list<PluginManifest> $enabledPlugins
     */
    public function __construct(
        array $enabledPlugins,
        private readonly HookRegistry $hooks,
    ) {
        foreach ($enabledPlugins as $manifest) {
            $this->manifestsBySlug[$manifest->slug] = $manifest;
        }
    }

    public function disablesGuestPageCache(): bool
    {
        foreach ($this->manifestsBySlug as $manifest) {
            if ($manifest->cacheConfig->isBypass()) {
                return true;
            }
        }

        return false;
    }

    public function hasClientModePlugins(): bool
    {
        foreach ($this->manifestsBySlug as $manifest) {
            if ($manifest->cacheConfig->isClient()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function invalidationTagsForContentChange(): array
    {
        $tags = [];
        foreach ($this->manifestsBySlug as $slug => $manifest) {
            if ($manifest->cacheConfig->invalidatesOnPlugin()) {
                $tags[] = Cache::tagPlugin($slug);
            }
        }

        return $tags;
    }

    /**
     * @return list<mixed>
     */
    public function collect(PluginCollectContext $app, string $hook): array
    {
        $results = [];
        foreach ($this->hooks->entries($hook) as $entry) {
            $result = $this->invokeEntry($app, $hook, $entry);
            if ($result === null || $result === '') {
                continue;
            }

            if (is_array($result)) {
                if (HookRegistry::isAssociativeMenuItem($result)) {
                    $results[] = $result;
                    continue;
                }

                foreach ($result as $item) {
                    if ($item !== null && $item !== '') {
                        $results[] = $item;
                    }
                }
            } else {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * @param array{priority: int, callback: callable, plugin_slug: ?string} $entry
     */
    private function invokeEntry(PluginCollectContext $app, string $hook, array $entry): mixed
    {
        $slug = $entry['plugin_slug'];
        $manifest = $slug !== null ? ($this->manifestsBySlug[$slug] ?? null) : null;
        $cache = $manifest?->cacheConfig ?? PluginCacheConfig::default();

        if ($cache->isClient() && $slug !== null && $this->hookUsesClientPlaceholder($hook)) {
            return $this->clientPlaceholder($slug, $cache);
        }

        $raw = ($entry['callback'])($app);

        if ($raw === null || $raw === '') {
            return $raw;
        }

        if ($cache->isFragment() && $slug !== null && $cache->fragmentHook === $hook) {
            return $this->cacheFragmentOutput($app, $slug, $hook, (string) $raw, $cache);
        }

        return $raw;
    }

    private function hookUsesClientPlaceholder(string $hook): bool
    {
        return in_array($hook, self::CLIENT_PLACEHOLDER_HOOKS, true);
    }

    private function clientPlaceholder(string $slug, PluginCacheConfig $cache): string
    {
        $route = $cache->clientRoute ?? '';
        if ($route === '') {
            return '';
        }

        return '<div class="plugin-client-slot" data-plugin-client="'
            . htmlspecialchars($slug, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" data-src="'
            . htmlspecialchars($route, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '"></div>';
    }

    private function cacheFragmentOutput(
        PluginCollectContext $app,
        string $slug,
        string $hook,
        string $html,
        PluginCacheConfig $cache,
    ): string {
        if (!$app->guestFragmentCacheEnabled()) {
            return $html;
        }

        $key = Cache::makeFragmentKey('plugin:' . $slug . ':' . $hook, ['_locale' => $app->resolvedLocale()]);
        $cached = $app->cache()->getFragment($key);
        if ($cached !== null) {
            return SecurityHeaders::rewriteHtmlNonces($cached, $app->cspNonce());
        }

        $tags = [Cache::tagPlugin($slug)];
        if ($cache->invalidatesOnSite()) {
            $tags[] = Cache::tagSite();
        }

        $app->cache()->setFragment($key, $html, $app->cacheTtlSeconds(), $tags);

        return $html;
    }
}