<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

/**
 * Runtime ownership for storage/, plugin code (plugins/), and local config.
 */
final class PluginStoragePermissions
{
    /** @var list<string> */
    private const STORAGE_SUBDIRS = [
        'database',
        'cache',
        'cache/twig',
        'cache/pages',
        'cache/fragments',
        'cache/plugin-audits',
        'logs',
        'uploads',
        'backups',
        'plugins',
    ];

    public static function webUser(): string
    {
        return self::resolveWebUser(null);
    }

    public static function resolveWebUser(?string $override = null): string
    {
        if (is_string($override) && trim($override) !== '') {
            return trim($override);
        }

        $user = getenv('LATCH_WEB_USER') ?: getenv('WEB_USER') ?: 'apache';

        return is_string($user) && trim($user) !== '' ? trim($user) : 'apache';
    }

    /**
     * When CLI runs as root, chown a tree to the web user.
     */
    public static function ensureWritable(string $dir): bool
    {
        return self::ensureWritableAs(self::webUser(), $dir);
    }

    public static function ensureWritableAs(string $webUser, string $dir, int $dirMode = 02775, int $fileMode = 0664): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return is_writable($dir);
        }

        $passwd = posix_getpwnam($webUser);
        if ($passwd === false) {
            return false;
        }

        $uid = (int) $passwd['uid'];
        $gid = (int) $passwd['gid'];
        self::chownTree($dir, $uid, $gid, $dirMode, $fileMode);

        return is_writable($dir);
    }

    /**
     * Paths `fix-perms` will chown when they exist (or are created under storage/).
     *
     * @return list<array{path: string, label: string, dir_mode: int, file_mode: int}>
     */
    public static function fixTargets(
        string $storagePath,
        ?string $pluginsPath = null,
        ?string $localConfigPath = null,
    ): array {
        $storagePath = rtrim($storagePath, '/');
        $targets = [
            [
                'path' => $storagePath,
                'label' => 'storage/',
                'dir_mode' => 02770,
                'file_mode' => 0660,
            ],
        ];

        foreach (self::STORAGE_SUBDIRS as $subdir) {
            $targets[] = [
                'path' => $storagePath . '/' . $subdir,
                'label' => 'storage/' . $subdir,
                'dir_mode' => str_contains($subdir, 'plugin') ? 02775 : 02770,
                'file_mode' => str_contains($subdir, 'plugin') ? 0664 : 0660,
            ];
        }

        if (is_string($pluginsPath) && $pluginsPath !== '') {
            $targets[] = [
                'path' => rtrim($pluginsPath, '/'),
                'label' => 'plugins/ (code)',
                'dir_mode' => 02775,
                'file_mode' => 0664,
            ];
        }

        if (is_string($localConfigPath) && $localConfigPath !== '') {
            $targets[] = [
                'path' => $localConfigPath,
                'label' => 'config/local.php',
                'dir_mode' => 0,
                'file_mode' => 0640,
            ];
        }

        return $targets;
    }

    /**
     * One-shot repair for all Latch runtime paths (requires root for chown).
     *
     * @return array{ok: bool, fixed: list<string>, skipped: list<string>, message: string, web_user: string}
     */
    public static function fixRuntimePermissions(
        string $storagePath,
        ?string $pluginsPath = null,
        ?string $localConfigPath = null,
        ?string $dbPath = null,
        ?string $webUser = null,
    ): array {
        $webUser = self::resolveWebUser($webUser);

        if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            return [
                'ok' => false,
                'fixed' => [],
                'skipped' => [],
                'message' => 'Run as root: sudo latch fix-perms',
                'web_user' => $webUser,
            ];
        }

        $passwd = posix_getpwnam($webUser);
        if ($passwd === false) {
            return [
                'ok' => false,
                'fixed' => [],
                'skipped' => [],
                'message' => "Web user not found: {$webUser} (set WEB_USER or --web-user=…)",
                'web_user' => $webUser,
            ];
        }

        $uid = (int) $passwd['uid'];
        $gid = (int) $passwd['gid'];
        $storagePath = rtrim($storagePath, '/');

        self::ensureStorageLayout($storagePath, $uid, $gid);

        $fixed = [];
        $skipped = [];

        foreach (self::fixTargets($storagePath, $pluginsPath, $localConfigPath) as $target) {
            $path = $target['path'];
            $label = $target['label'];

            if ($label === 'config/local.php') {
                if (!is_file($path)) {
                    $skipped[] = $label . ' (missing)';
                    continue;
                }

            $realConfig = realpath($path) ?: $path;
            if (self::fixLocalConfig($realConfig, $gid)) {
                $fixed[] = $label;
            } else {
                $skipped[] = $label;
            }

                continue;
            }

            if (!is_dir($path)) {
                $skipped[] = $label . ' (missing)';
                continue;
            }

            self::chownTree($path, $uid, $gid, $target['dir_mode'], $target['file_mode']);
            $fixed[] = $label;
        }

        if (is_string($dbPath) && $dbPath !== '' && is_file($dbPath)) {
            @chown($dbPath, $uid);
            @chgrp($dbPath, $gid);
            @chmod($dbPath, 0660);
            foreach (['-wal', '-shm'] as $suffix) {
                $sidecar = $dbPath . $suffix;
                if (!is_file($sidecar)) {
                    continue;
                }

                @chown($sidecar, $uid);
                @chgrp($sidecar, $gid);
                @chmod($sidecar, 0660);
            }

            $fixed[] = 'database/latch.sqlite';
        }

        if ($fixed === []) {
            return [
                'ok' => false,
                'fixed' => [],
                'skipped' => $skipped,
                'message' => 'Nothing fixed — create storage/ first or check paths',
                'web_user' => $webUser,
            ];
        }

        $message = 'Updated permissions for ' . $webUser . ': ' . implode(', ', $fixed);
        if ($skipped !== []) {
            $message .= ' (skipped: ' . implode(', ', $skipped) . ')';
        }

        return [
            'ok' => true,
            'fixed' => $fixed,
            'skipped' => $skipped,
            'message' => $message,
            'web_user' => $webUser,
        ];
    }

    /**
     * @deprecated Use fixRuntimePermissions()
     *
     * @return array{ok: bool, fixed: list<string>, message: string}
     */
    public static function fixPluginStorage(string $storagePath, ?string $pluginsPath = null): array
    {
        $result = self::fixRuntimePermissions($storagePath, $pluginsPath);

        return [
            'ok' => $result['ok'],
            'fixed' => $result['fixed'],
            'message' => $result['message'],
        ];
    }

    private static function ensureStorageLayout(string $storagePath, int $uid, int $gid): void
    {
        if (!is_dir($storagePath)) {
            @mkdir($storagePath, 02770, true);
        }

        foreach (self::STORAGE_SUBDIRS as $subdir) {
            $dir = $storagePath . '/' . $subdir;
            if (is_dir($dir)) {
                continue;
            }

            @mkdir($dir, str_contains($subdir, 'plugin') ? 02775 : 02770, true);
        }

        if (!is_dir($storagePath)) {
            return;
        }

        @chown($storagePath, $uid);
        @chgrp($storagePath, $gid);
        @chmod($storagePath, 02770);
    }

    private static function fixLocalConfig(string $path, int $webGid): bool
    {
        if (!is_file($path)) {
            return false;
        }

        @chown($path, 0);
        @chgrp($path, $webGid);
        @chmod($path, 0640);

        return true;
    }

    private static function chownTree(string $dir, int $uid, int $gid, int $dirMode, int $fileMode): void
    {
        @chown($dir, $uid);
        @chgrp($dir, $gid);
        @chmod($dir, $dirMode);

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
            @chmod($path, $item->isDir() ? $dirMode : $fileMode);
        }
    }
}