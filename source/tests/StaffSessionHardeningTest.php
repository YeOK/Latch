<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Auth;
use Latch\Core\Config;
use Latch\Core\Csrf;
use Latch\Core\Database;
use Latch\Core\Request;
use Latch\Core\SecurityLog;
use Latch\Core\Session;
use Latch\Models\UserRepository;
use Latch\Models\UserSessionRepository;
use PHPUnit\Framework\TestCase;

final class StaffSessionHardeningTest extends TestCase
{
    private Database $db;
    private string $logPath;
    private string $configDir;
    private Session $session;
    private UserSessionRepository $sessions;
    private Auth $auth;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit-Agent/1.0';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';

        $this->db = new Database(':memory:');
        $pdo = $this->db->pdo();
        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT,
                email TEXT,
                password_hash TEXT,
                role TEXT NOT NULL DEFAULT "member",
                created_at TEXT NOT NULL,
                password_changed_at TEXT,
                last_login_at TEXT,
                banned_at TEXT,
                banned_until TEXT,
                deleted_at TEXT,
                locked_until TEXT,
                failed_login_count INTEGER DEFAULT 0
             );
             CREATE TABLE user_sessions (
                id TEXT PRIMARY KEY,
                user_id INTEGER NOT NULL,
                fingerprint TEXT NOT NULL DEFAULT "",
                ip_address TEXT NOT NULL DEFAULT "",
                user_agent TEXT NOT NULL DEFAULT "",
                last_seen_at TEXT NOT NULL,
                created_at TEXT NOT NULL,
                revoked_at TEXT
             );
             INSERT INTO users (id, username, email, password_hash, role, created_at)
             VALUES (1, "admin", "admin@test", "hash", "admin", "2026-01-01T00:00:00+00:00");'
        );

        $this->configDir = sys_get_temp_dir() . '/latch-staff-cfg-' . bin2hex(random_bytes(4));
        mkdir($this->configDir);
        file_put_contents($this->configDir . '/default.php', '<?php return [
            "security" => [
                "session_name" => "latch_session",
                "staff_session_fingerprint" => true,
                "staff_idle_timeout_minutes" => 30,
                "staff_stepup_ttl_minutes" => 15,
                "staff_login_alerts" => false,
            ],
        ];');

        $this->logPath = sys_get_temp_dir() . '/latch-staff-sec-' . bin2hex(random_bytes(4)) . '.log';
        $config = new Config($this->configDir);
        $this->session = new Session();
        $this->session->start($config);
        $this->sessions = new UserSessionRepository($this->db);
        $this->auth = new Auth(
            $this->session,
            new UserRepository($this->db),
            $this->sessions,
            new Request($config),
            new Csrf($this->session),
            null,
            $config,
            new SecurityLog($this->logPath),
            null,
        );
    }

    protected function tearDown(): void
    {
        $this->session->destroy();
        $_SESSION = [];
        @unlink($this->logPath);
        array_map('unlink', glob($this->configDir . '/*') ?: []);
        @rmdir($this->configDir);
    }

    public function testFingerprintMismatchLogsOutStaff(): void
    {
        $user = ['id' => 1, 'username' => 'admin', 'role' => 'admin', 'password_changed_at' => null];
        $this->auth->login($user);

        $sid = $this->session->id();
        $this->assertNotNull($this->auth->user());

        $this->db->pdo()->prepare(
            'UPDATE user_sessions SET fingerprint = :f WHERE id = :id'
        )->execute(['f' => str_repeat('0', 64), 'id' => $sid]);

        $this->assertNull($this->auth->user());
        $log = (string) file_get_contents($this->logPath);
        $this->assertStringContainsString('session_fingerprint_mismatch', $log);
    }

    public function testIdleTimeoutLogsOutStaff(): void
    {
        $user = ['id' => 1, 'username' => 'admin', 'role' => 'admin', 'password_changed_at' => null];
        $this->auth->login($user);
        $sid = $this->session->id();

        $old = gmdate('c', time() - 3600);
        $this->db->pdo()->prepare(
            'UPDATE user_sessions SET last_seen_at = :t WHERE id = :id'
        )->execute(['t' => $old, 'id' => $sid]);

        $this->assertNull($this->auth->user());
        $log = (string) file_get_contents($this->logPath);
        $this->assertStringContainsString('session_idle_timeout', $log);
    }

    public function testMemberIgnoresFingerprintEnforcement(): void
    {
        $this->db->pdo()->exec(
            'INSERT INTO users (id, username, email, password_hash, role, created_at)
             VALUES (2, "member", "m@test", "hash", "member", "2026-01-01T00:00:00+00:00")'
        );
        $user = ['id' => 2, 'username' => 'member', 'role' => 'member', 'password_changed_at' => null];
        $this->auth->login($user);
        $sid = $this->session->id();
        $this->db->pdo()->prepare(
            'UPDATE user_sessions SET fingerprint = :f WHERE id = :id'
        )->execute(['f' => str_repeat('a', 64), 'id' => $sid]);

        $loaded = $this->auth->user();
        $this->assertNotNull($loaded);
        $this->assertSame(2, (int) $loaded['id']);
    }

    public function testStepUpWindow(): void
    {
        $user = ['id' => 1, 'username' => 'admin', 'role' => 'admin', 'password_changed_at' => null];
        $this->auth->login($user);

        $this->assertFalse($this->auth->hasRecentStaffStepUp());
        $this->auth->markStaffStepUp();
        $this->assertTrue($this->auth->hasRecentStaffStepUp());
    }

    public function testHasFingerprintTracksPriorDevices(): void
    {
        $this->assertFalse($this->sessions->hasFingerprint(1, 'abc'));
        $this->sessions->register('s1', 1, 'abc', '1.2.3.4', 'UA');
        $this->assertTrue($this->sessions->hasFingerprint(1, 'abc'));
        $this->sessions->revoke('s1', 1);
        $this->assertTrue($this->sessions->hasFingerprint(1, 'abc'));
    }
}
