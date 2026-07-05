<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

use InvalidArgumentException;

/**
 * Parsed plugin.json manifest.
 */
final class PluginManifest
{
    /**
     * @param list<string> $hooks
     * @param array<string, mixed> $permissions
     */
    public function __construct(
        public readonly string $name,
        public readonly string $slug,
        public readonly string $version,
        public readonly string $minLatchVersion,
        public readonly array $hooks,
        public readonly string $pluginDir,
        public readonly array $permissions = [],
        public readonly ?string $description = null,
        public readonly bool $ignored = false,
    ) {
    }

    public static function fromDirectory(string $pluginDir): self
    {
        $manifestPath = rtrim($pluginDir, '/') . '/plugin.json';
        if (!is_file($manifestPath)) {
            throw new InvalidArgumentException("Missing plugin.json in {$pluginDir}");
        }

        $raw = file_get_contents($manifestPath);
        $data = json_decode(is_string($raw) ? $raw : '', true);
        if (!is_array($data)) {
            throw new InvalidArgumentException("Invalid plugin.json in {$pluginDir}");
        }

        $slug = trim((string) ($data['slug'] ?? ''));
        $dirSlug = basename($pluginDir);
        if ($slug === '' || $slug !== $dirSlug) {
            throw new InvalidArgumentException("Manifest slug must match directory name: {$dirSlug}");
        }

        $name = trim((string) ($data['name'] ?? ''));
        $version = trim((string) ($data['version'] ?? ''));
        $minLatch = trim((string) ($data['min_latch_version'] ?? ''));
        if ($name === '' || $version === '' || $minLatch === '') {
            throw new InvalidArgumentException("Manifest {$slug} requires name, version, min_latch_version");
        }

        $hooks = $data['hooks'] ?? [];
        if (!is_array($hooks)) {
            throw new InvalidArgumentException("Manifest {$slug} hooks must be an array");
        }

        $hookList = [];
        $known = array_fill_keys(HookName::all(), true);
        foreach ($hooks as $hook) {
            if (!is_string($hook) || $hook === '') {
                continue;
            }
            if (!isset($known[$hook])) {
                throw new InvalidArgumentException("Unknown hook '{$hook}' in plugin {$slug}");
            }
            $hookList[] = $hook;
        }

        if ($hookList === []) {
            throw new InvalidArgumentException("Manifest {$slug} must declare at least one hook");
        }

        $permissions = is_array($data['permissions'] ?? null) ? $data['permissions'] : [];

        return new self(
            name: $name,
            slug: $slug,
            version: $version,
            minLatchVersion: $minLatch,
            hooks: array_values(array_unique($hookList)),
            pluginDir: $pluginDir,
            permissions: $permissions,
            description: isset($data['description']) ? trim((string) $data['description']) : null,
            ignored: (bool) ($data['ignored'] ?? false),
        );
    }

    public static function resolveDirectory(string $pluginsPath, string $slug): ?string
    {
        $slug = trim($slug);
        if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug)) {
            return null;
        }

        $dir = rtrim($pluginsPath, '/') . '/' . $slug;
        if (!is_dir($dir) || !is_file($dir . '/plugin.json')) {
            return null;
        }

        return realpath($dir) ?: $dir;
    }

    public function bootstrapClass(): string
    {
        return 'Latch\\Plugins\\' . self::studlySlug($this->slug) . '\\Plugin';
    }

    public function bootstrapFile(): string
    {
        return $this->pluginDir . '/src/Plugin.php';
    }

    public static function studlySlug(string $slug): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $slug)));
    }

    public function isCompatibleWith(string $latchVersion): bool
    {
        return version_compare($latchVersion, $this->minLatchVersion, '>=');
    }
}