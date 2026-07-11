<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Plugins\PluginManifest;
use RuntimeException;

/**
 * Resolves the sibling Latch-plugins catalog for PHPUnit (not used in production).
 */
final class CatalogPath
{
    /** @var list<string> */
    public const TIER1_SLUGS = [
        'forum-stats',
        'image-upload',
        'word-filter',
        'spam-bridge',
        'slack-notify',
    ];

    public static function root(): string
    {
        $env = getenv('LATCH_PLUGINS_CATALOG');
        if (is_string($env) && $env !== '') {
            $env = rtrim($env, '/');
            if (is_dir($env)) {
                return $env;
            }
        }

        $candidates = [
            dirname(__DIR__, 3) . '/Latch-plugins',
            dirname(__DIR__, 2) . '/Latch-plugins',
        ];

        foreach ($candidates as $candidate) {
            if (is_dir($candidate) && is_file($candidate . '/catalog.json')) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            'Latch-plugins catalog not found. Clone https://github.com/YeOK/Latch-plugins '
            . 'next to Latch-Git as ../Latch-plugins or set LATCH_PLUGINS_CATALOG.',
        );
    }

    public static function plugin(string $slug): string
    {
        $dir = self::root() . '/' . $slug;
        if (!is_dir($dir) || !is_file($dir . '/plugin.json')) {
            throw new RuntimeException("Catalog plugin not found: {$slug}");
        }

        return $dir;
    }

    public static function registerAutoloaders(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }

        foreach (self::TIER1_SLUGS as $slug) {
            if (!is_dir(self::root() . '/' . $slug)) {
                continue;
            }

            $manifest = PluginManifest::fromDirectory(self::plugin($slug));
            $prefix = 'Latch\\Plugins\\' . PluginManifest::studlySlug($slug) . '\\';
            $baseDir = $manifest->pluginDir . '/src/';

            spl_autoload_register(static function (string $class) use ($prefix, $baseDir): void {
                if (!str_starts_with($class, $prefix)) {
                    return;
                }

                $relative = substr($class, strlen($prefix));
                $path = $baseDir . str_replace('\\', '/', $relative) . '.php';
                if (is_file($path)) {
                    require $path;
                }
            });
        }

        $registered = true;
    }
}