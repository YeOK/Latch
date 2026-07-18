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
use Latch\Core\Session;
use Latch\Models\AuditLogRepository;
use Latch\Models\UserRepository;
use Latch\Models\UserSessionRepository;
use Latch\Support\Logs\LogViewer;
use Latch\Support\Logs\LogViewerException;
use PHPUnit\Framework\TestCase;

final class AdminLogsTest extends TestCase
{
    private Database $db;
    private Session $session;
    private UserSessionRepository $sessions;
    private Request $request;
    private Auth $auth;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $this->db = new Database(':memory:');
        $pdo = $this->db->pdo();
        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT,
                email TEXT,
                password_hash TEXT,
                role TEXT NOT NULL,
                created_at TEXT NOT NULL,
                totp_enabled_at TEXT
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
             CREATE TABLE audit_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                actor_id INTEGER,
                action TEXT NOT NULL,
                target_type TEXT NOT NULL,
                target_id INTEGER,
                ip_address TEXT,
                metadata TEXT,
                created_at TEXT NOT NULL
             );'
        );

        $config = new Config(LATCH_ROOT . '/config');
        $this->session = new Session();
        $this->session->start($config);
        $this->sessions = new UserSessionRepository($this->db);
        $this->request = new Request($config);
        $this->auth = new Auth(
            $this->session,
            new UserRepository($this->db),
            $this->sessions,
            $this->request,
            new Csrf($this->session),
            null,
            $config,
        );
    }

    /** Staff auth requires a registered user_sessions row (fingerprint / idle). */
    private function actAsStaff(int $userId): void
    {
        $this->session->set('user_id', $userId);
        $sid = $this->session->id();
        if ($sid === '') {
            return;
        }
        $fp = hash('sha256', $this->request->userAgent() . '|' . $this->request->ip());
        $this->sessions->register($sid, $userId, $fp, $this->request->ip(), $this->request->userAgent());
    }

    protected function tearDown(): void
    {
        $this->session->destroy();
        $_SESSION = [];
    }

    public function testModUserIsNotAdmin(): void
    {
        $this->insertUser(2, 'moduser', 'mod', null);

        $this->actAsStaff(2);
        $this->assertFalse($this->auth->isAdmin());
        $this->assertTrue($this->auth->isMod());
    }

    public function testAdminWithoutTotpWouldFailRequireAdminGate(): void
    {
        $this->insertUser(3, 'adminuser', 'admin', null);
        $this->actAsStaff(3);
        $this->assertTrue($this->auth->isAdmin());
        $this->assertNull($this->auth->user()['totp_enabled_at'] ?? null);
    }

    public function testUnknownSourceRejectedByParseFilters(): void
    {
        $viewer = LogViewer::fromConfig(new Config(LATCH_ROOT . '/config'));

        $this->expectException(LogViewerException::class);
        $this->expectExceptionMessage('Unknown log source.');

        $viewer->parseRequestFilters(['source' => 'does.not.exist']);
    }

    public function testMissingBuiltInSourceReturnsEmptyTail(): void
    {
        $root = sys_get_temp_dir() . '/latch-admin-logs-' . bin2hex(random_bytes(4));
        mkdir($root . '/config', 0700, true);
        mkdir($root . '/storage/logs', 0750, true);

        $default = require LATCH_ROOT . '/config/default.php';
        $default['paths']['storage'] = $root . '/storage';
        file_put_contents($root . '/config/default.php', '<?php return ' . var_export($default, true) . ';');

        $viewer = LogViewer::fromConfig(new Config($root . '/config'));
        $result = $viewer->tail('latch.security', 50, null, null);

        $this->assertSame('missing', $result['source']['status']);
        $this->assertSame([], $result['lines']);

        @rmdir($root . '/storage/logs');
        @rmdir($root . '/storage');
        @unlink($root . '/config/default.php');
        @rmdir($root . '/config');
        @rmdir($root);
    }

    public function testLogViewAuditCanBeRecorded(): void
    {
        $audit = new AuditLogRepository($this->db);
        $audit->record(1, 'logs.view', 'log_source', null, '127.0.0.1', [
            'source' => 'latch.security',
            'lines' => 0,
            'filters' => [],
        ]);

        $count = (int) $this->db->pdo()->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
        $this->assertSame(1, $count);
    }

    private function insertUser(int $id, string $username, string $role, ?string $totpEnabledAt): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO users (id, username, email, password_hash, role, created_at, totp_enabled_at)
             VALUES (:id, :username, :email, :hash, :role, :created_at, :totp_enabled_at)',
        );
        $stmt->execute([
            'id' => $id,
            'username' => $username,
            'email' => $username . '@test',
            'hash' => 'hash',
            'role' => $role,
            'created_at' => '2026-01-01T00:00:00+00:00',
            'totp_enabled_at' => $totpEnabledAt,
        ]);
    }
}