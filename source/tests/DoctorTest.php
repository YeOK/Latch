<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Config;
use Latch\Support\Doctor;
use PHPUnit\Framework\TestCase;

final class DoctorTest extends TestCase
{
    public function testEncryptionKeyCheckFailsWhenMissingOnInstalledInstance(): void
    {
        $root = sys_get_temp_dir() . '/latch-doctor-' . bin2hex(random_bytes(4));
        $configDir = $root . '/config';
        $storageDir = $root . '/storage/database';
        mkdir($configDir, 0777, true);
        mkdir($storageDir, 0777, true);

        $dbPath = $storageDir . '/latch.sqlite';
        touch($dbPath);

        file_put_contents($configDir . '/default.php', '<?php return [
            "database" => ["path" => ' . var_export($dbPath, true) . '],
            "paths" => ["storage" => ' . var_export($root . '/storage', true) . '],
            "security" => ["encryption_key" => ""],
        ];');
        file_put_contents($configDir . '/local.php', '<?php return ["site" => ["url" => "http://localhost", "name" => "Test"]];');

        $report = Doctor::run(new Config($configDir));
        $encryption = $this->findCheck($report['checks'], 'encryption_key');

        $this->assertFalse($encryption['ok']);
        $this->assertStringContainsString('security-bootstrap', $encryption['detail']);
    }

    public function testEncryptionKeyCheckPassesWithValidKey(): void
    {
        $root = sys_get_temp_dir() . '/latch-doctor-' . bin2hex(random_bytes(4));
        $configDir = $root . '/config';
        $storageDir = $root . '/storage/database';
        mkdir($configDir, 0777, true);
        mkdir($storageDir, 0777, true);

        $dbPath = $storageDir . '/latch.sqlite';
        touch($dbPath);
        $key = base64_encode(sodium_crypto_secretbox_keygen());

        file_put_contents($configDir . '/default.php', '<?php return [
            "database" => ["path" => ' . var_export($dbPath, true) . '],
            "paths" => ["storage" => ' . var_export($root . '/storage', true) . '],
            "security" => ["encryption_key" => ""],
        ];');
        file_put_contents($configDir . '/local.php', '<?php return [
            "site" => ["url" => "http://localhost", "name" => "Test"],
            "security" => ["encryption_key" => ' . var_export($key, true) . '],
        ];');

        $report = Doctor::run(new Config($configDir));
        $encryption = $this->findCheck($report['checks'], 'encryption_key');

        $this->assertTrue($encryption['ok']);
    }

    public function testPermissionIssuesForAuditEmptyWhenStorageAbsent(): void
    {
        $root = sys_get_temp_dir() . '/latch-doctor-' . bin2hex(random_bytes(4));
        $configDir = $root . '/config';
        mkdir($configDir, 0777, true);

        file_put_contents($configDir . '/default.php', '<?php return [
            "paths" => ["storage" => ' . var_export($root . '/storage', true) . '],
        ];');

        $issues = Doctor::permissionIssuesForAudit(new Config($configDir));

        $this->assertSame([], $issues);
    }

    public function testPermissionIssuesForAuditFlagsWorldReadableDatabase(): void
    {
        $root = sys_get_temp_dir() . '/latch-doctor-' . bin2hex(random_bytes(4));
        $configDir = $root . '/config';
        $storageDir = $root . '/storage/database';
        mkdir($configDir, 0777, true);
        mkdir($storageDir, 0777, true);

        $dbPath = $storageDir . '/latch.sqlite';
        touch($dbPath);
        chmod($dbPath, 0644);
        chmod($root . '/storage', 0750);

        file_put_contents($configDir . '/default.php', '<?php return [
            "database" => ["path" => ' . var_export($dbPath, true) . '],
            "paths" => ["storage" => ' . var_export($root . '/storage', true) . '],
        ];');

        $issues = Doctor::permissionIssuesForAudit(new Config($configDir));

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('database is world-readable', $issues[0]);
        $this->assertStringContainsString('chmod 660', $issues[0]);
    }

    public function testAuditFixHintsIncludesFixPermsForRootOwnedPluginStorage(): void
    {
        $issues = ['root-owned under storage/plugins/: spam-bridge — sudo chown -R apache:apache …'];

        $hints = Doctor::auditFixHints($issues);

        $this->assertStringContainsString('sudo latch fix-perms', $hints);
        $this->assertStringContainsString('sudo latch plugin enable', $hints);
    }

    public function testPermissionIssuesForAuditFlagsWorldAccessibleStorage(): void
    {
        $root = sys_get_temp_dir() . '/latch-doctor-' . bin2hex(random_bytes(4));
        $configDir = $root . '/config';
        $storageDir = $root . '/storage';
        mkdir($configDir, 0777, true);
        mkdir($storageDir, 0777, true);
        chmod($storageDir, 0755);

        file_put_contents($configDir . '/default.php', '<?php return [
            "paths" => ["storage" => ' . var_export($storageDir, true) . '],
        ];');

        $issues = Doctor::permissionIssuesForAudit(new Config($configDir));

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('storage/ is world-accessible', $issues[0]);
    }

    public function testCronDailyCheckFailsWhenNeverRun(): void
    {
        $root = sys_get_temp_dir() . '/latch-doctor-' . bin2hex(random_bytes(4));
        $configDir = $root . '/config';
        $storageDir = $root . '/storage/database';
        mkdir($configDir, 0777, true);
        mkdir($storageDir, 0777, true);

        $dbPath = $storageDir . '/latch.sqlite';
        touch($dbPath);

        file_put_contents($configDir . '/default.php', '<?php return [
            "database" => ["path" => ' . var_export($dbPath, true) . '],
            "paths" => ["storage" => ' . var_export($root . '/storage', true) . '],
            "security" => ["encryption_key" => ""],
        ];');
        file_put_contents($configDir . '/local.php', '<?php return [
            "security" => ["encryption_key" => ' . var_export(base64_encode(sodium_crypto_secretbox_keygen()), true) . '],
        ];');

        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->exec('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');

        $report = Doctor::run(new Config($configDir));
        $cron = $this->findCheck($report['checks'], 'cron_daily');

        $this->assertFalse($cron['ok']);
        $this->assertStringContainsString('daily cron never recorded', $cron['detail']);
    }

    /**
     * @param list<array{layer: string, name: string, ok: bool, detail: string}> $checks
     * @return array{layer: string, name: string, ok: bool, detail: string}
     */
    private function findCheck(array $checks, string $name): array
    {
        foreach ($checks as $check) {
            if ($check['name'] === $name) {
                return $check;
            }
        }

        $this->fail('Check not found: ' . $name);
    }
}