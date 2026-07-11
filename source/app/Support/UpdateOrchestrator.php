<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Support;

use Latch\Core\Cache;
use Latch\Core\Config;
use Latch\Core\CronService;
use Latch\Core\Database;


/**
 * Safe upgrade orchestrator: lock → backup → migrate → db-check → cron → audit → cache → unlock.
 */
final class UpdateOrchestrator
{
    private bool $weLocked = false;

    /**
     * @param array<string, string> $opts
     * @param callable(): array<int, string> $auditRunner
     * @param callable(Database): CronService $cronFactory
     */
    public function __construct(
        private readonly Config $config,
        private readonly array $opts,
        private readonly mixed $auditRunner,
        private readonly mixed $cronFactory,
    ) {
    }

    public function run(): int
    {
        $dryRun = isset($this->opts['dry-run']);
        $storagePath = (string) $this->config->get('paths.storage');
        $dbPath = (string) $this->config->get('database.path');
        $steps = [];

        $steps[] = ['name' => 'lock', 'fn' => fn (): bool => $this->stepLock($storagePath, $dryRun)];
        $steps[] = ['name' => 'backup', 'fn' => fn (): bool => $this->stepBackup($storagePath, $dbPath, $dryRun)];
        $steps[] = ['name' => 'core_files', 'fn' => fn (): bool => $this->stepCoreFiles($dryRun)];
        $steps[] = ['name' => 'migrate', 'fn' => fn (): bool => $this->stepMigrate($dryRun)];
        $steps[] = ['name' => 'db-check', 'fn' => fn (): bool => $this->stepDbCheck($dbPath, $dryRun)];
        $steps[] = ['name' => 'cron_daily', 'fn' => fn (): bool => $this->stepCronDaily($dryRun)];
        $steps[] = ['name' => 'audit', 'fn' => fn (): bool => $this->stepAudit($dryRun)];
        $steps[] = ['name' => 'cache', 'fn' => fn (): bool => $this->stepCache($storagePath, $dryRun)];
        $steps[] = ['name' => 'unlock', 'fn' => fn (): bool => $this->stepUnlock($storagePath, $dryRun)];

        $exitCodes = [
            'lock' => 10,
            'backup' => 11,
            'migrate' => 12,
            'db-check' => 13,
            'cache' => 14,
            'unlock' => 15,
            'cron_daily' => 16,
            'audit' => 17,
        ];

        $version = (string) $this->config->get('app.version', 'unknown');
        fwrite(STDOUT, "Latch update — app.version {$version}\n\n");

        foreach ($steps as $step) {
            $name = $step['name'];
            fwrite(STDOUT, "==> {$name}\n");
            try {
                if (!$step['fn']()) {
                    $code = $exitCodes[$name] ?? 1;
                    if (in_array($name, ['migrate', 'db-check'], true)) {
                        self::printRollbackPlaybook();
                    }
                    if ($this->weLocked && !isset($this->opts['skip-lock'])) {
                        fwrite(STDERR, "Site remains locked. Run: php bin/latch lock off\n");
                    }
                    return $code;
                }
            } catch (\Throwable $e) {
                fwrite(STDERR, $e->getMessage() . "\n");
                $code = $exitCodes[$name] ?? 1;
                if (in_array($name, ['migrate', 'db-check'], true)) {
                    self::printRollbackPlaybook();
                }
                return $code;
            }
        }

        fwrite(STDOUT, "\nUpdate complete.\n");
        return 0;
    }

    private function stepLock(string $storagePath, bool $dryRun): bool
    {
        if (isset($this->opts['skip-lock'])) {
            fwrite(STDOUT, "    Skipped (--skip-lock)\n");
            return true;
        }

        if (SiteLock::isLocked($storagePath)) {
            fwrite(STDOUT, "    Already locked\n");
            return true;
        }

        if ($dryRun) {
            fwrite(STDOUT, "    Would enable site lock\n");
            return true;
        }

        SiteLock::enable($storagePath, 'Latch update in progress', 'cli');
        $this->weLocked = true;
        fwrite(STDOUT, "    Site lock enabled\n");
        return true;
    }

    private function stepBackup(string $storagePath, string $dbPath, bool $dryRun): bool
    {
        if (isset($this->opts['skip-backup'])) {
            fwrite(STDOUT, "    Skipped (--skip-backup)\n");
            return true;
        }

        if ($dryRun) {
            fwrite(STDOUT, "    Would create WAL-safe backup\n");
            return true;
        }

        $result = SiteMaintenance::createBackup(
            $storagePath,
            $dbPath,
            (defined('LATCH_ROOT') ? LATCH_ROOT : dirname(__DIR__, 2)) . '/config/local.php',
        );
        if (!$result['ok']) {
            fwrite(STDERR, '    ' . $result['message'] . "\n");
            if ($this->weLocked) {
                SiteLock::disable($storagePath);
            }
            return false;
        }

        fwrite(STDOUT, '    ' . $result['message'] . "\n");
        return true;
    }

    private function stepCoreFiles(bool $dryRun): bool
    {
        $assumeReady = isset($this->opts['assume-files-ready'])
            || !function_exists('stream_isatty')
            || !stream_isatty(STDOUT);

        fwrite(STDOUT, "    Replace app/, bin/, public/, database/migrations/, vendor/ before continuing.\n");

        if ($assumeReady) {
            fwrite(STDOUT, "    Assuming core files are already in place (--assume-files-ready).\n");
            return true;
        }

        if ($dryRun) {
            fwrite(STDOUT, "    Would pause for operator confirmation\n");
            return true;
        }

        fwrite(STDOUT, "    Press Enter when ready (or re-run with --assume-files-ready)… ");
        fgets(STDIN);
        return true;
    }

    private function stepMigrate(bool $dryRun): bool
    {
        if ($dryRun) {
            fwrite(STDOUT, "    Would run SiteMigrate::migrate()\n");
            return true;
        }

        $result = SiteMigrate::migrate($this->config);
        fwrite(STDOUT, "    Migrations applied: {$result['applied']} (mode: {$result['mode']})\n");
        return true;
    }

    private function stepDbCheck(string $dbPath, bool $dryRun): bool
    {
        if ($dryRun) {
            fwrite(STDOUT, "    Would run db-check\n");
            return true;
        }

        if (!is_file($dbPath)) {
            fwrite(STDERR, "    Database missing: {$dbPath}\n");
            return false;
        }

        $report = SqliteIntegrity::run($dbPath);
        fwrite(STDOUT, SqliteIntegrity::formatHuman($report) . "\n");
        return $report['ok'];
    }

    private function stepCronDaily(bool $dryRun): bool
    {
        if (isset($this->opts['skip-cron'])) {
            fwrite(STDOUT, "    Skipped (--skip-cron)\n");
            return true;
        }

        if ($dryRun) {
            fwrite(STDOUT, "    Would run cron daily\n");
            return true;
        }

        $db = Database::fromConfig($this->config);
        $cron = ($this->cronFactory)($db);
        $stats = $cron->runDaily();
        foreach ($stats as $key => $value) {
            fwrite(STDOUT, "    {$key}: {$value}\n");
        }
        return true;
    }

    private function stepAudit(bool $dryRun): bool
    {
        if (isset($this->opts['skip-audit'])) {
            fwrite(STDOUT, "    Skipped (--skip-audit)\n");
            return true;
        }

        if ($dryRun) {
            fwrite(STDOUT, "    Would run security audit\n");
            return true;
        }

        $issues = ($this->auditRunner)();
        if ($issues === []) {
            fwrite(STDOUT, "    audit: OK\n");
            return true;
        }

        Doctor::writeAuditFailure($issues, '    ');
        return false;
    }

    private function stepCache(string $storagePath, bool $dryRun): bool
    {
        if (isset($this->opts['skip-cache'])) {
            fwrite(STDOUT, "    Skipped (--skip-cache)\n");
            return true;
        }

        if ($dryRun) {
            fwrite(STDOUT, "    Would clear page + Twig caches\n");
            return true;
        }

        $cleared = SiteMaintenance::clearCaches(new Cache($storagePath), $storagePath);
        fwrite(STDOUT, "    Purged page cache: {$cleared['page_cache']}, Twig files: {$cleared['twig_files']}\n");
        return true;
    }

    private function stepUnlock(string $storagePath, bool $dryRun): bool
    {
        if (isset($this->opts['skip-lock'])) {
            fwrite(STDOUT, "    Skipped unlock (--skip-lock)\n");
            return true;
        }

        if (!$this->weLocked) {
            fwrite(STDOUT, "    Lock was not enabled by this run\n");
            return true;
        }

        if ($dryRun) {
            fwrite(STDOUT, "    Would disable site lock\n");
            return true;
        }

        $result = SiteLock::disable($storagePath);
        if ($result !== 'ok') {
            fwrite(STDERR, "    Could not remove site lock (permission denied?).\n");
            fwrite(STDERR, '    Run: ' . SiteLock::cliUnlockHint() . "\n");
            return false;
        }

        fwrite(STDOUT, "    Site lock disabled\n");
        return true;
    }

    public static function printRollbackPlaybook(): void
    {
        fwrite(STDERR, "\nRollback:\n");
        fwrite(STDERR, "  1. Site should still be locked (verify: php bin/latch lock status)\n");
        fwrite(STDERR, "  2. php bin/latch restore --latest\n");
        fwrite(STDERR, "  3. php bin/latch lock off\n");
        fwrite(STDERR, "  4. If restore fails: see docs/UPGRADE.md § Manual recovery\n");
    }
}