<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

use Latch\Models\SettingRepository;

/**
 * Discovers installed plugins and tracks which are enabled.
 */
final class PluginRegistry
{
    private const SETTING_KEY = 'enabled_plugins';

    public function __construct(
        private readonly string $pluginsPath,
        private readonly SettingRepository $settings,
    ) {
    }

    /**
     * @return list<PluginManifest>
     */
    public function discover(): array
    {
        return self::discoverInDirectory($this->pluginsPath);
    }

    /**
     * Filesystem scan only — no database required (CLI audit-before-enable).
     *
     * @return list<PluginManifest>
     */
    public static function discoverInDirectory(string $pluginsPath): array
    {
        if (!is_dir($pluginsPath)) {
            return [];
        }

        $manifests = [];
        foreach (scandir($pluginsPath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.gitkeep') {
                continue;
            }

            $dir = $pluginsPath . '/' . $entry;
            if (!is_dir($dir) || !is_file($dir . '/plugin.json')) {
                continue;
            }

            try {
                $manifests[] = PluginManifest::fromDirectory($dir);
            } catch (\Throwable) {
                continue;
            }
        }

        usort($manifests, static fn (PluginManifest $a, PluginManifest $b): int => strcmp($a->slug, $b->slug));

        return $manifests;
    }

    /**
     * @return list<string>
     */
    public function enabledSlugs(): array
    {
        $raw = $this->settings->get(self::SETTING_KEY, '[]') ?? '[]';
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $slugs = [];
        foreach ($decoded as $slug) {
            if (is_string($slug) && $slug !== '') {
                $slugs[] = $slug;
            }
        }

        return array_values(array_unique($slugs));
    }

    public function isEnabled(string $slug): bool
    {
        return in_array($slug, $this->enabledSlugs(), true);
    }

    /**
     * @param list<string> $slugs
     */
    public function setEnabledSlugs(array $slugs): void
    {
        $normalized = array_values(array_unique(array_filter(
            $slugs,
            static fn (mixed $slug): bool => is_string($slug) && $slug !== '',
        )));
        $this->settings->set(self::SETTING_KEY, json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<array{manifest: PluginManifest, enabled: bool}>
     */
    public function listWithStatus(): array
    {
        $enabled = array_fill_keys($this->enabledSlugs(), true);
        $rows = [];
        foreach ($this->discover() as $manifest) {
            $rows[] = [
                'manifest' => $manifest,
                'enabled' => isset($enabled[$manifest->slug]),
            ];
        }

        return $rows;
    }
}