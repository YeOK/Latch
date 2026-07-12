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

    public function testLogSourceCheckSkippedWhenServerLogsDisabled(): void
    {
        $root = sys_get_temp_dir() . '/latch-doctor-' . bin2hex(random_bytes(4));
        $configDir = $root . '/config';
        mkdir($configDir, 0777, true);

        file_put_contents($configDir . '/default.php', '<?php return [
            "paths" => ["storage" => ' . var_export($root . '/storage', true) . '],
            "logs" => ["server_logs_enabled" => false],
        ];');

        $report = Doctor::run(new Config($configDir));

        $this->assertNull($this->findCheckOrNull($report['checks'], 'logs_server_sources'));
        $this->assertSame([], Doctor::logSourceIssuesForAudit(new Config($configDir)));
    }

    public function testLogSourceCheckPassesWhenReadable(): void
    {
        $root = sys_get_temp_dir() . '/latch-doctor-' . bin2hex(random_bytes(4));
        $configDir = $root . '/config';
        $storageDir = $root . '/storage/logs';
        $logPath = $storageDir . '/access.log';
        mkdir($configDir, 0777, true);
        mkdir($storageDir, 0755, true);
        file_put_contents($logPath, "ok\n");

        file_put_contents($configDir . '/default.php', '<?php return [
            "paths" => ["storage" => ' . var_export($root . '/storage', true) . '],
        ];');
        file_put_contents($configDir . '/local.php', '<?php return [
            "logs" => [
                "server_logs_enabled" => true,
                "sources" => [[
                    "id" => "httpd.access",
                    "label" => "Apache access",
                    "group" => "Web server",
                    "path" => ' . var_export($logPath, true) . ',
                    "format" => "text",
                ]],
            ],
        ];');

        $report = Doctor::run(new Config($configDir));
        $check = $this->findCheck($report['checks'], 'logs_server_sources');

        $this->assertTrue($check['ok']);
        $this->assertSame([], Doctor::logSourceIssuesForAudit(new Config($configDir)));
    }

    public function testLogSourceCheckFailsOnPermissionDenied(): void
    {
        $root = sys_get_temp_dir() . '/latch-doctor-' . bin2hex(random_bytes(4));
        $configDir = $root . '/config';
        $storageDir = $root . '/storage/logs';
        $logPath = $storageDir . '/error.log';
        mkdir($configDir, 0777, true);
        mkdir($storageDir, 0750, true);
        chmod($root . '/storage', 0750);
        file_put_contents($logPath, "secret\n");
        chmod($logPath, 0000);

        file_put_contents($configDir . '/default.php', '<?php return [
            "paths" => ["storage" => ' . var_export($root . '/storage', true) . '],
        ];');
        file_put_contents($configDir . '/local.php', '<?php return [
            "logs" => [
                "server_logs_enabled" => true,
                "sources" => [[
                    "id" => "httpd.error",
                    "label" => "Apache error",
                    "group" => "Web server",
                    "path" => ' . var_export($logPath, true) . ',
                    "format" => "text",
                ]],
            ],
        ];');

        $config = new Config($configDir);
        $report = Doctor::run($config);
        $check = $this->findCheck($report['checks'], 'logs_source_httpd_error');

        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('not readable by PHP', $check['detail']);
        $this->assertStringContainsString('setfacl', $check['detail']);

        $issues = Doctor::permissionIssuesForAudit($config);
        $logIssues = array_values(array_filter(
            $issues,
            static fn (string $issue): bool => str_contains($issue, 'httpd.error'),
        ));
        $this->assertCount(1, $logIssues);
        $this->assertStringContainsString('not readable by PHP', $logIssues[0]);

        chmod($logPath, 0644);
    }

    public function testLogSourceCheckFailsOnDeniedPath(): void
    {
        $root = sys_get_temp_dir() . '/latch-doctor-' . bin2hex(random_bytes(4));
        $configDir = $root . '/config';
        mkdir($configDir, 0777, true);
        file_put_contents($configDir . '/local.php', '<?php return [];');

        $deniedPath = $configDir . '/local.php';

        file_put_contents($configDir . '/default.php', '<?php return [
            "paths" => ["storage" => ' . var_export($root . '/storage', true) . '],
        ];');
        file_put_contents($configDir . '/local.php', '<?php return [
            "logs" => [
                "server_logs_enabled" => true,
                "sources" => [[
                    "id" => "secrets.leak",
                    "label" => "Secrets",
                    "group" => "Other",
                    "path" => ' . var_export($deniedPath, true) . ',
                    "format" => "text",
                ]],
            ],
        ];');

        $report = Doctor::run(new Config($configDir));
        $check = $this->findCheck($report['checks'], 'logs_source_secrets_leak');

        $this->assertFalse($check['ok']);
        $this->assertStringContainsString('path denied by logs registry', $check['detail']);
    }

    public function testAuditFixHintsIncludesSetfaclForUnreadableLogSources(): void
    {
        $hints = Doctor::auditFixHints(['httpd.error: not readable by PHP (/var/log/httpd/latch-error.log) — sudo setfacl -m u:apache:r …']);

        $this->assertStringContainsString('setfacl', $hints);
        $this->assertStringContainsString('INSTALL-FEDORA.md', $hints);
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
        $check = $this->findCheckOrNull($checks, $name);
        if ($check === null) {
            $this->fail('Check not found: ' . $name);
        }

        return $check;
    }

    /**
     * @param list<array{layer: string, name: string, ok: bool, detail: string}> $checks
     * @return ?array{layer: string, name: string, ok: bool, detail: string}
     */
    private function findCheckOrNull(array $checks, string $name): ?array
    {
        foreach ($checks as $check) {
            if ($check['name'] === $name) {
                return $check;
            }
        }

        return null;
    }
}