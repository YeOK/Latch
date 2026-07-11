<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Plugins\PluginAuditor;
use Latch\Core\Plugins\PluginManifest;
use Latch\Core\Plugins\PostSaveContext;
use Latch\Plugins\SlackNotify\HttpTransport;
use Latch\Plugins\SlackNotify\MessageBuilder;
use Latch\Plugins\SlackNotify\Notifier;
use Latch\Plugins\SlackNotify\PluginConfig;
use Latch\Plugins\SlackNotify\Settings;
use Latch\Plugins\SlackNotify\WebhookClient;
use PHPUnit\Framework\TestCase;

final class SlackNotifyPluginTest extends TestCase
{
    private string $pluginDir;

    protected function setUp(): void
    {
        $this->pluginDir = CatalogPath::plugin('slack-notify');
    }

    public function testPluginPassesAudit(): void
    {
        $root = dirname(__DIR__);
        $auditor = new PluginAuditor($root, $root . '/plugins', $root . '/storage');
        $report = $auditor->auditPath($this->pluginDir);

        $this->assertTrue($report->passed(), $report->toHuman());
    }

    public function testManifestHasSecretsAndSettings(): void
    {
        $manifest = PluginManifest::fromDirectory($this->pluginDir);

        $this->assertFalse($manifest->databaseEnabled);
        $this->assertFalse($manifest->bundled);
        $this->assertTrue($manifest->settingsSchema->hasAdminUi());
        $this->assertNotEmpty($manifest->settingsSchema->secretFields);
    }

    public function testMessageBuilderFormatsNewTopic(): void
    {
        $settings = new Settings('Latch Bot', [Settings::EVENT_NEW_TOPIC], true, false);
        $builder = new MessageBuilder($settings, 'https://forum.test');

        $message = $builder->forPost(new PostSaveContext(
            body: 'Hello **world** and [link](https://example.com)',
            user: ['id' => 1, 'username' => 'alice'],
            board: ['id' => 2, 'slug' => 'general'],
            topic: ['id' => 9, 'title' => 'First topic'],
            kind: 'topic',
            post: ['id' => 50, 'approval_status' => 'approved'],
        ));

        $this->assertStringContainsString('*alice*', $message['text']);
        $this->assertStringContainsString('<https://forum.test/topic/9|First topic>', $message['text']);
        $this->assertStringContainsString('Hello world and link', $message['text']);
    }

    public function testMessageBuilderFormatsReply(): void
    {
        $settings = new Settings('Latch', [Settings::EVENT_NEW_REPLY], false, false);
        $builder = new MessageBuilder($settings, 'https://forum.test');

        $message = $builder->forPost(new PostSaveContext(
            body: 'A reply',
            user: ['id' => 2, 'username' => 'bob'],
            board: ['id' => 1, 'slug' => 'dev'],
            topic: ['id' => 4, 'title' => 'Bug report'],
            kind: 'reply',
            post: ['id' => 51, 'approval_status' => 'approved'],
        ));

        $this->assertStringContainsString('*bob* replied in *Bug report*', $message['text']);
        $this->assertStringNotContainsString("\n> ", $message['text']);
    }

    public function testWebhookClientSendsSlackPayload(): void
    {
        $sent = [];
        $transport = new HttpTransport(static function (
            string $method,
            string $url,
            ?string $body,
            array $headers,
        ) use (&$sent): string {
            $sent = [
                'method' => $method,
                'url' => $url,
                'body' => $body,
                'headers' => $headers,
            ];

            return 'ok';
        });

        $client = new WebhookClient($transport);
        $ok = $client->send('https://hooks.slack.com/services/T/B/X', [
            'username' => 'Latch',
            'text' => 'Hello',
        ]);

        $this->assertTrue($ok);
        $this->assertSame('POST', $sent['method']);
        $decoded = json_decode((string) $sent['body'], true);
        $this->assertSame('Latch', $decoded['username']);
        $this->assertSame('Hello', $decoded['text']);
    }

    public function testWebhookClientSendsDiscordPayload(): void
    {
        $sent = [];
        $transport = new HttpTransport(static function (
            string $method,
            string $url,
            ?string $body,
        ) use (&$sent): string {
            $sent['body'] = $body;

            return 'ok';
        });

        $client = new WebhookClient($transport);
        $notifier = new Notifier(
            new Settings('Team Bot', [Settings::EVENT_USER_REGISTERED], true, false),
            new PluginConfig('https://discord.com/api/webhooks/1/token'),
            new MessageBuilder(new Settings('Team Bot', [Settings::EVENT_USER_REGISTERED], true, false), 'https://forum.test'),
            $client,
        );

        $notifier->onUserRegistered(['id' => 3, 'username' => 'carol', 'role' => 'member']);

        $decoded = json_decode((string) $sent['body'], true);
        $this->assertSame('Team Bot', $decoded['username']);
        $this->assertStringContainsString('carol', $decoded['content']);
        $this->assertArrayNotHasKey('text', $decoded);
    }

    public function testNotifierSkipsWhenWebhookMissing(): void
    {
        $called = false;
        $transport = new HttpTransport(static function () use (&$called): string {
            $called = true;

            return 'ok';
        });

        $notifier = new Notifier(
            new Settings('Latch', [Settings::EVENT_NEW_REPLY], true, false),
            new PluginConfig(null),
            new MessageBuilder(new Settings('Latch', [Settings::EVENT_NEW_REPLY], true, false), 'https://forum.test'),
            new WebhookClient($transport),
        );

        $notifier->onPostSaved(new PostSaveContext(
            body: 'Hi',
            user: ['id' => 1, 'username' => 'alice'],
            board: ['id' => 1, 'slug' => 'general'],
            topic: ['id' => 2, 'title' => 'Topic'],
            kind: 'reply',
            post: ['id' => 10, 'approval_status' => 'approved'],
        ));

        $this->assertFalse($called);
    }

    public function testNotifierSkipsDisabledEvent(): void
    {
        $called = false;
        $transport = new HttpTransport(static function () use (&$called): string {
            $called = true;

            return 'ok';
        });

        $notifier = new Notifier(
            new Settings('Latch', [Settings::EVENT_NEW_TOPIC], true, false),
            new PluginConfig('https://hooks.slack.com/services/T/B/X'),
            new MessageBuilder(new Settings('Latch', [Settings::EVENT_NEW_TOPIC], true, false), 'https://forum.test'),
            new WebhookClient($transport),
        );

        $notifier->onPostSaved(new PostSaveContext(
            body: 'Reply only',
            user: ['id' => 1, 'username' => 'alice'],
            board: ['id' => 1, 'slug' => 'general'],
            topic: ['id' => 2, 'title' => 'Topic'],
            kind: 'reply',
            post: ['id' => 10, 'approval_status' => 'approved'],
        ));

        $this->assertFalse($called);
    }

    public function testNotifierSkipsPendingWhenDisabled(): void
    {
        $called = false;
        $transport = new HttpTransport(static function () use (&$called): string {
            $called = true;

            return 'ok';
        });

        $notifier = new Notifier(
            new Settings('Latch', [Settings::EVENT_NEW_REPLY], true, false),
            new PluginConfig('https://hooks.slack.com/services/T/B/X'),
            new MessageBuilder(new Settings('Latch', [Settings::EVENT_NEW_REPLY], true, false), 'https://forum.test'),
            new WebhookClient($transport),
        );

        $notifier->onPostSaved(new PostSaveContext(
            body: 'Pending',
            user: ['id' => 1, 'username' => 'alice'],
            board: ['id' => 1, 'slug' => 'general'],
            topic: ['id' => 2, 'title' => 'Topic'],
            kind: 'reply',
            post: ['id' => 10, 'approval_status' => 'pending'],
        ));

        $this->assertFalse($called);
    }
}