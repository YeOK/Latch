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
use RuntimeException;
use SplFileInfo;

/**
 * Shared site maintenance tasks (CLI and admin dashboard).
 */
final class SiteMaintenance
{
    /**
     * Create a site backup under storage/backups/.
     *
     * Layout (split format):
     *   latch-backup-{timestamp}.tar.gz
     *     core.tar.gz      — latch.sqlite + optional config/local.php
     *     plugins.tar.gz   — storage/plugins/ (WAL-safe *.sqlite + settings)
     *
     * Legacy callers still receive a single outer path. Operators can restore
     * core without plugins when a bad plugin poisons the site.
     *
     * @param array{core?: bool, plugins?: bool} $options
     * @return array{ok: bool, message: string, path: string|null, parts: list<string>}
     */
    public static function createBackup(
        string $storagePath,
        string $dbPath,
        string $localConfigPath,
        array $options = [],
    ): array {
        if (!self::execAvailable()) {
            return [
                'ok' => false,
                'message' => 'Backup is not available from the web UI on this server (exec disabled). Run: php bin/latch backup',
                'path' => null,
                'parts' => [],
            ];
        }

        $includeCore = ($options['core'] ?? true) !== false;
        $includePlugins = ($options['plugins'] ?? true) !== false;
        if (!$includeCore && !$includePlugins) {
            return [
                'ok' => false,
                'message' => 'Nothing to back up (both --core-only and --plugins-only disabled).',
                'path' => null,
                'parts' => [],
            ];
        }

        $backupDir = rtrim($storagePath, '/') . '/backups';
        $pluginsRoot = rtrim($storagePath, '/') . '/plugins';

        if (!is_dir($backupDir) && !mkdir($backupDir, 0750, true) && !is_dir($backupDir)) {
            return [
                'ok' => false,
                'message' => 'Cannot create backup directory.',
                'path' => null,
                'parts' => [],
            ];
        }

        $timestamp = gmdate('Ymd-His');
        $archive = $backupDir . "/latch-backup-{$timestamp}.tar.gz";

        $sourceRoot = realpath(dirname(rtrim($storagePath, '/')));
        if ($sourceRoot === false) {
            // RPM layout: storage is /var/lib/latch/storage, config is /etc/latch — resolve via local.php parent.
            $configDir = realpath(dirname($localConfigPath));
            $sourceRoot = $configDir !== false ? $configDir : dirname($localConfigPath);
        }

        $stageRoot = sys_get_temp_dir() . '/latch-backup-tree-' . bin2hex(random_bytes(6));
        $parts = [];

        try {
            if (!mkdir($stageRoot, 0700, true) && !is_dir($stageRoot)) {
                return [
                    'ok' => false,
                    'message' => 'Cannot create backup staging directory.',
                    'path' => null,
                    'parts' => [],
                ];
            }

            if ($includeCore) {
                $coreInner = self::stageCoreArchive($stageRoot, $dbPath, $localConfigPath, $sourceRoot);
                if ($coreInner === null) {
                    if (!$includePlugins) {
                        return [
                            'ok' => false,
                            'message' => 'Nothing to back up (no core database or local.php).',
                            'path' => null,
                            'parts' => [],
                        ];
                    }
                } else {
                    $parts[] = 'core';
                }
            }

            if ($includePlugins) {
                $pluginsInner = self::stagePluginsArchive($stageRoot, $pluginsRoot);
                if ($pluginsInner === null) {
                    if (!$includeCore || $parts === []) {
                        return [
                            'ok' => false,
                            'message' => 'Nothing to back up (no plugin storage under storage/plugins/).',
                            'path' => null,
                            'parts' => [],
                        ];
                    }
                } else {
                    $parts[] = 'plugins';
                }
            }

            if ($parts === []) {
                return [
                    'ok' => false,
                    'message' => 'Nothing to back up.',
                    'path' => null,
                    'parts' => [],
                ];
            }

            $memberArgs = [];
            foreach ($parts as $part) {
                $memberArgs[] = escapeshellarg($part . '.tar.gz');
            }
            $cmd = 'tar -czf ' . escapeshellarg($archive)
                . ' -C ' . escapeshellarg($stageRoot)
                . ' ' . implode(' ', $memberArgs)
                . ' 2>&1';
            exec($cmd, $output, $code);
            if ($code !== 0 || !is_file($archive)) {
                return [
                    'ok' => false,
                    'message' => 'Backup failed' . ($output !== [] ? ': ' . implode("\n", $output) : '.'),
                    'path' => null,
                    'parts' => [],
                ];
            }
        } catch (RuntimeException $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
                'path' => null,
                'parts' => [],
            ];
        } finally {
            self::removeTree($stageRoot);
        }

        $partsLabel = implode(' + ', $parts);

        return [
            'ok' => true,
            'message' => "Backup written: {$archive} [{$partsLabel}]",
            'path' => $archive,
            'parts' => $parts,
        ];
    }

    /**
     * @return string|null Path to core.tar.gz inside stage root, or null if nothing to include
     */
    private static function stageCoreArchive(
        string $stageRoot,
        string $dbPath,
        string $localConfigPath,
        string $sourceRoot,
    ): ?string {
        $coreTree = $stageRoot . '/core-tree';
        $hasMember = false;

        if (is_file($dbPath)) {
            $stageDb = $coreTree . '/storage/database/latch.sqlite';
            if (!is_dir(dirname($stageDb)) && !mkdir(dirname($stageDb), 0700, true) && !is_dir(dirname($stageDb))) {
                throw new RuntimeException('Cannot create core backup staging directory.');
            }
            Scripts::runSqliteBackup($dbPath, $stageDb);
            $hasMember = true;
        }

        if (is_file($localConfigPath)) {
            $stageConfig = $coreTree . '/config/local.php';
            if (!is_dir(dirname($stageConfig)) && !mkdir(dirname($stageConfig), 0700, true) && !is_dir(dirname($stageConfig))) {
                throw new RuntimeException('Cannot stage config/local.php for backup.');
            }
            if (!copy($localConfigPath, $stageConfig)) {
                throw new RuntimeException('Failed to copy config/local.php into backup stage.');
            }
            $hasMember = true;
        }

        if (!$hasMember) {
            return null;
        }

        $inner = $stageRoot . '/core.tar.gz';
        $cmd = 'tar -czf ' . escapeshellarg($inner)
            . ' -C ' . escapeshellarg($coreTree)
            . ' . 2>&1';
        exec($cmd, $output, $code);
        if ($code !== 0 || !is_file($inner)) {
            throw new RuntimeException(
                'Failed to pack core.tar.gz' . ($output !== [] ? ': ' . implode("\n", $output) : ''),
            );
        }

        self::removeTree($coreTree);

        return $inner;
    }

    /**
     * Pack storage/plugins/ into plugins.tar.gz (WAL-safe SQLite copies).
     *
     * @return string|null Path to plugins.tar.gz, or null if no plugin files
     */
    private static function stagePluginsArchive(string $stageRoot, string $pluginsRoot): ?string
    {
        if (!is_dir($pluginsRoot)) {
            return null;
        }

        $pluginTree = $stageRoot . '/plugins-tree';
        $destRoot = $pluginTree . '/storage/plugins';
        if (!mkdir($destRoot, 0700, true) && !is_dir($destRoot)) {
            throw new RuntimeException('Cannot create plugins backup staging directory.');
        }

        $copied = self::copyPluginStorage($pluginsRoot, $destRoot);
        if ($copied === 0) {
            self::removeTree($pluginTree);

            return null;
        }

        $inner = $stageRoot . '/plugins.tar.gz';
        $cmd = 'tar -czf ' . escapeshellarg($inner)
            . ' -C ' . escapeshellarg($pluginTree)
            . ' storage 2>&1';
        exec($cmd, $output, $code);
        if ($code !== 0 || !is_file($inner)) {
            throw new RuntimeException(
                'Failed to pack plugins.tar.gz' . ($output !== [] ? ': ' . implode("\n", $output) : ''),
            );
        }

        self::removeTree($pluginTree);

        return $inner;
    }

    /**
     * Copy plugin storage tree; *.sqlite via WAL-safe backup, skip -wal/-shm.
     *
     * @return int Number of files copied
     */
    private static function copyPluginStorage(string $srcRoot, string $destRoot): int
    {
        $srcRoot = rtrim($srcRoot, '/');
        $destRoot = rtrim($destRoot, '/');
        $count = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            $path = $file->getPathname();
            $rel = self::relativePath($srcRoot, $path);
            if ($rel === null || $rel === '') {
                continue;
            }

            $dest = $destRoot . '/' . $rel;

            if ($file->isDir()) {
                if (!is_dir($dest) && !mkdir($dest, 0700, true) && !is_dir($dest)) {
                    throw new RuntimeException('Cannot create plugin stage dir: ' . $dest);
                }
                continue;
            }

            if (!$file->isFile()) {
                continue;
            }

            $base = $file->getBasename();
            if (str_ends_with($base, '-wal') || str_ends_with($base, '-shm')) {
                continue;
            }

            $parent = dirname($dest);
            if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                throw new RuntimeException('Cannot create plugin stage parent: ' . $parent);
            }

            if (str_ends_with(strtolower($base), '.sqlite')) {
                Scripts::runSqliteBackup($path, $dest);
            } else {
                if (!copy($path, $dest)) {
                    throw new RuntimeException('Failed to copy plugin file: ' . $rel);
                }
            }
            $count++;
        }

        return $count;
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
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $normalized = str_replace('\\', '/', $absolutePath);

        if (!str_starts_with($normalized, $root)) {
            return null;
        }

        return ltrim(substr($normalized, strlen($root)), '/');
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
