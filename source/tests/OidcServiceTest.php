<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Config;
use Latch\Core\Database;
use Latch\Core\InputValidator;
use Latch\Core\Oidc\OidcConfig;
use Latch\Core\Oidc\OidcHttpClient;
use Latch\Core\Oidc\OidcProviderProfile;
use Latch\Core\Oidc\OidcService;
use Latch\Core\RateLimiter;
use Latch\Core\RegistrationGuard;
use Latch\Core\Request;
use Latch\Core\SecurityLog;
use Latch\Core\Turnstile;
use Latch\Models\OidcIdentityRepository;
use Latch\Models\SettingRepository;
use Latch\Models\UserRepository;
use PHPUnit\Framework\TestCase;

final class OidcServiceTest extends TestCase
{
    private Database $db;
    private UserRepository $users;
    private OidcIdentityRepository $identities;
    private OidcService $service;
    private SettingRepository $settings;
    private Config $config;

    protected function setUp(): void
    {
        $this->db = new Database(':memory:');
        $pdo = $this->db->pdo();
        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL,
                email TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT "member",
                theme_mode TEXT NOT NULL DEFAULT "system",
                email_verified_at TEXT,
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

        $this->config = new Config(LATCH_ROOT . '/config');
        $this->settings = new SettingRepository($this->db);
        $this->users = new UserRepository($this->db, new InputValidator($this->config));
        $this->identities = new OidcIdentityRepository($this->db);
        $this->service = $this->makeService(new Request($this->config));
    }

    private function makeService(Request $request): OidcService
    {
        $logPath = sys_get_temp_dir() . '/latch-oidc-test-' . getmypid() . '.log';
        @unlink($logPath);

        return new OidcService(
            new OidcConfig($this->config, $this->settings),
            new OidcHttpClient(),
            $this->identities,
            $this->users,
            $this->settings,
            new InputValidator($this->config),
            $this->config,
            new RegistrationGuard(
                $this->settings,
                new RateLimiter($this->db),
                new Turnstile('', ''),
                new SecurityLog($logPath),
                $request,
            ),
        );
    }

    public function testSuggestUsernameFromGithubLogin(): void
    {
        $username = $this->service->suggestUsername(new OidcProviderProfile(
            '42',
            'person@example.com',
            true,
            'cool-dev',
            'Cool Dev',
        ));

        $this->assertSame('cool-dev', $username);
    }

    public function testSuggestUsernameAvoidsCollision(): void
    {
        $this->users->createSocial('cool-dev', 'other@example.com');

        $username = $this->service->suggestUsername(new OidcProviderProfile(
            '99',
            'new@example.com',
            true,
            'cool-dev',
            null,
        ));

        $this->assertNotSame('cool-dev', $username);
        $this->assertMatchesRegularExpression('/^cool-dev\d+$/', $username);
    }

    public function testResolveUserLinksExistingVerifiedEmail(): void
    {
        $existing = $this->users->createSocial('member1', 'person@example.com');
        $this->users->markEmailVerified((int) $existing['id']);

        $resolved = $this->service->resolveUser('google', new OidcProviderProfile(
            'google-subject-1',
            'person@example.com',
            true,
            null,
            'Person',
        ));

        $this->assertFalse($resolved['created']);
        $this->assertSame((int) $existing['id'], (int) $resolved['user']['id']);
        $this->assertNotNull($this->identities->findByProviderSubject('google', 'google-subject-1'));
    }

    public function testResolveUserCreatesAccountWithVerifiedEmail(): void
    {
        $resolved = $this->service->resolveUser('github', new OidcProviderProfile(
            '777',
            'newperson@example.com',
            true,
            'newperson',
            'New Person',
        ));

        $this->assertTrue($resolved['created']);
        $this->assertSame('newperson@example.com', $resolved['user']['email']);
        $this->assertTrue($this->users->isEmailVerified($resolved['user']));
        $this->assertNotNull($this->identities->findByProviderSubject('github', '777'));
    }

    public function testResolveUserRequiresVerifiedEmailForNewAccount(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->resolveUser('github', new OidcProviderProfile(
            '888',
            'hidden@example.com',
            false,
            'hidden',
            null,
        ));
    }

    public function testResolveUserRejectsNewAccountWhenRegistrationDisabled(): void
    {
        $this->settings->setBool('allow_registration', false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Registration is disabled.');

        $this->service->resolveUser('github', new OidcProviderProfile(
            '900',
            'blocked@example.com',
            true,
            'blocked',
            null,
        ));
    }

    public function testResolveUserStillLinksExistingAccountWhenRegistrationDisabled(): void
    {
        $this->settings->setBool('allow_registration', false);
        $existing = $this->users->createSocial('member1', 'person@example.com');
        $this->users->markEmailVerified((int) $existing['id']);

        $resolved = $this->service->resolveUser('google', new OidcProviderProfile(
            'google-subject-2',
            'person@example.com',
            true,
            null,
            'Person',
        ));

        $this->assertFalse($resolved['created']);
        $this->assertSame((int) $existing['id'], (int) $resolved['user']['id']);
    }

    public function testResolveUserRejectsNewAccountWhenIpRateLimited(): void
    {
        $rateLimiter = new RateLimiter($this->db);
        $request = new Request($this->config);
        for ($i = 0; $i < 3; $i++) {
            $rateLimiter->recordRegistrationAttempt($request->ip(), true);
        }

        $this->service = $this->makeService($request);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Too many registration attempts');

        $this->service->resolveUser('github', new OidcProviderProfile(
            '901',
            'ratelimited@example.com',
            true,
            'ratelimited',
            null,
        ));
    }
}