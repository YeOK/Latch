<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Plugins\PluginAuditor;
use Latch\Core\Request;
use Latch\Core\Plugins\PluginDatabase;
use Latch\Core\Plugins\PluginDatabaseManager;
use Latch\Core\Plugins\PluginManifest;
use Latch\Core\Plugins\PluginSettingsStore;
use Latch\Core\Plugins\PostSaveContext;
use Latch\Plugins\SpamBridge\AkismetClient;
use Latch\Plugins\SpamBridge\RegistrationEnforcer;
use Latch\Plugins\SpamBridge\HttpTransport;
use Latch\Plugins\SpamBridge\PluginConfig;
use Latch\Plugins\SpamBridge\Settings;
use Latch\Plugins\SpamBridge\SpamChecker;
use Latch\Plugins\SpamBridge\SpamLog;
use Latch\Plugins\SpamBridge\StopForumSpamClient;
use PHPUnit\Framework\TestCase;

final class SpamBridgePluginTest extends TestCase
{
    private string $pluginDir;
    private string $storageRoot;

    protected function setUp(): void
    {
        $this->pluginDir = CatalogPath::plugin('spam-bridge');
        $this->storageRoot = sys_get_temp_dir() . '/latch-spam-bridge-' . bin2hex(random_bytes(4));
        mkdir($this->storageRoot . '/plugins/spam-bridge', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->storageRoot);
    }

    public function testPluginPassesAudit(): void
    {
        $root = dirname(__DIR__);
        $auditor = new PluginAuditor($root, $root . '/plugins', $root . '/storage');
        $report = $auditor->auditPath($this->pluginDir);

        $this->assertTrue($report->passed(), $report->toHuman());
    }

    public function testManifestDeclaresDatabaseAndNetwork(): void
    {
        $manifest = PluginManifest::fromDirectory($this->pluginDir);

        $this->assertTrue($manifest->databaseEnabled);
        $this->assertTrue($manifest->settingsSchema->hasAdminUi());
        $this->assertSame('spam-bridge', $manifest->slug);
        $this->assertFalse($manifest->bundled);
    }

    public function testAkismetClientParsesSpamResponse(): void
    {
        $transport = new HttpTransport(static fn (): string => 'true');
        $client = new AkismetClient('test-key', $transport);

        $result = $client->commentCheck(['blog' => 'https://forum.test', 'user_ip' => '1.2.3.4']);

        $this->assertTrue($result['spam']);
        $this->assertNull($result['error']);
    }

    public function testStopForumSpamClientParsesJsonMatch(): void
    {
        $json = '{"success":1,"ip":{"appears":1,"frequency":255,"confidence":99.95}}';
        $transport = new HttpTransport(static fn (): string => $json);
        $client = new StopForumSpamClient($transport);

        $result = $client->check(['ip' => '91.186.18.61']);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['matches']);
        $this->assertTrue($result['matches'][0]['appears']);
        $this->assertSame(255, $result['matches'][0]['frequency']);
    }

    public function testBlocksPostWhenAkismetReturnsSpam(): void
    {
        $transport = new HttpTransport(static fn (string $method): ?string =>
            $method === 'POST' ? 'true' : null
        );

        $checker = $this->checker(
            settings: new Settings('akismet', 1, 90, true, true, false),
            transport: $transport,
            akismetKey: 'secret',
        );

        $reason = $checker->checkPost($this->postContext('Buy cheap meds now.'));

        $this->assertSame(
            'Your submission was flagged as spam. Contact an administrator if you believe this is an error.',
            $reason,
        );
    }

    public function testStrictnessZeroLogsButDoesNotBlock(): void
    {
        $transport = new HttpTransport(static fn (string $method): ?string =>
            $method === 'POST' ? 'true' : null
        );

        $checker = $this->checker(
            settings: new Settings('akismet', 0, 90, true, true, false),
            transport: $transport,
            akismetKey: 'secret',
        );

        $this->assertNull($checker->checkPost($this->postContext('Spam body')));
    }

    public function testBlocksPostWhenStopForumSpamReturnsBlacklistHit(): void
    {
        $json = '{"success":1,"email":{"appears":1,"frequency":255,"confidence":99.95}}';
        $transport = new HttpTransport(static fn (string $method, string $url): ?string =>
            $method === 'GET' && str_contains($url, 'stopforumspam.org') ? $json : null
        );

        $checker = $this->checker(
            settings: new Settings('stop_forum_spam', 1, 90, false, true, false),
            transport: $transport,
            akismetKey: '',
        );

        $ctx = $this->postContext('Hello', ['id' => 3, 'role' => 'member', 'username' => 'member', 'email' => 'testing@xrumer.ru']);
        $reason = $checker->checkPost($ctx);

        $this->assertSame(
            'Your submission was flagged as spam. Contact an administrator if you believe this is an error.',
            $reason,
        );
    }

    public function testReloadsProviderSettingBetweenChecks(): void
    {
        $calls = 0;
        $transport = new HttpTransport(static function (string $method, string $url) use (&$calls): ?string {
            if ($method !== 'GET' || !str_contains($url, 'stopforumspam.org')) {
                return null;
            }

            $calls++;

            return '{"success":1,"email":{"appears":1,"frequency":255,"confidence":99.95}}';
        });

        $checker = $this->checker(
            settings: new Settings('akismet', 1, 90, false, true, false),
            transport: $transport,
            akismetKey: '',
        );

        $ctx = $this->postContext('Hello', ['id' => 3, 'role' => 'member', 'username' => 'member', 'email' => 'testing@xrumer.ru']);
        $this->assertNull($checker->checkPost($ctx));
        $this->assertSame(0, $calls);

        $this->saveSettings(new Settings('stop_forum_spam', 1, 90, false, true, false));
        $this->assertNotNull($checker->checkPost($ctx));
        $this->assertSame(1, $calls);
    }

    public function testStaffBypassSkipsChecks(): void
    {
        $transport = new HttpTransport(static fn (): string => 'true');
        $checker = $this->checker(
            settings: new Settings('akismet', 1, 90, true, true, false),
            transport: $transport,
            akismetKey: 'secret',
        );

        $ctx = $this->postContext('Spam', ['id' => 9, 'role' => 'admin', 'username' => 'admin', 'email' => 'a@b.test']);

        $this->assertNull($checker->checkPost($ctx));
    }

    public function testSpamLogWritesRow(): void
    {
        $manifest = PluginManifest::fromDirectory($this->pluginDir);
        $manager = new PluginDatabaseManager($this->storageRoot);
        $manager->migrate($manifest);

        $db = $manager->open($manifest);
        $this->assertInstanceOf(PluginDatabase::class, $db);

        $log = new SpamLog($db);
        $log->record('post', 'akismet', 5, null, 'akismet:spam', ['score' => 1]);

        $count = (int) $db->pdo()->query('SELECT COUNT(*) FROM spam_log')->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testRegistrationBanWhenAkismetFlagsSignup(): void
    {
        $transport = new HttpTransport(static fn (string $method): ?string =>
            $method === 'POST' ? 'true' : null
        );

        $banned = [];
        $enforcer = new class($banned) implements RegistrationEnforcer {
            /** @param list<array{user_id: int, provider: string}> $banned */
            public function __construct(private array &$banned)
            {
            }

            public function banSpamRegistration(int $userId, string $provider): void
            {
                $this->banned[] = ['user_id' => $userId, 'provider' => $provider];
            }
        };

        $checker = $this->checker(
            settings: new Settings('akismet', 1, 90, false, true, false),
            transport: $transport,
            akismetKey: 'secret',
            enforcer: $enforcer,
        );

        $checker->checkRegistration([
            'id' => 42,
            'username' => 'spammer',
            'email' => 'spam@spam.com',
            'role' => 'member',
        ]);

        $this->assertCount(1, $banned);
        $this->assertSame(42, $banned[0]['user_id']);
        $this->assertSame('akismet', $banned[0]['provider']);
    }

    private function checker(
        Settings $settings,
        HttpTransport $transport,
        string $akismetKey,
        ?RegistrationEnforcer $enforcer = null,
    ): SpamChecker {
        $manifest = PluginManifest::fromDirectory($this->pluginDir);
        $this->saveSettings($settings);

        $config = new PluginConfig($akismetKey !== '' ? $akismetKey : null);
        $akismet = $akismetKey !== '' ? new AkismetClient($akismetKey, $transport) : null;
        $sfs = new StopForumSpamClient($transport);
        $log = new SpamLog(null);

        $_SERVER['REMOTE_ADDR'] = '203.0.113.50';
        $_SERVER['HTTP_USER_AGENT'] = 'LatchTest/1.0';

        $enforcer ??= new class implements RegistrationEnforcer {
            public function banSpamRegistration(int $userId, string $provider): void
            {
            }
        };

        return new SpamChecker(
            $this->storageRoot,
            $manifest,
            $config,
            $akismet,
            $sfs,
            $log,
            new Request(),
            'https://forum.test',
            $enforcer,
        );
    }

    private function saveSettings(Settings $settings): void
    {
        $manifest = PluginManifest::fromDirectory($this->pluginDir);
        $store = PluginSettingsStore::forPlugin($manifest, $this->storageRoot);
        $store->save([
            'provider' => $settings->provider,
            'strictness' => $settings->strictness,
            'sfs_min_confidence' => $settings->sfsMinConfidence,
            'staff_bypass' => $settings->staffBypass,
            'check_registrations' => $settings->checkRegistrations,
            'log_rejects' => $settings->logRejects,
        ]);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function postContext(string $body, array $user = ['id' => 3, 'role' => 'member', 'username' => 'member', 'email' => 'm@example.test']): PostSaveContext
    {
        return new PostSaveContext(
            body: $body,
            user: $user,
            board: ['id' => 1, 'slug' => 'general'],
            topic: ['id' => 10],
            kind: 'reply',
        );
    }

    private function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}