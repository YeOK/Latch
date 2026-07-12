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
use Latch\Plugins\ImageUpload\BodyGuard;
use Latch\Plugins\ImageUpload\PluginConfig;
use Latch\Plugins\ImageUpload\PostImageFormatter;
use Latch\Plugins\ImageUpload\R2Presigner;
use Latch\Plugins\ImageUpload\Settings;
use PHPUnit\Framework\TestCase;

final class ImageUploadPluginTest extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        $this->storageRoot = sys_get_temp_dir() . '/latch-image-upload-' . bin2hex(random_bytes(4));
        mkdir($this->storageRoot . '/plugins/image-upload', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->storageRoot);
    }

    private function sampleConfig(?Settings $settings = null): PluginConfig
    {
        $settings ??= new Settings(8, 'forum/', array_keys(Settings::ALL_CONTENT_TYPES));

        return new PluginConfig(
            accountId: 'a1b2c3d4e5f6',
            accessKeyId: 'testaccesskey',
            secretAccessKey: 'testsecretkey',
            bucket: 'latch-forum-images',
            publicHost: 'images.forum.example.com',
            r2Host: 'a1b2c3d4e5f6.r2.cloudflarestorage.com',
            maxBytes: $settings->maxBytes(),
            keyPrefix: $settings->keyPrefix,
            allowedTypeMap: $settings->allowedTypeMap(),
        );
    }

    public function testPluginPassesAudit(): void
    {
        $root = dirname(__DIR__);
        $auditor = new PluginAuditor($root, $root . '/plugins', $root . '/storage');
        $report = $auditor->auditPath(CatalogPath::plugin('image-upload'));

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
            'https://images.forum.example.com/forum/1/Screenshot%20From%202026.png',
            $url,
        );
    }

    public function testBodyGuardRejectsForeignImageHost(): void
    {
        $config = $this->sampleConfig();
        $guard = new BodyGuard($config);
        $ctx = new PostSaveContext(
            body: 'Hello ![x](https://evil.example/p.png)',
            user: ['id' => 1, 'username' => 'founder'],
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
            body: '![shot](https://images.forum.example.com/forum/1/abc.png)',
            user: ['id' => 1, 'username' => 'founder'],
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
            user: ['id' => 1, 'username' => 'founder'],
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
            user: ['id' => 1, 'username' => 'founder'],
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

    public function testManifestExposesSettingsAndSecrets(): void
    {
        $manifest = PluginManifest::fromDirectory(CatalogPath::plugin('image-upload'));

        $this->assertTrue($manifest->settingsSchema->hasAdminUi());
        $this->assertNotEmpty($manifest->settingsSchema->secretFields);
        $this->assertNotNull($manifest->settingsSchema->fieldByKey('max_mb'));
        $this->assertNotNull($manifest->settingsSchema->fieldByKey('allowed_types'));
    }

    public function testSettingsLoadsLegacyLocalPhpValues(): void
    {
        $manifest = PluginManifest::fromDirectory(CatalogPath::plugin('image-upload'));
        $settings = Settings::load($this->storageRoot, $manifest, [
            'max_mb' => 12,
            'key_prefix' => 'uploads',
            'allowed_types' => ['image/png'],
        ]);

        $this->assertSame(12, $settings->maxMb);
        $this->assertSame('uploads/', $settings->keyPrefix);
        $this->assertSame(['image/png'], $settings->allowedTypes);
    }

    public function testSettingsJsonOverridesLegacyLocalPhp(): void
    {
        $manifest = PluginManifest::fromDirectory(CatalogPath::plugin('image-upload'));
        $path = $this->storageRoot . '/plugins/image-upload/settings.json';
        file_put_contents($path, json_encode([
            'max_mb' => 4,
            'key_prefix' => 'media/',
            'allowed_types' => ['image/webp'],
        ], JSON_THROW_ON_ERROR));

        $settings = Settings::load($this->storageRoot, $manifest, [
            'max_mb' => 12,
            'key_prefix' => 'legacy/',
        ]);

        $this->assertSame(4, $settings->maxMb);
        $this->assertSame('media/', $settings->keyPrefix);
        $this->assertSame(['image/webp'], $settings->allowedTypes);
    }

    public function testRestrictedAllowedTypesBlockOtherMimes(): void
    {
        $settings = new Settings(8, 'forum/', ['image/jpeg']);
        $config = $this->sampleConfig($settings);

        $this->assertTrue($config->isAllowedContentType('image/jpeg'));
        $this->assertFalse($config->isAllowedContentType('image/png'));
    }

    public function testPostImageFormatterWrapsConfiguredHostImages(): void
    {
        $config = $this->sampleConfig();
        $formatter = new PostImageFormatter($config);
        $input = '<p>Hi</p><img src="https://images.forum.example.com/forum/1/abc.png" alt="shot" class="post-image" loading="lazy" decoding="async">';

        $html = $formatter->format($input);

        $this->assertStringContainsString('post-image-figure', $html);
        $this->assertStringContainsString('post-image-open', $html);
        $this->assertStringContainsString('data-full-src="https://images.forum.example.com/forum/1/abc.png"', $html);
        $this->assertStringContainsString('post-image--preview', $html);
    }

    public function testPostImageFormatterIgnoresForeignHosts(): void
    {
        $config = $this->sampleConfig();
        $formatter = new PostImageFormatter($config);
        $input = '<img src="https://evil.example/x.png" alt="x" class="post-image" loading="lazy" decoding="async">';

        $this->assertSame($input, $formatter->format($input));
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