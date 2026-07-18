<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Support;

use Latch\Core\Config;
use Latch\Models\AuditLogRepository;
use Latch\Core\Database;
use RuntimeException;

/**
 * List and restore site backups from storage/backups/latch-backup-*.tar.gz.
 *
 * Supports:
 * - Split format: outer tar with core.tar.gz + plugins.tar.gz
 * - Legacy flat: storage/database/latch.sqlite + config/local.php at top level
 */
final class SiteRestore
{
    public const CORE_MEMBER = 'core.tar.gz';
    public const PLUGINS_MEMBER = 'plugins.tar.gz';
    private const DB_MEMBER = 'storage/database/latch.sqlite';
    private const CONFIG_MEMBER = 'config/local.php';
    private const PRE_RESTORE_PREFIX = '.pre-restore-';
    private const PRE_RESTORE_LATEST = '.pre-restore-latest.sqlite';
    private const MAX_PRE_RESTORE_SNAPSHOTS = 3;

    /**
     * @return list<array{name: string, path: string, size_bytes: int, mtime_iso: string, contents: list<string>, format: string, parts: list<string>}>
     */
    public static function listBackups(string $storagePath): array
    {
        $dir = self::backupDir($storagePath);
        if (!is_dir($dir)) {
            return [];
        }

        $backups = [];
        foreach (glob($dir . '/latch-backup-*.tar.gz') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }

            $mtime = filemtime($path);
            $meta = self::describeArchive($path);
            $backups[] = [
                'name' => basename($path),
                'path' => $path,
                'size_bytes' => (int) filesize($path),
                'mtime_iso' => $mtime !== false ? gmdate('c', $mtime) : '',
                'contents' => $meta['contents'],
                'format' => $meta['format'],
                'parts' => $meta['parts'],
            ];
        }

        usort($backups, static fn (array $a, array $b): int => strcmp($b['mtime_iso'], $a['mtime_iso']));

        return $backups;
    }

    /**
     * @param array{
     *   storage_path: string,
     *   source_root: string,
     *   db_path: string,
     *   local_config_path: string,
     *   archive?: string,
     *   with_config?: bool,
     *   force?: bool,
     *   dry_run?: bool,
     *   core_only?: bool,
     *   plugins_only?: bool
     * } $options
     * @return array{ok: bool, message: string, archive?: string, rolled_back?: bool, parts?: list<string>}
     */
    public static function restore(array $options): array
    {
        $storagePath = rtrim((string) $options['storage_path'], '/');
        $sourceRoot = rtrim((string) $options['source_root'], '/');
        $dbPath = (string) $options['db_path'];
        $localConfigPath = (string) $options['local_config_path'];
        $withConfig = !empty($options['with_config']);
        $force = !empty($options['force']);
        $dryRun = !empty($options['dry_run']);
        $coreOnly = !empty($options['core_only']);
        $pluginsOnly = !empty($options['plugins_only']);

        if ($coreOnly && $pluginsOnly) {
            throw new RuntimeException('Use either --core-only or --plugins-only, not both.');
        }

        if (!$force && !SiteLock::isLocked($storagePath)) {
            throw new RuntimeException(
                "Refusing restore: site is not locked.\n"
                . "Run: php bin/latch lock on\n"
                . 'Or:  php bin/latch restore --latest --force   # dangerous',
                3,
            );
        }

        $archive = (string) ($options['archive'] ?? '');
        if ($archive === '') {
            throw new RuntimeException('No backup archive specified.');
        }

        if (!is_file($archive)) {
            throw new RuntimeException('Backup archive not found: ' . $archive);
        }

        $meta = self::describeArchive($archive);
        $format = $meta['format'];
        $parts = $meta['parts'];

        $wantCore = !$pluginsOnly;
        $wantPlugins = !$coreOnly;

        if ($wantCore) {
            if ($format === 'split' && !in_array('core', $parts, true)) {
                throw new RuntimeException(
                    'Archive has no core.tar.gz (try --plugins-only if you only need plugin storage).',
                );
            }
            if ($format === 'legacy' && !in_array(self::DB_MEMBER, $meta['contents'], true)) {
                throw new RuntimeException('Archive does not contain ' . self::DB_MEMBER . ' or core.tar.gz');
            }
        }

        if ($pluginsOnly) {
            if ($format === 'legacy') {
                throw new RuntimeException(
                    'This is a legacy core-only backup (pre-split). No plugin storage to restore.',
                );
            }
            if (!in_array('plugins', $parts, true)) {
                throw new RuntimeException('Archive has no plugins.tar.gz.');
            }
        }

        if ($format === 'split' || $format === 'legacy') {
            $hasConfig = $format === 'legacy'
                ? in_array(self::CONFIG_MEMBER, $meta['contents'], true)
                : self::splitCoreHasConfig($archive);
            if ($hasConfig && !$withConfig && $wantCore) {
                fwrite(STDERR, "Note: archive includes local.php; pass --with-config to restore it.\n");
            }
        }

        $planned = [];
        if ($wantCore) {
            $planned[] = 'core';
        }
        if ($wantPlugins && ($format === 'split' ? in_array('plugins', $parts, true) : false)) {
            $planned[] = 'plugins';
        }

        if ($dryRun) {
            return [
                'ok' => true,
                'message' => 'Dry run: would restore ' . basename($archive)
                    . ' parts=[' . implode(', ', $planned) . ']',
                'archive' => $archive,
                'parts' => $planned,
            ];
        }

        if ($force) {
            self::logForcedRestore($dbPath, $archive);
        }

        $preRestorePath = $wantCore ? self::createPreRestoreSnapshot($storagePath, $dbPath) : null;

        $tempRoot = sys_get_temp_dir() . '/latch-restore-' . bin2hex(random_bytes(8));
        if (!mkdir($tempRoot, 0700, true) && !is_dir($tempRoot)) {
            throw new RuntimeException('Cannot create temp directory for restore.');
        }

        try {
            self::extractArchive($archive, $tempRoot);

            if ($wantCore) {
                $extractedDb = self::locateExtractedDb($tempRoot, $format);
                self::removeWalSidecars($dbPath);
                Scripts::runSqliteBackup($extractedDb, $dbPath);

                if ($withConfig) {
                    $extractedConfig = self::locateExtractedConfig($tempRoot, $format);
                    if ($extractedConfig !== null) {
                        if (!copy($extractedConfig, $localConfigPath)) {
                            throw new RuntimeException('Failed to restore config/local.php');
                        }
                    }
                }

                $check = SqliteIntegrity::run($dbPath);
                if (!$check['ok']) {
                    self::attemptRollback($preRestorePath, $dbPath);
                    throw new RuntimeException(
                        'Post-restore db-check failed. Rolled back to pre-restore snapshot when possible.',
                    );
                }
            }

            if (in_array('plugins', $planned, true)) {
                self::restorePluginsFromExtract($tempRoot, $storagePath, $format);
            }

            $msg = 'Restored ' . basename($archive)
                . ' parts=[' . implode(', ', $planned) . ']';
            if (in_array('core', $planned, true)) {
                $msg .= ' and verified database integrity';
            }
            if ($coreOnly) {
                $msg .= ' (plugins left unchanged — disable a bad plugin with: php bin/latch plugin disable <slug>)';
            }
            $msg .= '.';

            return [
                'ok' => true,
                'message' => $msg,
                'archive' => $archive,
                'parts' => $planned,
            ];
        } finally {
            self::removeTree($tempRoot);
        }
    }

    public static function resolveArchive(string $storagePath, ?string $latest, ?string $name, ?string $archive): string
    {
        if ($archive !== null && $archive !== '') {
            return $archive;
        }

        if ($name !== null && $name !== '') {
            return self::backupDir($storagePath) . '/' . ltrim($name, '/');
        }

        if ($latest !== null) {
            $backups = self::listBackups($storagePath);
            if ($backups === []) {
                throw new RuntimeException('No backups found in ' . self::backupDir($storagePath));
            }

            return $backups[0]['path'];
        }

        throw new RuntimeException('Specify --latest, --name=FILENAME, or --archive=PATH');
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KiB';
        }

        return round($bytes / (1024 * 1024), 1) . ' MiB';
    }

    /**
     * @return array{format: string, parts: list<string>, contents: list<string>}
     */
    public static function describeArchive(string $archive): array
    {
        $top = self::archiveContents($archive);
        $hasCore = in_array(self::CORE_MEMBER, $top, true) || in_array('./' . self::CORE_MEMBER, $top, true);
        $hasPlugins = in_array(self::PLUGINS_MEMBER, $top, true) || in_array('./' . self::PLUGINS_MEMBER, $top, true);

        if ($hasCore || $hasPlugins) {
            $parts = [];
            $contents = [];
            if ($hasCore) {
                $parts[] = 'core';
                $contents[] = self::CORE_MEMBER;
            }
            if ($hasPlugins) {
                $parts[] = 'plugins';
                $contents[] = self::PLUGINS_MEMBER;
            }

            return [
                'format' => 'split',
                'parts' => $parts,
                'contents' => $contents,
            ];
        }

        return [
            'format' => 'legacy',
            'parts' => in_array(self::DB_MEMBER, $top, true) ? ['core'] : [],
            'contents' => $top,
        ];
    }

    /**
     * @return list<string>
     */
    private static function archiveContents(string $archive): array
    {
        $cmd = 'tar -tzf ' . escapeshellarg($archive) . ' 2>/dev/null';
        exec($cmd, $lines, $code);
        if ($code !== 0) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $line): string => ltrim(trim($line), './'),
            $lines,
        )));
    }

    private static function backupDir(string $storagePath): string
    {
        return rtrim($storagePath, '/') . '/backups';
    }

    private static function extractArchive(string $archive, string $dest): void
    {
        $cmd = 'tar -xzf ' . escapeshellarg($archive) . ' -C ' . escapeshellarg($dest) . ' 2>&1';
        exec($cmd, $output, $code);
        if ($code !== 0) {
            throw new RuntimeException('Failed to extract archive: ' . implode("\n", $output));
        }
    }

    private static function locateExtractedDb(string $tempRoot, string $format): string
    {
        if ($format === 'split') {
            $coreArchive = self::safeJoin($tempRoot, self::CORE_MEMBER);
            if ($coreArchive === null || !is_file($coreArchive)) {
                throw new RuntimeException('core.tar.gz missing after extract.');
            }
            $coreDir = $tempRoot . '/_core';
            if (!mkdir($coreDir, 0700, true) && !is_dir($coreDir)) {
                throw new RuntimeException('Cannot stage core extract.');
            }
            self::extractArchive($coreArchive, $coreDir);

            return self::locateDbFile($coreDir);
        }

        return self::locateDbFile($tempRoot);
    }

    private static function locateDbFile(string $root): string
    {
        $candidate = $root . '/' . self::DB_MEMBER;
        $resolved = realpath($candidate);
        $rootReal = realpath($root) ?: $root;
        if ($resolved === false || !str_starts_with($resolved, $rootReal)) {
            throw new RuntimeException('Extracted database path is invalid.');
        }

        if (!is_file($resolved)) {
            throw new RuntimeException('Extracted database not found in archive.');
        }

        return $resolved;
    }

    private static function locateExtractedConfig(string $tempRoot, string $format): ?string
    {
        if ($format === 'split') {
            $coreArchive = self::safeJoin($tempRoot, self::CORE_MEMBER);
            if ($coreArchive === null || !is_file($coreArchive)) {
                return null;
            }
            $coreDir = $tempRoot . '/_core_cfg';
            if (!is_dir($coreDir)) {
                if (!mkdir($coreDir, 0700, true) && !is_dir($coreDir)) {
                    return null;
                }
                self::extractArchive($coreArchive, $coreDir);
            }

            return self::locateConfigFile($coreDir);
        }

        return self::locateConfigFile($tempRoot);
    }

    private static function locateConfigFile(string $root): ?string
    {
        $candidate = $root . '/' . self::CONFIG_MEMBER;
        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved)) {
            return null;
        }

        $rootReal = realpath($root);
        if ($rootReal !== false && !str_starts_with($resolved, $rootReal)) {
            return null;
        }

        return $resolved;
    }

    private static function splitCoreHasConfig(string $outerArchive): bool
    {
        $tmp = sys_get_temp_dir() . '/latch-peek-' . bin2hex(random_bytes(4));
        if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
            return false;
        }

        try {
            self::extractArchive($outerArchive, $tmp);
            $core = self::safeJoin($tmp, self::CORE_MEMBER);
            if ($core === null || !is_file($core)) {
                return false;
            }
            $inner = self::archiveContents($core);

            return in_array(self::CONFIG_MEMBER, $inner, true);
        } catch (\Throwable) {
            return false;
        } finally {
            self::removeTree($tmp);
        }
    }

    private static function restorePluginsFromExtract(string $tempRoot, string $storagePath, string $format): void
    {
        $pluginsDest = rtrim($storagePath, '/') . '/plugins';
        $sourcePlugins = null;

        if ($format === 'split') {
            $pluginsArchive = self::safeJoin($tempRoot, self::PLUGINS_MEMBER);
            if ($pluginsArchive === null || !is_file($pluginsArchive)) {
                return;
            }
            $plugDir = $tempRoot . '/_plugins';
            if (!mkdir($plugDir, 0700, true) && !is_dir($plugDir)) {
                throw new RuntimeException('Cannot stage plugins extract.');
            }
            self::extractArchive($pluginsArchive, $plugDir);
            $sourcePlugins = $plugDir . '/storage/plugins';
            if (!is_dir($sourcePlugins)) {
                // plugins.tar.gz may pack relative storage/plugins or just plugins/
                if (is_dir($plugDir . '/plugins')) {
                    $sourcePlugins = $plugDir . '/plugins';
                } else {
                    throw new RuntimeException('plugins.tar.gz does not contain storage/plugins/.');
                }
            }
        } else {
            return;
        }

        if (!is_dir($pluginsDest)) {
            if (!mkdir($pluginsDest, 0750, true) && !is_dir($pluginsDest)) {
                throw new RuntimeException('Cannot create storage/plugins/ for restore.');
            }
        }

        // Full replace so restored state matches the backup (bad plugin data can be skipped with --core-only).
        self::removeTree($pluginsDest);
        if (!mkdir($pluginsDest, 0750, true) && !is_dir($pluginsDest)) {
            throw new RuntimeException('Cannot recreate storage/plugins/ for restore.');
        }

        self::copyTree($sourcePlugins, $pluginsDest);
    }

    private static function copyTree(string $src, string $dest): void
    {
        $src = rtrim($src, '/');
        $dest = rtrim($dest, '/');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            $rel = substr($file->getPathname(), strlen($src) + 1);
            $target = $dest . '/' . $rel;
            if ($file->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0750, true) && !is_dir($target)) {
                    throw new RuntimeException('Cannot create directory during plugin restore: ' . $rel);
                }
                continue;
            }
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0750, true) && !is_dir($parent)) {
                throw new RuntimeException('Cannot create parent during plugin restore: ' . $rel);
            }
            if (!copy($file->getPathname(), $target)) {
                throw new RuntimeException('Failed to restore plugin file: ' . $rel);
            }
        }
    }

    private static function safeJoin(string $root, string $name): ?string
    {
        $path = rtrim($root, '/') . '/' . $name;
        $resolved = realpath($path);
        $rootReal = realpath($root) ?: $root;
        if ($resolved === false) {
            // realpath fails if not yet… use path if file exists under root
            if (is_file($path) && str_starts_with($path, $rootReal)) {
                return $path;
            }

            return null;
        }
        if (!str_starts_with($resolved, $rootReal)) {
            return null;
        }

        return $resolved;
    }

    private static function createPreRestoreSnapshot(string $storagePath, string $dbPath): ?string
    {
        if (!is_file($dbPath)) {
            return null;
        }

        $backupDir = self::backupDir($storagePath);
        if (!is_dir($backupDir) && !mkdir($backupDir, 0750, true) && !is_dir($backupDir)) {
            throw new RuntimeException('Cannot create backup directory for pre-restore snapshot.');
        }

        $timestamp = gmdate('Ymd-His');
        $snapshot = $backupDir . '/' . self::PRE_RESTORE_PREFIX . $timestamp . '.sqlite';
        Scripts::runSqliteBackup($dbPath, $snapshot);

        $latest = $backupDir . '/' . self::PRE_RESTORE_LATEST;
        if (is_link($latest) || is_file($latest)) {
            @unlink($latest);
        }
        @symlink(basename($snapshot), $latest);

        self::prunePreRestoreSnapshots($backupDir);

        return $snapshot;
    }

    private static function prunePreRestoreSnapshots(string $backupDir): void
    {
        $files = glob($backupDir . '/' . self::PRE_RESTORE_PREFIX . '*.sqlite') ?: [];
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        foreach (array_slice($files, self::MAX_PRE_RESTORE_SNAPSHOTS) as $old) {
            @unlink($old);
        }
    }

    private static function removeWalSidecars(string $dbPath): void
    {
        foreach ([$dbPath . '-wal', $dbPath . '-shm'] as $sidecar) {
            if (is_file($sidecar)) {
                @unlink($sidecar);
            }
        }
    }

    private static function attemptRollback(?string $preRestorePath, string $dbPath): void
    {
        if ($preRestorePath === null || !is_file($preRestorePath)) {
            return;
        }

        try {
            self::removeWalSidecars($dbPath);
            Scripts::runSqliteBackup($preRestorePath, $dbPath);
        } catch (\Throwable) {
            // Operator must recover manually.
        }
    }

    private static function logForcedRestore(string $dbPath, string $archive): void
    {
        fwrite(STDERR, "WARNING: restore --force skips site-lock quiesce. Traffic may see torn reads.\n");

        try {
            $config = new Config((defined('LATCH_ROOT') ? LATCH_ROOT : dirname(__DIR__, 2)) . '/config');
            if ($config->isInstalled()) {
                $audit = new AuditLogRepository(new Database($dbPath));
                $audit->record(null, 'restore.forced', 'database', null, 'cli', [
                    'archive' => basename($archive),
                ]);
                return;
            }
        } catch (\Throwable) {
            // Fall through to file log.
        }

        $logDir = dirname($dbPath, 2) . '/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }
        $line = gmdate('c') . ' restore.forced archive=' . basename($archive) . "\n";
        @file_put_contents($logDir . '/restore.log', $line, FILE_APPEND | LOCK_EX);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($path);
    }
}
