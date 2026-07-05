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
 */
final class SiteRestore
{
    private const DB_MEMBER = 'storage/database/latch.sqlite';
    private const CONFIG_MEMBER = 'config/local.php';
    private const PRE_RESTORE_PREFIX = '.pre-restore-';
    private const PRE_RESTORE_LATEST = '.pre-restore-latest.sqlite';
    private const MAX_PRE_RESTORE_SNAPSHOTS = 3;

    /**
     * @return list<array{name: string, path: string, size_bytes: int, mtime_iso: string, contents: list<string>}>
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
            $backups[] = [
                'name' => basename($path),
                'path' => $path,
                'size_bytes' => (int) filesize($path),
                'mtime_iso' => $mtime !== false ? gmdate('c', $mtime) : '',
                'contents' => self::archiveContents($path),
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
     *   dry_run?: bool
     * } $options
     * @return array{ok: bool, message: string, archive?: string, rolled_back?: bool}
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

        $contents = self::archiveContents($archive);
        if (!in_array(self::DB_MEMBER, $contents, true)) {
            throw new RuntimeException('Archive does not contain ' . self::DB_MEMBER);
        }

        if (in_array(self::CONFIG_MEMBER, $contents, true) && !$withConfig) {
            fwrite(STDERR, "Note: archive includes local.php; pass --with-config to restore it.\n");
        }

        if ($dryRun) {
            return [
                'ok' => true,
                'message' => 'Dry run: would restore ' . basename($archive) . ' to ' . $dbPath,
                'archive' => $archive,
            ];
        }

        if ($force) {
            self::logForcedRestore($dbPath, $archive);
        }

        $preRestorePath = self::createPreRestoreSnapshot($storagePath, $dbPath);

        $tempRoot = sys_get_temp_dir() . '/latch-restore-' . bin2hex(random_bytes(8));
        if (!mkdir($tempRoot, 0700, true) && !is_dir($tempRoot)) {
            throw new RuntimeException('Cannot create temp directory for restore.');
        }

        try {
            self::extractArchive($archive, $tempRoot);
            $extractedDb = self::locateExtractedDb($tempRoot);
            self::removeWalSidecars($dbPath);
            Scripts::runSqliteBackup($extractedDb, $dbPath);

            if ($withConfig) {
                $extractedConfig = self::locateExtractedConfig($tempRoot);
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

            return [
                'ok' => true,
                'message' => 'Restored ' . basename($archive) . ' and verified database integrity.',
                'archive' => $archive,
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
     * @return list<string>
     */
    private static function archiveContents(string $archive): array
    {
        $cmd = 'tar -tzf ' . escapeshellarg($archive) . ' 2>/dev/null';
        exec($cmd, $lines, $code);
        if ($code !== 0) {
            return [];
        }

        return array_values(array_filter(array_map('trim', $lines)));
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

    private static function locateExtractedDb(string $tempRoot): string
    {
        $candidate = $tempRoot . '/' . self::DB_MEMBER;
        $resolved = realpath($candidate);
        if ($resolved === false || !str_starts_with($resolved, realpath($tempRoot) ?: $tempRoot)) {
            throw new RuntimeException('Extracted database path is invalid.');
        }

        if (!is_file($resolved)) {
            throw new RuntimeException('Extracted database not found in archive.');
        }

        return $resolved;
    }

    private static function locateExtractedConfig(string $tempRoot): ?string
    {
        $candidate = $tempRoot . '/' . self::CONFIG_MEMBER;
        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved)) {
            return null;
        }

        $root = realpath($tempRoot);
        if ($root !== false && !str_starts_with($resolved, $root)) {
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