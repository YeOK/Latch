<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Support;

use Latch\Core\Database;
use PDO;
use PDOException;
use RuntimeException;

/**
 * SQLite integrity and foreign-key checks for operator CLI.
 */
final class SqliteIntegrity
{
    /**
     * @return array{
     *   ok: bool,
     *   checks: list<array{name: string, ok: bool, detail: string|list<string>}>,
     *   duration_ms: int
     * }
     */
    public static function run(string $dbPath, bool $quickOnly = false, bool $skipForeignKeys = false): array
    {
        if (!is_file($dbPath)) {
            throw new RuntimeException('Database file not found: ' . $dbPath);
        }

        $started = hrtime(true);
        $checks = [];
        $stagingPath = null;

        try {
            $pdo = self::openConnection($dbPath);
        } catch (\Throwable $e) {
            if (!self::isReadonlyOpenError($e)) {
                throw new RuntimeException('Cannot open database: ' . $e->getMessage(), 0, $e);
            }

            $stagingPath = self::stageReadOnlyCopy($dbPath);
            try {
                $pdo = self::openConnection($stagingPath);
            } catch (\Throwable $inner) {
                throw new RuntimeException(
                    'Cannot open database read-only. The SQLite file or storage/database/ may be owned by the web server user. Try: sudo -u apache php bin/latch db-check'
                    . "\nDetail: " . $inner->getMessage(),
                    0,
                    $inner,
                );
            }
        }

        try {
            if ($quickOnly) {
                $checks[] = self::checkQuick($pdo);
            } else {
                $checks[] = self::checkIntegrity($pdo);
            }

            if (!$skipForeignKeys) {
                $checks[] = self::checkForeignKeys($pdo);
            }
        } finally {
            if ($stagingPath !== null && is_file($stagingPath)) {
                @unlink($stagingPath);
            }
        }

        $ok = true;
        foreach ($checks as $check) {
            if (!$check['ok']) {
                $ok = false;
            }
        }

        return [
            'ok' => $ok,
            'checks' => $checks,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ];
    }

    private static function openConnection(string $dbPath): PDO
    {
        return Database::openReadOnly($dbPath)->pdo();
    }

    private static function isReadonlyOpenError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'readonly')
            || str_contains($message, 'read-only')
            || str_contains($message, 'attempt to write a readonly database');
    }

    /**
     * WAL-safe read-only copy into a user-writable temp path (no writes to live storage/).
     */
    private static function stageReadOnlyCopy(string $dbPath): string
    {
        $dest = sys_get_temp_dir() . '/latch-dbcheck-' . bin2hex(random_bytes(8)) . '.sqlite';

        if (class_exists(\SQLite3::class, false)) {
            $in = new \SQLite3($dbPath, \SQLITE3_OPEN_READONLY);
            $out = new \SQLite3($dest);
            try {
                if ($in->backup($out)) {
                    return $dest;
                }
            } finally {
                $in->close();
                $out->close();
                if (!is_file($dest) || filesize($dest) === 0) {
                    @unlink($dest);
                }
            }
        }

        if (!@copy($dbPath, $dest)) {
            throw new RuntimeException('Cannot copy database for read-only check: ' . $dbPath);
        }

        return $dest;
    }

    /**
     * @return array{name: string, ok: bool, detail: string|list<string>}
     */
    private static function checkIntegrity(PDO $pdo): array
    {
        try {
            $rows = $pdo->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            return [
                'name' => 'integrity_check',
                'ok' => false,
                'detail' => $e->getMessage(),
            ];
        }

        $rows = array_map(static fn ($row): string => (string) $row, $rows);
        $ok = count($rows) === 1 && ($rows[0] ?? '') === 'ok';

        return [
            'name' => 'integrity_check',
            'ok' => $ok,
            'detail' => $ok ? 'ok' : $rows,
        ];
    }

    /**
     * @return array{name: string, ok: bool, detail: string|list<string>}
     */
    private static function checkQuick(PDO $pdo): array
    {
        try {
            $result = (string) $pdo->query('PRAGMA quick_check')->fetchColumn();
        } catch (PDOException $e) {
            return [
                'name' => 'quick_check',
                'ok' => false,
                'detail' => $e->getMessage(),
            ];
        }

        $ok = $result === 'ok';

        return [
            'name' => 'quick_check',
            'ok' => $ok,
            'detail' => $result,
        ];
    }

    /**
     * @return array{name: string, ok: bool, detail: string|list<string>}
     */
    private static function checkForeignKeys(PDO $pdo): array
    {
        try {
            $pdo->exec('PRAGMA foreign_keys = ON');
            $violations = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
        } catch (PDOException $e) {
            return [
                'name' => 'foreign_key_check',
                'ok' => false,
                'detail' => $e->getMessage(),
            ];
        }

        $partition = ForeignKeyCheck::partitionViolations($violations);
        $messages = [];
        foreach ($partition['unexpected'] as $row) {
            $messages[] = ForeignKeyCheck::formatViolation($row);
        }

        $detail = 'ok';
        if ($messages !== []) {
            $detail = $messages;
        } elseif ($partition['allowed_orphans'] > 0) {
            $detail = 'ok (' . $partition['allowed_orphans'] . ' author orphan(s) ignored)';
        }

        return [
            'name' => 'foreign_key_check',
            'ok' => $messages === [],
            'detail' => $detail,
        ];
    }

    /**
     * @param array{ok: bool, checks: list<array{name: string, ok: bool, detail: string|list<string>}>, duration_ms: int} $report
     */
    public static function formatHuman(array $report): string
    {
        $status = $report['ok'] ? 'OK' : 'FAILED';
        $problems = 0;
        foreach ($report['checks'] as $check) {
            if (!$check['ok']) {
                $problems++;
            }
        }

        $lines = ['db-check: ' . $status . ($report['ok'] ? '' : " — {$problems} problem(s)")];
        foreach ($report['checks'] as $check) {
            $detail = $check['detail'];
            if (is_array($detail)) {
                $detailText = $detail === [] ? 'ok' : implode('; ', $detail);
            } else {
                $detailText = $detail;
            }
            $lines[] = '  [' . $check['name'] . '] ' . $detailText;
        }
        $lines[] = '  (' . $report['duration_ms'] . ' ms)';

        return implode("\n", $lines);
    }

    /**
     * @param array{ok: bool, checks: list<array{name: string, ok: bool, detail: string|list<string>}>, duration_ms: int} $report
     * @return array<string, mixed>
     */
    public static function toJson(string $dbPath, array $report): array
    {
        return [
            'ok' => $report['ok'],
            'database' => $dbPath,
            'checks' => $report['checks'],
            'duration_ms' => $report['duration_ms'],
        ];
    }
}