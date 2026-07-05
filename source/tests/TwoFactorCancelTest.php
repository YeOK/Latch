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
use Latch\Models\UserRepository;
use Latch\Models\UserSessionRepository;
use PHPUnit\Framework\TestCase;

final class TwoFactorCancelTest extends TestCase
{
    private Database $db;
    private Session $session;
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
                role TEXT NOT NULL DEFAULT "admin",
                created_at TEXT NOT NULL,
                totp_enabled INTEGER NOT NULL DEFAULT 1
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
             VALUES (5, "admin", "admin@test", "hash", "admin", "2026-01-01T00:00:00+00:00");'
        );

        $config = new Config(LATCH_ROOT . '/config');
        $this->session = new Session();
        $this->session->start($config);
        $this->auth = new Auth(
            $this->session,
            new UserRepository($this->db),
            new UserSessionRepository($this->db),
            new Request($config),
            new Csrf($this->session),
        );
    }

    protected function tearDown(): void
    {
        $this->session->destroy();
        $_SESSION = [];
    }

    public function testClearTotpPendingRemovesPendingSession(): void
    {
        $user = $this->db->pdo()->query('SELECT * FROM users WHERE id = 5')->fetch();
        $this->assertIsArray($user);

        $this->auth->beginTotpPending($user);
        $this->assertTrue($this->auth->hasTotpPending());
        $this->assertNull($this->auth->user());

        $this->auth->clearTotpPending();

        $this->assertFalse($this->auth->hasTotpPending());
        $this->assertNull($this->session->get('totp_pending_user_id'));
        $this->assertNull($this->session->get('totp_setup_required'));
    }
}