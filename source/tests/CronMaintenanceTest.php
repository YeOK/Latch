<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\CronService;
use Latch\Core\Database;
use Latch\Core\RateLimiter;
use Latch\Models\ApiAuditLogRepository;
use Latch\Models\EmailChangeRepository;
use Latch\Models\EmailVerificationRepository;
use Latch\Models\NotificationRepository;
use Latch\Models\OAuthTokenRepository;
use Latch\Models\PasswordResetRepository;
use Latch\Models\SettingRepository;
use Latch\Models\UserRepository;
use Latch\Models\UserSessionRepository;
use PHPUnit\Framework\TestCase;

final class CronMaintenanceTest extends TestCase
{
    private string $dbPath;
    private string $cacheDir;
    private Database $db;
    private CronService $cron;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-cron-test-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->cacheDir = sys_get_temp_dir() . '/latch-cron-cache-' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir, 0775, true);

        $this->db = new Database($this->dbPath);
        $pdo = $this->db->pdo();

        $pdo->exec(
            "CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
             CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT,
                role TEXT DEFAULT 'member',
                banned_at TEXT,
                banned_until TEXT,
                deleted_at TEXT
             );
             CREATE TABLE login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip_address TEXT,
                username TEXT,
                attempted_at TEXT,
                success INTEGER
             );
             CREATE TABLE user_notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                event_type TEXT,
                message TEXT,
                url TEXT,
                created_at TEXT,
                read_at TEXT
             );
             CREATE TABLE maintenance_runs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job TEXT,
                ran_at TEXT,
                duration_ms INTEGER,
                stats_json TEXT
             );
             CREATE TABLE password_resets (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                token_hash TEXT,
                expires_at TEXT,
                used_at TEXT,
                created_at TEXT
             );
             CREATE TABLE email_verifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                email TEXT,
                token_hash TEXT,
                expires_at TEXT,
                verified_at TEXT,
                created_at TEXT
             );
             CREATE TABLE email_change_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                new_email TEXT,
                token_hash TEXT,
                expires_at TEXT,
                used_at TEXT,
                created_at TEXT
             );
             CREATE TABLE user_sessions (
                id TEXT PRIMARY KEY,
                user_id INTEGER,
                fingerprint TEXT NOT NULL DEFAULT '',
                ip_address TEXT NOT NULL DEFAULT '',
                user_agent TEXT NOT NULL DEFAULT '',
                last_seen_at TEXT NOT NULL,
                created_at TEXT NOT NULL,
                revoked_at TEXT
             );
             CREATE TABLE oauth_access_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token_hash TEXT,
                client_id TEXT,
                user_id INTEGER,
                scopes TEXT,
                expires_at TEXT,
                created_at TEXT,
                revoked_at TEXT
             );
             CREATE TABLE oauth_authorization_codes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code_hash TEXT,
                client_id TEXT,
                user_id INTEGER,
                scopes TEXT,
                redirect_uri TEXT,
                code_challenge TEXT,
                code_challenge_method TEXT DEFAULT 'S256',
                expires_at TEXT,
                used_at TEXT,
                created_at TEXT
             );
             CREATE TABLE oauth_refresh_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token_hash TEXT,
                access_token_id INTEGER,
                client_id TEXT,
                user_id INTEGER,
                scopes TEXT,
                expires_at TEXT,
                created_at TEXT,
                revoked_at TEXT
             );
             CREATE TABLE api_audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id TEXT,
                user_id INTEGER,
                method TEXT,
                path TEXT,
                status_code INTEGER,
                ip_address TEXT,
                created_at TEXT
             );
             INSERT INTO settings (key, value) VALUES
                ('cron_login_attempts_retain_days', '14'),
                ('cron_read_notification_retain_days', '90'),
                ('cron_notification_cap', '500'),
                ('cron_deleted_user_retain_days', '30');"
        );

        $old = gmdate('c', time() - (20 * 86400));
        $pdo->exec("INSERT INTO login_attempts (ip_address, attempted_at, success) VALUES ('1.2.3.4', '{$old}', 0)");

        $recentRead = gmdate('c', time() - (10 * 86400));
        $staleRead = gmdate('c', time() - (100 * 86400));
        $staleUnread = gmdate('c', time() - (100 * 86400));
        $pdo->exec(
            "INSERT INTO users (id, username) VALUES (1, 'member');
             INSERT INTO user_notifications (user_id, event_type, message, url, created_at, read_at) VALUES
                (1, 'topic_reply', 'recent read', '/', '{$recentRead}', '{$recentRead}'),
                (1, 'topic_reply', 'stale read', '/', '{$staleRead}', '{$staleRead}'),
                (1, 'topic_reply', 'stale unread', '/', '{$staleUnread}', NULL);"
        );

        $expiredBan = gmdate('c', time() - 3600);
        $pdo->exec(
            "INSERT INTO users (id, username, banned_at, banned_until) VALUES (2, 'banned', '{$expiredBan}', '{$expiredBan}');"
        );

        $this->cron = $this->buildCron();
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            unlink($this->dbPath);
        }

        if (is_dir($this->cacheDir)) {
            array_map('unlink', glob($this->cacheDir . '/*') ?: []);
            rmdir($this->cacheDir);
        }
    }

    public function testDailyPrunesStaleLoginAttempts(): void
    {
        $stats = $this->cron->runDaily();

        $this->assertSame(1, $stats['login_attempts']);
        $this->assertSame(0, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn());
    }

    public function testDailyPrunesOnlyStaleReadNotifications(): void
    {
        $stats = $this->cron->runDaily();

        $this->assertSame(1, $stats['read_notifications']);
        $remaining = (int) $this->db->pdo()->query('SELECT COUNT(*) FROM user_notifications')->fetchColumn();
        $this->assertSame(2, $remaining);

        $unread = (int) $this->db->pdo()->query(
            'SELECT COUNT(*) FROM user_notifications WHERE read_at IS NULL'
        )->fetchColumn();
        $this->assertSame(1, $unread);
    }

    public function testDailySweepsExpiredBans(): void
    {
        $stats = $this->cron->runDaily();

        $this->assertSame(1, $stats['expired_bans']);
        $row = $this->db->pdo()->query('SELECT banned_until FROM users WHERE id = 2')->fetch();
        $this->assertNull($row['banned_until']);
    }

    public function testDailyDoesNotTouchPageCacheFiles(): void
    {
        $cacheFile = $this->cacheDir . '/guest-page.cache';
        file_put_contents($cacheFile, 'cached');

        $this->cron->runDaily();

        $this->assertFileExists($cacheFile);
        $this->assertSame('cached', file_get_contents($cacheFile));
    }

    public function testDailyRecordsMaintenanceRun(): void
    {
        $this->cron->runDaily();

        $count = (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM maintenance_runs WHERE job = 'daily'"
        )->fetchColumn();
        $this->assertSame(1, $count);
        $this->assertNotNull((new SettingRepository($this->db))->get('last_cron_daily_at'));
    }

    public function testDailyPrunesOrphanedUserDependencies(): void
    {
        $this->db->pdo()->exec(
            'INSERT INTO email_verifications (user_id, email, token_hash, expires_at, created_at)
             VALUES (99, "ghost@test", "hash", "2099-01-01T00:00:00+00:00", "2026-01-01");'
        );

        $stats = $this->cron->runDaily();

        $this->assertSame(1, $stats['user_orphans']);
        $this->assertSame(0, (int) $this->db->pdo()->query('SELECT COUNT(*) FROM email_verifications')->fetchColumn());
    }

    public function testDailyPurgesExpiredDeletedUsers(): void
    {
        $expired = gmdate('c', time() - (31 * 86400));
        $this->db->pdo()->exec(
            "INSERT INTO users (id, username, role, deleted_at) VALUES (3, 'deleted_3', 'member', '{$expired}');
             INSERT INTO users (id, username, role, deleted_at) VALUES (4, 'deleted_4', 'member', '" . gmdate('c') . "');"
        );

        $stats = $this->cron->runDaily();

        $this->assertSame(1, $stats['deleted_users']);
        $this->assertFalse($this->db->pdo()->query('SELECT id FROM users WHERE id = 3')->fetchColumn());
        $this->assertSame(4, (int) $this->db->pdo()->query('SELECT id FROM users WHERE id = 4')->fetchColumn());
    }

    public function testHourlyPrunesRateLimitTablesWhenPresent(): void
    {
        $this->db->pdo()->exec(
            "CREATE TABLE search_attempts (id INTEGER PRIMARY KEY, ip_address TEXT, searched_at TEXT);
             CREATE TABLE api_rate_attempts (id INTEGER PRIMARY KEY, bucket_key TEXT, requested_at TEXT);
             INSERT INTO search_attempts (ip_address, searched_at) VALUES ('9.9.9.9', '2000-01-01T00:00:00+00:00');
             INSERT INTO api_rate_attempts (bucket_key, requested_at) VALUES ('guest:1', '2000-01-01T00:00:00+00:00');"
        );

        $stats = $this->cron->runHourly();

        $this->assertGreaterThanOrEqual(1, $stats['search_attempts']);
        $this->assertGreaterThanOrEqual(1, $stats['api_rate_attempts']);
    }

    private function buildCron(): CronService
    {
        $db = $this->db;

        return new CronService(
            $db,
            new SettingRepository($db),
            new PasswordResetRepository($db),
            new EmailVerificationRepository($db),
            new EmailChangeRepository($db),
            new UserSessionRepository($db),
            new UserRepository($db),
            new NotificationRepository($db),
            new RateLimiter($db),
            new OAuthTokenRepository($db),
            new ApiAuditLogRepository($db),
            null,
        );
    }
}