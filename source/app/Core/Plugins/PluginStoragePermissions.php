<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

/**
 * Ensure storage/plugins/{slug}/ is writable by the web server after root CLI installs.
 */
final class PluginStoragePermissions
{
    public static function webUser(): string
    {
        $user = getenv('LATCH_WEB_USER') ?: getenv('WEB_USER') ?: 'apache';

        return is_string($user) && trim($user) !== '' ? trim($user) : 'apache';
    }

    /**
     * When CLI runs as root, chown plugin storage to the web user so admin can save settings.json.
     */
    public static function ensureWritable(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return is_writable($dir);
        }

        $passwd = posix_getpwnam(self::webUser());
        if ($passwd === false) {
            return false;
        }

        $uid = (int) $passwd['uid'];
        $gid = (int) $passwd['gid'];
        self::chownTree($dir, $uid, $gid);

        return is_writable($dir);
    }

    /**
     * One-shot fix for plugin settings dirs and audit cache (requires root for chown).
     *
     * @return array{ok: bool, fixed: list<string>, message: string}
     */
    public static function fixPluginStorage(string $storagePath, ?string $pluginsPath = null): array
    {
        $storagePath = rtrim($storagePath, '/');
        $targets = [
            $storagePath . '/plugins',
            $storagePath . '/cache/plugin-audits',
        ];

        if (is_string($pluginsPath) && $pluginsPath !== '' && is_dir($pluginsPath)) {
            foreach (scandir($pluginsPath) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || $entry === '.gitkeep') {
                    continue;
                }

                $dir = rtrim($pluginsPath, '/') . '/' . $entry;
                if (is_dir($dir) && is_file($dir . '/plugin.json')) {
                    $targets[] = $dir;
                }
            }
        }

        $existing = array_values(array_filter($targets, static fn (string $path): bool => is_dir($path)));
        if ($existing === []) {
            return [
                'ok' => true,
                'fixed' => [],
                'message' => 'No plugin storage paths yet — nothing to fix.',
            ];
        }

        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            return [
                'ok' => false,
                'fixed' => [],
                'message' => 'Run as root: sudo latch fix-perms',
            ];
        }

        $fixed = [];
        foreach ($existing as $dir) {
            if (self::ensureWritable($dir)) {
                $fixed[] = $dir;
            }
        }

        if ($fixed === []) {
            return [
                'ok' => false,
                'fixed' => [],
                'message' => 'Could not fix plugin storage — chown manually to ' . self::webUser(),
            ];
        }

        return [
            'ok' => true,
            'fixed' => $fixed,
            'message' => 'Updated permissions: ' . implode(', ', $fixed),
        ];
    }

    private static function chownTree(string $dir, int $uid, int $gid): void
    {
        @chown($dir, $uid);
        @chgrp($dir, $gid);
        @chmod($dir, 02775);

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );
        } catch (\Throwable) {
            return;
        }

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            $path = $item->getPathname();
            @chown($path, $uid);
            @chgrp($path, $gid);
            @chmod($path, $item->isDir() ? 02775 : 0664);
        }
    }
}