<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Support;

use FilesystemIterator;
use Latch\Core\Cache;
use Latch\Models\SearchRepository;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Shared site maintenance tasks (CLI and admin dashboard).
 */
final class SiteMaintenance
{
    /**
     * @return array{ok: bool, message: string, path: string|null}
     */
    public static function createBackup(string $storagePath, string $dbPath, string $localConfigPath): array
    {
        if (!self::execAvailable()) {
            return [
                'ok' => false,
                'message' => 'Backup is not available from the web UI on this server (exec disabled). Run: php bin/latch backup',
                'path' => null,
            ];
        }

        $backupDir = rtrim($storagePath, '/') . '/backups';

        if (!is_dir($backupDir) && !mkdir($backupDir, 0750, true) && !is_dir($backupDir)) {
            return [
                'ok' => false,
                'message' => 'Cannot create backup directory.',
                'path' => null,
            ];
        }

        $timestamp = gmdate('Ymd-His');
        $archive = $backupDir . "/latch-backup-{$timestamp}.tar.gz";

        $sourceRoot = realpath(dirname(rtrim($storagePath, '/')));
        if ($sourceRoot === false) {
            return [
                'ok' => false,
                'message' => 'Cannot resolve site root for backup.',
                'path' => null,
            ];
        }

        if (!is_file($dbPath) && !is_file($localConfigPath)) {
            return [
                'ok' => false,
                'message' => 'Nothing to back up.',
                'path' => null,
            ];
        }

        $stageRoot = sys_get_temp_dir() . '/latch-backup-tree-' . bin2hex(random_bytes(6));
        $stageDb = $stageRoot . '/storage/database/latch.sqlite';
        $tarMembers = [];

        try {
            if (is_file($dbPath)) {
                if (!is_dir(dirname($stageDb)) && !mkdir(dirname($stageDb), 0700, true) && !is_dir(dirname($stageDb))) {
                    return [
                        'ok' => false,
                        'message' => 'Cannot create backup staging directory.',
                        'path' => null,
                    ];
                }

                Scripts::runSqliteBackup($dbPath, $stageDb);
                $tarMembers[] = '-C ' . escapeshellarg($stageRoot) . ' storage/database/latch.sqlite';
            }

            if (is_file($localConfigPath)) {
                $tarMembers[] = '-C ' . escapeshellarg($sourceRoot) . ' config/local.php';
            }

            if ($tarMembers === []) {
                return [
                    'ok' => false,
                    'message' => 'Nothing to back up.',
                    'path' => null,
                ];
            }

            $cmd = 'tar -czf ' . escapeshellarg($archive) . ' ' . implode(' ', $tarMembers) . ' 2>/dev/null';
            exec($cmd, $output, $code);
            if ($code !== 0) {
                return [
                    'ok' => false,
                    'message' => 'Backup failed.',
                    'path' => null,
                ];
            }
        } finally {
            self::removeTree($stageRoot);
        }

        return [
            'ok' => true,
            'message' => 'Backup written: ' . $archive,
            'path' => $archive,
        ];
    }

    /**
     * @return array{page_cache: int, twig_files: int}
     */
    public static function clearCaches(Cache $cache, string $storagePath): array
    {
        return [
            'page_cache' => $cache->purgeAll(),
            'twig_files' => self::clearTwigCompileCache(rtrim($storagePath, '/') . '/cache/twig'),
        ];
    }

    public static function clearTwigCompileCache(string $twigCache): int
    {
        self::ensureTwigCacheDir($twigCache);

        if (!is_dir($twigCache)) {
            return 0;
        }

        $cleared = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($twigCache, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }

            if (@unlink($file->getPathname())) {
                $cleared++;
            }
        }

        self::ensureTwigCacheDir($twigCache, true);

        return $cleared;
    }

    public static function ensureTwigCacheDir(string $twigCache, bool $recursive = false): void
    {
        if (!is_dir($twigCache)) {
            mkdir($twigCache, 0775, true);
        }

        @chmod($twigCache, 0775);

        if (!$recursive || !is_dir($twigCache)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($twigCache, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            @chmod($file->getPathname(), $file->isDir() ? 0775 : 0664);
        }
    }

    private static function relativePath(string $root, string $absolutePath): ?string
    {
        $root = rtrim($root, '/') . '/';
        $normalized = str_replace('\\', '/', $absolutePath);
        $rootNorm = str_replace('\\', '/', $root);

        if (!str_starts_with($normalized, $rootNorm)) {
            return null;
        }

        return ltrim(substr($normalized, strlen($rootNorm)), '/');
    }

    private static function execAvailable(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }

        $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));

        return !in_array('exec', $disabled, true);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($path);
    }

    /**
     * @return array{ok: bool, message: string, topics: int}
     */
    public static function reindexSearch(SearchRepository $search): array
    {
        if (!$search->isEnabled()) {
            return [
                'ok' => false,
                'message' => 'Search index not found. Run migrate first.',
                'topics' => 0,
            ];
        }

        $count = $search->reindexAll();

        return [
            'ok' => true,
            'message' => "Search index rebuilt for {$count} topic(s).",
            'topics' => $count,
        ];
    }
}