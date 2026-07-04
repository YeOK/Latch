<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Core\Plugins\PluginAuditor;
use Latch\Core\Plugins\PostSaveContext;
use Latch\Plugins\ImageUpload\BodyGuard;
use Latch\Plugins\ImageUpload\PluginConfig;
use Latch\Plugins\ImageUpload\R2Presigner;
use PHPUnit\Framework\TestCase;

final class ImageUploadPluginTest extends TestCase
{
    private function sampleConfig(): PluginConfig
    {
        return new PluginConfig(
            accountId: 'a1b2c3d4e5f6',
            accessKeyId: 'testaccesskey',
            secretAccessKey: 'testsecretkey',
            bucket: 'latch-forum-images',
            publicHost: 'images.latch.network',
            r2Host: 'a1b2c3d4e5f6.r2.cloudflarestorage.com',
            maxBytes: 8 * 1024 * 1024,
            keyPrefix: 'forum/',
        );
    }

    public function testPluginPassesAudit(): void
    {
        $root = dirname(__DIR__);
        $auditor = new PluginAuditor($root, $root . '/plugins', $root . '/storage');
        $report = $auditor->auditTarget('image-upload');

        $this->assertTrue($report->passed(), $report->toHuman());
    }

    public function testPresignerBuildsR2PutUrl(): void
    {
        $config = $this->sampleConfig();
        $url = (new R2Presigner($config))->presignPut('forum/1/abc.png', 'image/png', 300);

        $this->assertStringStartsWith('https://a1b2c3d4e5f6.r2.cloudflarestorage.com/latch-forum-images/forum/1/abc.png?', $url);
        $this->assertStringContainsString('X-Amz-Algorithm=AWS4-HMAC-SHA256', $url);
        $this->assertStringContainsString('X-Amz-Signature=', $url);
    }

    public function testPublicUrlEncodesSegments(): void
    {
        $config = $this->sampleConfig();
        $url = $config->publicUrlForKey('forum/1/Screenshot From 2026.png');

        $this->assertSame(
            'https://images.latch.network/forum/1/Screenshot%20From%202026.png',
            $url,
        );
    }

    public function testBodyGuardRejectsForeignImageHost(): void
    {
        $config = $this->sampleConfig();
        $guard = new BodyGuard($config);
        $ctx = new PostSaveContext(
            body: 'Hello ![x](https://evil.example/p.png)',
            user: ['id' => 1, 'username' => 'yeok'],
            board: ['id' => 1],
            topic: null,
            kind: 'reply',
        );

        $this->assertNotNull($guard->validate($ctx));
    }

    public function testBodyGuardAllowsConfiguredHost(): void
    {
        $config = $this->sampleConfig();
        $guard = new BodyGuard($config);
        $ctx = new PostSaveContext(
            body: '![shot](https://images.latch.network/forum/1/abc.png)',
            user: ['id' => 1, 'username' => 'yeok'],
            board: ['id' => 1],
            topic: null,
            kind: 'reply',
        );

        $this->assertNull($guard->validate($ctx));
    }

    public function testBodyGuardIgnoresMarkdownImagesInInlineCode(): void
    {
        $config = $this->sampleConfig();
        $guard = new BodyGuard($config);
        $ctx = new PostSaveContext(
            body: 'Docs mention `![alt](https://host/example.png)` as syntax only.',
            user: ['id' => 1, 'username' => 'yeok'],
            board: ['id' => 1],
            topic: null,
            kind: 'topic',
        );

        $this->assertNull($guard->validate($ctx));
    }

    public function testBodyGuardAllowsPluginsDocumentationImport(): void
    {
        $config = $this->sampleConfig();
        $guard = new BodyGuard($config);
        $path = dirname(__DIR__) . '/docs/PLUGINS.md';
        $this->assertFileExists($path);

        $parser = new \Latch\Plugins\MdImport\MarkdownImport();
        $parsed = $parser->parse((string) file_get_contents($path), 'PLUGINS.md', null, true);
        $posts = $parser->splitIntoPosts($parsed['body'], 65535);
        $ctx = new PostSaveContext(
            body: $posts[0],
            user: ['id' => 1, 'username' => 'yeok'],
            board: ['id' => 1],
            topic: null,
            kind: 'topic',
            topicTitle: $parsed['title'],
        );

        $this->assertNull($guard->validate($ctx));
    }

    public function testContentTypeAllowlist(): void
    {
        $config = $this->sampleConfig();
        $this->assertTrue($config->isAllowedContentType('image/png'));
        $this->assertFalse($config->isAllowedContentType('text/html'));
        $this->assertSame('png', $config->extensionForContentType('image/png'));
    }
}