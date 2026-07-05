<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

/**
 * Content fingerprint for plugin audit cache invalidation.
 */
final class PluginFingerprint
{
    public static function forDirectory(string $pluginDir): string
    {
        $pluginDir = realpath($pluginDir) ?: $pluginDir;
        if (!is_dir($pluginDir)) {
            return '';
        }

        $parts = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pluginDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $absolute = $fileInfo->getPathname();
            $relative = ltrim(str_replace($pluginDir, '', $absolute), '/\\');
            if (str_starts_with($relative, 'vendor/')) {
                continue;
            }

            $parts[] = str_replace('\\', '/', $relative)
                . ':' . $fileInfo->getSize()
                . ':' . $fileInfo->getMTime();
        }

        sort($parts);

        return hash('xxh128', implode("\n", $parts));
    }
}