<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Core\Config;
use Latch\Core\Database;
use Latch\Core\InputValidator;
use Latch\Core\Oidc\OidcConfig;
use Latch\Core\Oidc\OidcHttpClient;
use Latch\Core\Oidc\OidcProviderProfile;
use Latch\Core\Oidc\OidcService;
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
             CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);'
        );

        $config = new Config(LATCH_ROOT . '/config');
        $this->settings = new SettingRepository($this->db);
        $this->users = new UserRepository($this->db, new InputValidator($config));
        $this->identities = new OidcIdentityRepository($this->db);
        $this->service = new OidcService(
            new OidcConfig($config, $this->settings),
            new OidcHttpClient(),
            $this->identities,
            $this->users,
            $this->settings,
            new InputValidator($config),
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
}