<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Support;

use Latch\Core\Config;
use Latch\Core\Database;
use Latch\Core\Migrator;
use RuntimeException;

/**
 * Privilege-aware migrate: workdir + WAL-safe publish when live DB is not writable.
 */
final class SiteMigrate
{
    /**
     * @return array{applied: int, mode: 'direct'|'workdir'}
     */
    public static function migrate(Config $config, ?string $webUser = null): array
    {
        $dbPath = (string) $config->get('database.path');
        $webUser = trim((string) ($webUser ?? getenv('WEB_USER') ?: 'apache'));

        if (!is_file($dbPath)) {
            throw new RuntimeException('Database not found: ' . $dbPath);
        }

        if (self::isLiveDbWritable($dbPath)) {
            $db = new Database($dbPath);
            $applied = (new Migrator($db, self::latchRoot() . '/database/migrations'))->migrate();

            return ['applied' => $applied, 'mode' => 'direct'];
        }

        $workDb = sys_get_temp_dir() . '/latch-migrate-' . bin2hex(random_bytes(8)) . '.sqlite';
        try {
            Scripts::runSqliteBackup($dbPath, $workDb);
            putenv('LATCH_DB_PATH=' . $workDb);
            $db = new Database($workDb);
            $applied = (new Migrator($db, self::latchRoot() . '/database/migrations'))->migrate();
            self::publishDatabase($workDb, $dbPath, $webUser);

            return ['applied' => $applied, 'mode' => 'workdir'];
        } finally {
            if (is_file($workDb)) {
                @unlink($workDb);
            }
            foreach ([$workDb . '-wal', $workDb . '-shm'] as $sidecar) {
                if (is_file($sidecar)) {
                    @unlink($sidecar);
                }
            }
        }
    }

    private static function isLiveDbWritable(string $dbPath): bool
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }

        if (is_file($dbPath) && !is_writable($dbPath)) {
            return false;
        }

        $probe = $dir . '/.latch-write-probe-' . bin2hex(random_bytes(4));
        if (@file_put_contents($probe, '') === false) {
            return false;
        }
        @unlink($probe);

        return true;
    }

    private static function publishDatabase(string $workDb, string $liveDb, string $webUser): void
    {
        $backupScript = Scripts::sqliteBackupPath();
        Scripts::assertExists($backupScript);

        if (self::isLiveDbWritable($liveDb)) {
            Scripts::runSqliteBackup($workDb, $liveDb);
            return;
        }

        $cmd = 'sudo -n -u ' . escapeshellarg($webUser)
            . ' ' . escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg($backupScript)
            . ' ' . escapeshellarg($workDb)
            . ' ' . escapeshellarg($liveDb)
            . ' 2>&1';
        exec($cmd, $output, $code);
        if ($code === 0) {
            return;
        }

        throw new RuntimeException(
            'Cannot publish migrated database to ' . $liveDb . ".\n"
            . "Hint: sudo -u {$webUser} php bin/latch migrate\n"
            . 'Or: bash scripts/migrate-latch-db.sh',
        );
    }

    private static function latchRoot(): string
    {
        return defined('LATCH_ROOT') ? LATCH_ROOT : dirname(__DIR__, 2);
    }
}