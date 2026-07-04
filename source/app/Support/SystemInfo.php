<?php

declare(strict_types=1);

namespace Latch\Support;

use Latch\Core\Config;
use Latch\Core\Database;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

/**
 * Storage and cron health for the admin dashboard.
 * File-size scans are cached briefly to keep dashboard loads cheap.
 */
final class SystemInfo
{
    private const CACHE_TTL = 60;

    /** @var array<string, mixed>|null */
    private static ?array $requestSnapshot = null;

    /**
     * @param array{enabled: bool, transport: string, configured: bool} $mailStatus
     * @return array{
     *     database: array{
     *         main_bytes: int,
     *         wal_bytes: int,
     *         shm_bytes: int,
     *         total_bytes: int,
     *         total_label: string,
     *         detail_label: string,
     *         journal_mode: string
     *     },
     *     guest_cache: array{
     *         enabled: bool,
     *         file_count: int,
     *         total_bytes: int,
     *         label: string
     *     },
     *     cron: array{
     *         hourly: array{at: string|null, ago: string|null},
     *         daily: array{at: string|null, ago: string|null},
     *         weekly: array{at: string|null, ago: string|null}
     *     },
     *     mail: array{label: string, alert: bool}
     * }
     */
    public static function snapshot(
        Config $config,
        bool $cacheEnabled,
        array $mailStatus,
        ?string $lastCronDailyAt,
    ): array {
        if (self::$requestSnapshot !== null) {
            return self::$requestSnapshot;
        }

        $storagePath = (string) $config->get('paths.storage');
        $dbPath = (string) $config->get('database.path');
        $cacheFile = rtrim($storagePath, '/') . '/cache/system-info.json';
        $cached = self::readCache($cacheFile, $dbPath, $cacheEnabled, $mailStatus, $lastCronDailyAt);
        if ($cached !== null) {
            self::$requestSnapshot = $cached;

            return $cached;
        }

        $dbSizes = self::databaseSizes($dbPath);
        $journalMode = self::journalMode($dbPath);
        $guestCache = self::guestCacheStats($storagePath, $cacheEnabled);
        $cron = self::cronRuns($dbPath, $lastCronDailyAt);
        $mail = self::mailSummary($mailStatus);

        $mainLabel = SiteRestore::formatBytes($dbSizes['main']);
        $walLabel = SiteRestore::formatBytes($dbSizes['wal']);
        $detailLabel = $dbSizes['wal'] > 0
            ? "{$mainLabel} + {$walLabel} WAL"
            : $mainLabel;

        $snapshot = [
            'database' => [
                'main_bytes' => $dbSizes['main'],
                'wal_bytes' => $dbSizes['wal'],
                'shm_bytes' => $dbSizes['shm'],
                'total_bytes' => $dbSizes['total'],
                'total_label' => SiteRestore::formatBytes($dbSizes['total']),
                'detail_label' => $detailLabel,
                'journal_mode' => $journalMode,
            ],
            'guest_cache' => $guestCache,
            'cron' => $cron,
            'mail' => $mail,
        ];

        self::writeCache($cacheFile, $snapshot, $cacheEnabled, $mailStatus, $lastCronDailyAt);
        self::$requestSnapshot = $snapshot;

        return $snapshot;
    }

    public static function relativeTimeLabel(?string $iso): ?string
    {
        if ($iso === null || trim($iso) === '') {
            return null;
        }

        $timestamp = strtotime($iso);
        if ($timestamp === false) {
            return null;
        }

        $seconds = max(0, time() - $timestamp);
        if ($seconds < 60) {
            return 'just now';
        }

        if ($seconds < 3600) {
            return (int) floor($seconds / 60) . 'm ago';
        }

        if ($seconds < 86400) {
            return (int) floor($seconds / 3600) . 'h ago';
        }

        return (int) floor($seconds / 86400) . 'd ago';
    }

    /**
     * @return array{main: int, wal: int, shm: int, total: int}
     */
    public static function databaseSizes(string $dbPath): array
    {
        $main = is_file($dbPath) ? (int) filesize($dbPath) : 0;
        $wal = is_file($dbPath . '-wal') ? (int) filesize($dbPath . '-wal') : 0;
        $shm = is_file($dbPath . '-shm') ? (int) filesize($dbPath . '-shm') : 0;

        return [
            'main' => $main,
            'wal' => $wal,
            'shm' => $shm,
            'total' => $main + $wal + $shm,
        ];
    }

    /**
     * @param array{enabled: bool, transport: string, configured: bool} $mailStatus
     */
    public static function mailSummary(array $mailStatus): array
    {
        $enabled = (bool) ($mailStatus['enabled'] ?? false);
        $configured = (bool) ($mailStatus['configured'] ?? false);
        $transport = strtolower(trim((string) ($mailStatus['transport'] ?? 'msmtp')));

        if (!$enabled) {
            return ['label' => 'Disabled', 'alert' => true];
        }

        if (!$configured) {
            return ['label' => 'Not configured', 'alert' => true];
        }

        return [
            'label' => $transport . ' · ready',
            'alert' => false,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array{enabled: bool, transport: string, configured: bool} $mailStatus
     * @return array<string, mixed>|null
     */
    private static function readCache(
        string $cacheFile,
        string $dbPath,
        bool $cacheEnabled,
        array $mailStatus,
        ?string $lastCronDailyAt,
    ): ?array {
        if (!is_file($cacheFile) || !is_readable($cacheFile)) {
            return null;
        }

        if (filemtime($cacheFile) < time() - self::CACHE_TTL) {
            return null;
        }

        $raw = file_get_contents($cacheFile);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['snapshot']) || !is_array($data['snapshot'])) {
            return null;
        }

        $snapshot = $data['snapshot'];
        if (!self::cacheStillValid($data, $dbPath, $cacheEnabled, $mailStatus, $lastCronDailyAt)) {
            return null;
        }

        return self::refreshCronAndMail($snapshot, $dbPath, $lastCronDailyAt, $mailStatus);
    }

    /**
     * @param array<string, mixed> $data
     * @param array{enabled: bool, transport: string, configured: bool} $mailStatus
     */
    private static function cacheStillValid(
        array $data,
        string $dbPath,
        bool $cacheEnabled,
        array $mailStatus,
        ?string $lastCronDailyAt,
    ): bool {
        $sizes = self::databaseSizes($dbPath);
        $cachedSizes = $data['db_fingerprint'] ?? null;
        if (!is_array($cachedSizes)) {
            return false;
        }

        foreach (['main', 'wal', 'shm', 'total'] as $key) {
            if ((int) ($cachedSizes[$key] ?? -1) !== $sizes[$key]) {
                return false;
            }
        }

        if ((bool) ($data['cache_enabled'] ?? false) !== $cacheEnabled) {
            return false;
        }

        if ((string) ($data['last_cron_daily_at'] ?? '') !== (string) ($lastCronDailyAt ?? '')) {
            return false;
        }

        return (string) ($data['mail_fingerprint'] ?? '') === self::mailFingerprint($mailStatus);
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array{enabled: bool, transport: string, configured: bool} $mailStatus
     * @return array<string, mixed>
     */
    private static function refreshCronAndMail(
        array $snapshot,
        string $dbPath,
        ?string $lastCronDailyAt,
        array $mailStatus,
    ): array {
        $snapshot['cron'] = self::cronRuns($dbPath, $lastCronDailyAt);
        $snapshot['mail'] = self::mailSummary($mailStatus);

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array{enabled: bool, transport: string, configured: bool} $mailStatus
     */
    private static function writeCache(
        string $cacheFile,
        array $snapshot,
        bool $cacheEnabled,
        array $mailStatus,
        ?string $lastCronDailyAt,
    ): void {
        $dir = dirname($cacheFile);
        if (!is_dir($dir) && !@mkdir($dir, 02770, true)) {
            return;
        }

        $db = $snapshot['database'] ?? [];
        @file_put_contents($cacheFile, json_encode([
            'cached_at' => gmdate('c'),
            'db_fingerprint' => [
                'main' => (int) ($db['main_bytes'] ?? 0),
                'wal' => (int) ($db['wal_bytes'] ?? 0),
                'shm' => (int) ($db['shm_bytes'] ?? 0),
                'total' => (int) ($db['total_bytes'] ?? 0),
            ],
            'cache_enabled' => $cacheEnabled,
            'last_cron_daily_at' => $lastCronDailyAt,
            'mail_fingerprint' => self::mailFingerprint($mailStatus),
            'snapshot' => $snapshot,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array{enabled: bool, transport: string, configured: bool} $mailStatus
     */
    private static function mailFingerprint(array $mailStatus): string
    {
        return json_encode([
            (bool) ($mailStatus['enabled'] ?? false),
            (bool) ($mailStatus['configured'] ?? false),
            strtolower(trim((string) ($mailStatus['transport'] ?? ''))),
        ], JSON_THROW_ON_ERROR);
    }

    private static function journalMode(string $dbPath): string
    {
        if (!is_file($dbPath)) {
            return 'unknown';
        }

        try {
            $db = Database::openReadOnly($dbPath);
            $mode = $db->pdo()->query('PRAGMA journal_mode')->fetchColumn();

            return is_string($mode) ? strtolower($mode) : 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /**
     * @return array{
     *     hourly: array{at: string|null, ago: string|null},
     *     daily: array{at: string|null, ago: string|null},
     *     weekly: array{at: string|null, ago: string|null}
     * }
     */
    private static function cronRuns(string $dbPath, ?string $lastCronDailyAt): array
    {
        $runs = [
            'hourly' => null,
            'daily' => $lastCronDailyAt,
            'weekly' => null,
        ];

        if (!is_file($dbPath)) {
            return self::formatCronRuns($runs);
        }

        try {
            $db = Database::openReadOnly($dbPath);
            if (!self::tableExists($db->pdo(), 'maintenance_runs')) {
                return self::formatCronRuns($runs);
            }

            $stmt = $db->pdo()->query(
                "SELECT job, MAX(ran_at) AS ran_at
                 FROM maintenance_runs
                 WHERE job IN ('hourly', 'daily', 'weekly')
                 GROUP BY job"
            );
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $job = (string) ($row['job'] ?? '');
                $ranAt = (string) ($row['ran_at'] ?? '');
                if ($ranAt === '' || !array_key_exists($job, $runs)) {
                    continue;
                }

                if ($job === 'daily' && $runs['daily'] !== null) {
                    $existing = strtotime((string) $runs['daily']);
                    $candidate = strtotime($ranAt);
                    if ($existing !== false && $candidate !== false && $candidate <= $existing) {
                        continue;
                    }
                }

                $runs[$job] = $ranAt;
            }
        } catch (\Throwable) {
            return self::formatCronRuns($runs);
        }

        return self::formatCronRuns($runs);
    }

    /**
     * @param array{hourly: string|null, daily: string|null, weekly: string|null} $runs
     * @return array{
     *     hourly: array{at: string|null, ago: string|null},
     *     daily: array{at: string|null, ago: string|null},
     *     weekly: array{at: string|null, ago: string|null}
     * }
     */
    private static function formatCronRuns(array $runs): array
    {
        $formatted = [];
        foreach (['hourly', 'daily', 'weekly'] as $job) {
            $at = $runs[$job] ?? null;
            $formatted[$job] = [
                'at' => is_string($at) && $at !== '' ? $at : null,
                'ago' => self::relativeTimeLabel(is_string($at) ? $at : null),
            ];
        }

        return $formatted;
    }

    /**
     * @return array{enabled: bool, file_count: int, total_bytes: int, label: string}
     */
    private static function guestCacheStats(string $storagePath, bool $enabled): array
    {
        if (!$enabled) {
            return [
                'enabled' => false,
                'file_count' => 0,
                'total_bytes' => 0,
                'label' => 'Disabled',
            ];
        }

        $dir = rtrim($storagePath, '/') . '/cache/pages';
        if (!is_dir($dir)) {
            return [
                'enabled' => true,
                'file_count' => 0,
                'total_bytes' => 0,
                'label' => '0 files',
            ];
        }

        $fileCount = 0;
        $totalBytes = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $fileCount++;
            $totalBytes += (int) $file->getSize();
        }

        $sizeLabel = SiteRestore::formatBytes($totalBytes);
        $countLabel = $fileCount === 1 ? '1 file' : $fileCount . ' files';

        return [
            'enabled' => true,
            'file_count' => $fileCount,
            'total_bytes' => $totalBytes,
            'label' => "{$countLabel} · {$sizeLabel}",
        ];
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1"
        );
        $stmt->execute(['name' => $table]);

        return (bool) $stmt->fetchColumn();
    }
}