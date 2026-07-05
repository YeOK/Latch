<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Config;
use Latch\Core\Database;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    public function testAppliesSqlitePragmasFromOptions(): void
    {
        $path = sys_get_temp_dir() . '/latch-db-' . bin2hex(random_bytes(4)) . '.sqlite';
        $db = new Database($path, false, [
            'busy_timeout_ms' => 7500,
            'cache_size_kib' => 4096,
            'mmap_size' => 1048576,
        ]);

        $pdo = $db->pdo();
        $this->assertSame(7500, (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
        $this->assertSame(-4096, (int) $pdo->query('PRAGMA cache_size')->fetchColumn());
        $this->assertSame(1048576, (int) $pdo->query('PRAGMA mmap_size')->fetchColumn());
        $this->assertSame('wal', strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn()));
    }

    public function testUsesDefaultSqliteOptionsWhenOmitted(): void
    {
        $path = sys_get_temp_dir() . '/latch-db-' . bin2hex(random_bytes(4)) . '.sqlite';
        $db = new Database($path);
        $pdo = $db->pdo();

        $this->assertSame(5000, (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
        $this->assertSame(-8192, (int) $pdo->query('PRAGMA cache_size')->fetchColumn());
        $this->assertSame(0, (int) $pdo->query('PRAGMA mmap_size')->fetchColumn());
    }

    public function testFromConfigReadsSqliteSection(): void
    {
        $configDir = sys_get_temp_dir() . '/latch-config-' . bin2hex(random_bytes(4));
        mkdir($configDir);
        $dbPath = sys_get_temp_dir() . '/latch-db-' . bin2hex(random_bytes(4)) . '.sqlite';

        file_put_contents($configDir . '/default.php', '<?php return [];');
        file_put_contents($configDir . '/local.php', '<?php return ' . var_export([
            'database' => [
                'path' => $dbPath,
                'sqlite' => [
                    'busy_timeout_ms' => 2500,
                    'cache_size_kib' => 2048,
                    'mmap_size' => 65536,
                ],
            ],
        ], true) . ';');

        $db = Database::fromConfig(new Config($configDir));
        $pdo = $db->pdo();

        $this->assertSame(2500, (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
        $this->assertSame(-2048, (int) $pdo->query('PRAGMA cache_size')->fetchColumn());
        $this->assertSame(65536, (int) $pdo->query('PRAGMA mmap_size')->fetchColumn());
    }

    public function testCacheSizeZeroLeavesSqliteDefault(): void
    {
        $path = sys_get_temp_dir() . '/latch-db-' . bin2hex(random_bytes(4)) . '.sqlite';
        $baseline = new \PDO('sqlite:' . $path);
        $expected = (int) $baseline->query('PRAGMA cache_size')->fetchColumn();

        $db = new Database($path, false, ['cache_size_kib' => 0]);
        $this->assertSame($expected, (int) $db->pdo()->query('PRAGMA cache_size')->fetchColumn());
    }
}