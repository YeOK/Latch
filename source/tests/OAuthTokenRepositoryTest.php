<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Database;
use Latch\Core\OAuthScopes;
use Latch\Models\OAuthTokenRepository;
use PHPUnit\Framework\TestCase;

final class OAuthTokenRepositoryTest extends TestCase
{
    private string $dbPath;
    private Database $db;
    private OAuthTokenRepository $tokens;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-oauth-test-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $pdo = $this->db->pdo();
        $pdo->exec(<<<'SQL'
            CREATE TABLE users (id INTEGER PRIMARY KEY);
            CREATE TABLE oauth_authorization_codes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code_hash TEXT NOT NULL UNIQUE,
                client_id TEXT NOT NULL,
                user_id INTEGER NOT NULL,
                scopes TEXT NOT NULL,
                redirect_uri TEXT NOT NULL,
                code_challenge TEXT NOT NULL,
                code_challenge_method TEXT NOT NULL DEFAULT 'S256',
                expires_at TEXT NOT NULL,
                used_at TEXT,
                created_at TEXT NOT NULL
            );
            CREATE TABLE oauth_access_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token_hash TEXT NOT NULL UNIQUE,
                client_id TEXT NOT NULL,
                user_id INTEGER,
                scopes TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL,
                revoked_at TEXT
            );
            CREATE TABLE oauth_refresh_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token_hash TEXT NOT NULL UNIQUE,
                access_token_id INTEGER NOT NULL,
                client_id TEXT NOT NULL,
                user_id INTEGER NOT NULL,
                scopes TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL,
                revoked_at TEXT
            );
            CREATE TABLE oauth_clients (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_id TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                redirect_uris TEXT NOT NULL DEFAULT '[]',
                scopes TEXT NOT NULL DEFAULT '["read"]',
                rate_limit_per_minute INTEGER NOT NULL DEFAULT 60,
                is_confidential INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL,
                revoked_at TEXT
            );
            INSERT INTO users (id) VALUES (1);
            INSERT INTO oauth_clients (client_id, name, created_at)
            VALUES ('latch_app', 'Test App', '2026-01-01T00:00:00+00:00');
            SQL);

        $this->tokens = new OAuthTokenRepository($this->db);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testClientCredentialsTokenIsValid(): void
    {
        $issued = $this->tokens->issueClientCredentialsToken('latch_test', [OAuthScopes::READ]);
        $row = $this->tokens->findValidAccessToken($issued['access_token']);

        $this->assertNotNull($row);
        $this->assertSame('latch_test', $row['client_id']);
        $this->assertNull($row['user_id']);
    }

    public function testAuthorizationCodePkceRoundTrip(): void
    {
        $verifier = 'challenge-verifier-string-1234567890';
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $code = $this->tokens->storeAuthorizationCode(
            'latch_app',
            1,
            [OAuthScopes::READ],
            'https://app.example/oauth/callback',
            $challenge,
            'S256',
        );

        $consumed = $this->tokens->consumeAuthorizationCode(
            $code,
            'latch_app',
            'https://app.example/oauth/callback',
            $verifier,
        );

        $this->assertNotNull($consumed);
        $this->assertSame(1, $consumed['user_id']);

        $second = $this->tokens->consumeAuthorizationCode(
            $code,
            'latch_app',
            'https://app.example/oauth/callback',
            $verifier,
        );
        $this->assertNull($second);
    }

    public function testListAndRevokeAuthorizedAppsForUser(): void
    {
        $tokens = $this->tokens->issueAuthorizationCodeTokens(
            'latch_app',
            1,
            [OAuthScopes::READ, OAuthScopes::MESSAGES_READ],
        );

        $apps = $this->tokens->listAuthorizedAppsForUser(1);
        $this->assertCount(1, $apps);
        $this->assertSame('latch_app', $apps[0]['client_id']);
        $this->assertSame('Test App', $apps[0]['client_name']);
        $this->assertContains(OAuthScopes::MESSAGES_READ, $apps[0]['scopes']);

        $this->assertTrue($this->tokens->userHasActiveDelegation(1, 'latch_app'));
        $this->assertNotNull($this->tokens->findValidAccessToken($tokens['access_token']));
        $this->assertNotNull($this->tokens->findValidRefreshToken($tokens['refresh_token'], 'latch_app'));

        $revoked = $this->tokens->revokeUserDelegation(1, 'latch_app');
        $this->assertGreaterThan(0, $revoked);

        $this->assertSame([], $this->tokens->listAuthorizedAppsForUser(1));
        $this->assertFalse($this->tokens->userHasActiveDelegation(1, 'latch_app'));
        $this->assertNull($this->tokens->findValidAccessToken($tokens['access_token']));
        $this->assertNull($this->tokens->findValidRefreshToken($tokens['refresh_token'], 'latch_app'));
    }
}