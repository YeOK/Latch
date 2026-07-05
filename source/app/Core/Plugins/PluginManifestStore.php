<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

use InvalidArgumentException;
use RuntimeException;

/**
 * Read/write plugin.json (CLI maintenance — e.g. ignored flag).
 */
final class PluginManifestStore
{
    /**
     * @return array<string, mixed>
     */
    public static function read(string $pluginDir): array
    {
        $path = self::manifestPath($pluginDir);
        $raw = file_get_contents($path);
        $data = json_decode(is_string($raw) ? $raw : '', true);
        if (!is_array($data)) {
            throw new InvalidArgumentException('Invalid plugin.json in ' . $pluginDir);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function write(string $pluginDir, array $data): void
    {
        $path = self::manifestPath($pluginDir);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write plugin.json: ' . $path);
        }
    }

    public static function setIgnored(string $pluginDir, bool $ignored): void
    {
        $data = self::read($pluginDir);
        if ($ignored) {
            $data['ignored'] = true;
        } else {
            unset($data['ignored']);
        }

        self::write($pluginDir, $data);
    }

    public static function isIgnored(string $pluginDir): bool
    {
        $data = self::read($pluginDir);

        return (bool) ($data['ignored'] ?? false);
    }

    private static function manifestPath(string $pluginDir): string
    {
        $path = rtrim($pluginDir, '/') . '/plugin.json';
        if (!is_file($path)) {
            throw new InvalidArgumentException('Missing plugin.json in ' . $pluginDir);
        }

        return $path;
    }
}