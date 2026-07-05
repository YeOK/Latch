<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Config;
use Latch\Core\Oidc\OidcConfig;
use Latch\Core\Oidc\OidcService;
use Latch\Core\Database;
use Latch\Core\InputValidator;
use Latch\Core\RateLimiter;
use Latch\Core\RegistrationGuard;
use Latch\Core\Request;
use Latch\Core\SecurityLog;
use Latch\Core\Turnstile;
use Latch\Models\OidcIdentityRepository;
use Latch\Models\SettingRepository;
use Latch\Models\UserRepository;
use PHPUnit\Framework\TestCase;

/**
 * Offline OIDC flow checks (authorization URL shape — no live Google/GitHub calls).
 */
final class OidcAuthorizationTest extends TestCase
{
    public function testGoogleAuthorizationUrlContainsClientAndState(): void
    {
        $service = $this->makeService($this->configWithGoogle());
        $url = $service->buildAuthorizationUrl('google', 'state-token-123');

        $this->assertStringContainsString('accounts.google.com', $url);
        $this->assertStringContainsString('client_id=google-client-id', $url);
        $this->assertStringContainsString('state=state-token-123', $url);
        $this->assertStringContainsString('response_type=code', $url);
    }

    public function testGithubAuthorizationUrlContainsClientAndState(): void
    {
        $service = $this->makeService($this->configWithGithub());
        $url = $service->buildAuthorizationUrl('github', 'gh-state-456');

        $this->assertStringContainsString('github.com/login/oauth/authorize', $url);
        $this->assertStringContainsString('client_id=github-client-id', $url);
        $this->assertStringContainsString('state=gh-state-456', $url);
    }

    private function makeService(Config $config): OidcService
    {
        $db = new Database(':memory:');
        $pdo = $db->pdo();
        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT,
                email TEXT,
                password_hash TEXT,
                role TEXT NOT NULL DEFAULT "member",
                created_at TEXT NOT NULL
             );
             CREATE TABLE oidc_identities (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                provider TEXT NOT NULL,
                provider_subject TEXT NOT NULL,
                email TEXT,
                created_at TEXT NOT NULL,
                UNIQUE (provider, provider_subject)
             );
             CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);
             CREATE TABLE registration_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip_address TEXT NOT NULL,
                attempted_at TEXT NOT NULL,
                success INTEGER NOT NULL DEFAULT 0
             );'
        );

        $settings = new SettingRepository($db);
        $request = new Request($config);
        $logPath = sys_get_temp_dir() . '/latch-oidc-auth-' . getmypid() . '.log';
        @unlink($logPath);

        return new OidcService(
            new OidcConfig($config, $settings),
            new \Latch\Core\Oidc\OidcHttpClient(),
            new OidcIdentityRepository($db),
            new UserRepository($db, new InputValidator($config)),
            $settings,
            new InputValidator($config),
            $config,
            new RegistrationGuard(
                $settings,
                new RateLimiter($db),
                new Turnstile('', ''),
                new SecurityLog($logPath),
                $request,
            ),
        );
    }

    private function configWithGoogle(): Config
    {
        $dir = sys_get_temp_dir() . '/latch-oidc-cfg-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/default.php', '<?php return [
            "site" => ["url" => "https://forum.example.com"],
            "oidc" => [
                "google" => [
                    "client_id" => "google-client-id",
                    "client_secret" => "google-secret",
                ],
            ],
        ];');

        return new Config($dir);
    }

    private function configWithGithub(): Config
    {
        $dir = sys_get_temp_dir() . '/latch-oidc-cfg-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/default.php', '<?php return [
            "site" => ["url" => "https://forum.example.com"],
            "oidc" => [
                "github" => [
                    "client_id" => "github-client-id",
                    "client_secret" => "github-secret",
                ],
            ],
        ];');

        return new Config($dir);
    }
}