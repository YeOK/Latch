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
use Latch\Core\SecretCipher;
use Latch\Models\SettingRepository;

/**
 * Four-layer install preflight (host, vendor, instance, permissions).
 */
final class Doctor
{
    /**
     * @return array{ok: bool, checks: list<array{layer: string, name: string, ok: bool, detail: string}>}
     */
    public static function run(Config $config): array
    {
        $checks = [];
        $checks[] = self::checkPhpVersion();
        $checks = array_merge($checks, self::checkExtensions());
        $checks = array_merge($checks, self::checkVendor());
        $checks = array_merge($checks, self::checkInstance($config));
        $checks = array_merge($checks, self::checkPermissions($config));

        $ok = true;
        foreach ($checks as $check) {
            if (!$check['ok']) {
                $ok = false;
            }
        }

        return ['ok' => $ok, 'checks' => $checks];
    }

    /**
     * @return array{layer: string, name: string, ok: bool, detail: string}
     */
    private static function checkPhpVersion(): array
    {
        $ok = PHP_VERSION_ID >= 80200;

        return [
            'layer' => '1-host',
            'name' => 'php_version',
            'ok' => $ok,
            'detail' => 'PHP ' . PHP_VERSION . ($ok ? '' : ' (need >= 8.2)'),
        ];
    }

    /**
     * @return list<array{layer: string, name: string, ok: bool, detail: string}>
     */
    private static function checkExtensions(): array
    {
        $required = ['pdo', 'pdo_sqlite', 'mbstring', 'json', 'session', 'sodium'];
        $checks = [];
        foreach ($required as $ext) {
            $loaded = extension_loaded($ext);
            $checks[] = [
                'layer' => '1-host',
                'name' => 'ext_' . $ext,
                'ok' => $loaded,
                'detail' => $loaded ? 'loaded' : 'missing — install php-' . $ext,
            ];
        }

        $xml = extension_loaded('dom') && extension_loaded('xml');
        $checks[] = [
            'layer' => '1-host',
            'name' => 'ext_xml',
            'ok' => true,
            'detail' => $xml ? 'loaded (PHPUnit)' : 'warn: install php-xml for bin/latch test',
        ];

        return $checks;
    }

    /**
     * @return list<array{layer: string, name: string, ok: bool, detail: string}>
     */
    private static function checkVendor(): array
    {
        $autoload = (defined('LATCH_ROOT') ? LATCH_ROOT : dirname(__DIR__, 2)) . '/vendor/autoload.php';
        $ok = is_file($autoload);

        return [[
            'layer' => '2-vendor',
            'name' => 'composer_vendor',
            'ok' => $ok,
            'detail' => $ok ? 'vendor/autoload.php present' : 'run composer install in source/',
        ]];
    }

    /**
     * @return list<array{layer: string, name: string, ok: bool, detail: string}>
     */
    private static function checkInstance(Config $config): array
    {
        $checks = [];
        $localPath = (defined('LATCH_ROOT') ? LATCH_ROOT : dirname(__DIR__, 2)) . '/config/local.php';
        $localOk = is_file($localPath);
        $checks[] = [
            'layer' => '3-instance',
            'name' => 'local_config',
            'ok' => $localOk,
            'detail' => $localOk ? 'config/local.php present' : 'run php bin/latch install',
        ];

        $dbPath = (string) $config->get('database.path');
        $dbOk = is_file($dbPath);
        $checks[] = [
            'layer' => '3-instance',
            'name' => 'database_file',
            'ok' => $dbOk,
            'detail' => $dbOk ? $dbPath : 'database not found',
        ];

        if ($dbOk && class_exists(Database::class)) {
            try {
                $db = Database::openReadOnly($dbPath);
                $pending = (new Migrator($db, (defined('LATCH_ROOT') ? LATCH_ROOT : dirname(__DIR__, 2)) . '/database/migrations'))->pendingCount();
                $checks[] = [
                    'layer' => '3-instance',
                    'name' => 'migrations',
                    'ok' => $pending === 0,
                    'detail' => $pending === 0 ? 'up to date' : "{$pending} pending — run php bin/latch migrate",
                ];
            } catch (\Throwable $e) {
                $checks[] = [
                    'layer' => '3-instance',
                    'name' => 'migrations',
                    'ok' => false,
                    'detail' => 'cannot read database: ' . $e->getMessage(),
                ];
            }

            $cipher = new SecretCipher($config);
            $keyOk = $cipher->hasConfiguredKey();
            $checks[] = [
                'layer' => '3-instance',
                'name' => 'encryption_key',
                'ok' => $keyOk,
                'detail' => $keyOk
                    ? 'security.encryption_key set (required for admin 2FA)'
                    : 'missing or invalid — run php bin/latch security-bootstrap',
            ];

            try {
                $settings = new SettingRepository(Database::openReadOnly($dbPath));
                $checks[] = self::checkCronFreshness($settings);
            } catch (\Throwable $e) {
                $checks[] = [
                    'layer' => '3-instance',
                    'name' => 'cron_daily',
                    'ok' => false,
                    'detail' => 'cannot read cron settings: ' . $e->getMessage(),
                ];
            }
        }

        return $checks;
    }

    /**
     * @return array{layer: string, name: string, ok: bool, detail: string}
     */
    private static function checkCronFreshness(SettingRepository $settings): array
    {
        $lastDaily = trim((string) $settings->get('last_cron_daily_at', ''));
        if ($lastDaily === '') {
            return [
                'layer' => '3-instance',
                'name' => 'cron_daily',
                'ok' => false,
                'detail' => 'daily cron never recorded — install cron (scripts/install-cron.sh) and run php bin/latch cron daily',
            ];
        }

        $ranAt = strtotime($lastDaily);
        if ($ranAt === false) {
            return [
                'layer' => '3-instance',
                'name' => 'cron_daily',
                'ok' => false,
                'detail' => 'last_cron_daily_at invalid — run php bin/latch cron daily',
            ];
        }

        $ageHours = (int) floor((time() - $ranAt) / 3600);
        $ok = $ageHours <= 48;

        return [
            'layer' => '3-instance',
            'name' => 'cron_daily',
            'ok' => $ok,
            'detail' => $ok
                ? "last daily cron {$ageHours}h ago"
                : "daily cron stale ({$ageHours}h ago) — check crontab or systemd timers",
        ];
    }

    /**
     * @return list<array{layer: string, name: string, ok: bool, detail: string}>
     */
    private static function checkPermissions(Config $config): array
    {
        $checks = [];
        $storagePath = (string) $config->get('paths.storage');
        if (is_dir($storagePath)) {
            $mode = fileperms($storagePath) & 0777;
            $worldOk = ($mode & 0005) === 0;
            $checks[] = [
                'layer' => '4-perms',
                'name' => 'storage_private',
                'ok' => $worldOk,
                'detail' => $worldOk
                    ? 'storage/ not world-accessible'
                    : 'storage/ is world-accessible (' . substr(sprintf('%o', $mode), -4) . ')',
            ];
        }

        $dbPath = (string) $config->get('database.path');
        if (is_file($dbPath)) {
            $mode = fileperms($dbPath) & 0777;
            $worldOk = ($mode & 0004) === 0;
            $checks[] = [
                'layer' => '4-perms',
                'name' => 'database_private',
                'ok' => $worldOk,
                'detail' => $worldOk
                    ? 'database not world-readable (' . substr(sprintf('%o', $mode), -4) . ')'
                    : 'database is world-readable (' . substr(sprintf('%o', $mode), -4) . ') — chmod 660',
            ];
        }

        $localPath = (defined('LATCH_ROOT') ? LATCH_ROOT : dirname(__DIR__, 2)) . '/config/local.php';
        if (is_file($localPath)) {
            $mode = fileperms($localPath) & 0777;
            $worldOk = ($mode & 0004) === 0;
            $checks[] = [
                'layer' => '4-perms',
                'name' => 'local_config_private',
                'ok' => $worldOk,
                'detail' => $worldOk
                    ? 'local.php not world-readable'
                    : 'local.php is world-readable — chmod 640',
            ];
        }

        return $checks;
    }

    /**
     * @param array{ok: bool, checks: list<array{layer: string, name: string, ok: bool, detail: string}>} $report
     */
    public static function formatHuman(array $report): string
    {
        $status = $report['ok'] ? 'OK' : 'ISSUES FOUND';
        $lines = ['doctor: ' . $status];
        $currentLayer = '';
        foreach ($report['checks'] as $check) {
            if ($check['layer'] !== $currentLayer) {
                $currentLayer = $check['layer'];
                $lines[] = '';
                $lines[] = '[' . $currentLayer . ']';
            }
            $mark = $check['ok'] ? 'ok' : 'FAIL';
            $lines[] = '  ' . $check['name'] . ': ' . $mark . ' — ' . $check['detail'];
        }

        return implode("\n", $lines);
    }
}