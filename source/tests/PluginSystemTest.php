<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Core\Database;
use Latch\Core\Plugins\HookName;
use Latch\Core\Plugins\HookRegistry;
use Latch\Core\Plugins\PluginInterface;
use Latch\Core\Plugins\PluginManifest;
use Latch\Core\Plugins\PluginRegistry;
use Latch\Core\Plugins\PostSaveContext;
use Latch\Models\SettingRepository;
use PHPUnit\Framework\TestCase;

final class PluginSystemTest extends TestCase
{
    private string $dbPath;
    private Database $db;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-plugin-test-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $this->db->pdo()->exec(
            'CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);
             INSERT INTO settings (key, value) VALUES ("enabled_plugins", "[]");'
        );
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testHookRegistryRunsLowerPriorityFirst(): void
    {
        $hooks = new HookRegistry();
        $order = [];

        $hooks->add('test', static function () use (&$order): void {
            $order[] = 'second';
        }, 20);
        $hooks->add('test', static function () use (&$order): void {
            $order[] = 'first';
        }, 5);

        $hooks->dispatch('test');

        $this->assertSame(['first', 'second'], $order);
    }

    public function testHookRegistryCollectMergesResults(): void
    {
        $hooks = new HookRegistry();
        $hooks->add(HookName::LAYOUT_FOOTER, static fn (): string => '<p>one</p>');
        $hooks->add(HookName::LAYOUT_FOOTER, static fn (): array => ['<p>two</p>']);

        $this->assertSame(['<p>one</p>', '<p>two</p>'], $hooks->collect(HookName::LAYOUT_FOOTER));
    }

    public function testHookRegistryFilterChainsValue(): void
    {
        $hooks = new HookRegistry();
        $hooks->add(HookName::AVATAR_RESOLVE, static fn (string $url): string => $url . '&s=96');
        $hooks->add(HookName::AVATAR_RESOLVE, static fn (string $url): string => str_replace('http://', 'https://', $url));

        $result = $hooks->filter(HookName::AVATAR_RESOLVE, 'http://example.test/a', 'a@b.test', 48);

        $this->assertSame('https://example.test/a&s=96', $result);
    }

    public function testPostSaveContextReject(): void
    {
        $ctx = new PostSaveContext('hello', ['id' => 1], ['id' => 2], null, 'reply');
        $hooks = new HookRegistry();
        $hooks->add(HookName::POST_BEFORE_SAVE, static function (PostSaveContext $context): void {
            $context->reject('blocked');
        });

        $hooks->dispatch(HookName::POST_BEFORE_SAVE, $ctx);

        $this->assertSame('blocked', $ctx->rejectReason);
    }

    public function testImageHostFilterAllowsMarkdownImage(): void
    {
        $hooks = new HookRegistry();
        $hooks->add(
            HookName::POST_FORMAT_IMAGE_HOST,
            static fn (bool $allowed, string $host): bool => $allowed || $host === 'cdn.example.test',
        );

        $formatter = new \Latch\Core\PostFormatter();
        $formatter->setImageHostChecker(
            static fn (string $host): bool => $hooks->filter(HookName::POST_FORMAT_IMAGE_HOST, false, $host) === true,
        );

        $html = $formatter->format('![pic](https://cdn.example.test/x.png)');

        $this->assertStringContainsString('<img src="https://cdn.example.test/x.png"', $html);
        $this->assertStringNotContainsString('[image blocked]', $html);
    }

    public function testExampleManifestParses(): void
    {
        $dir = dirname(__DIR__) . '/plugins/example';
        $manifest = PluginManifest::fromDirectory($dir);

        $this->assertSame('example', $manifest->slug);
        $this->assertContains(HookName::ROUTE_REGISTER, $manifest->hooks);
        $this->assertSame('Latch\\Plugins\\Example\\Plugin', $manifest->bootstrapClass());
        $this->assertTrue($manifest->isCompatibleWith('0.3.0'));
    }

    public function testExamplePluginClassImplementsInterface(): void
    {
        $manifest = PluginManifest::fromDirectory(dirname(__DIR__) . '/plugins/example');
        require_once $manifest->bootstrapFile();
        $class = $manifest->bootstrapClass();

        $this->assertTrue(class_exists($class));
        $plugin = new $class();
        $this->assertInstanceOf(PluginInterface::class, $plugin);
    }

    public function testRegistryDiscoversExamplePlugin(): void
    {
        $registry = new PluginRegistry(dirname(__DIR__) . '/plugins', new SettingRepository($this->db));
        $slugs = array_map(static fn (PluginManifest $m): string => $m->slug, $registry->discover());

        $this->assertContains('example', $slugs);
    }

    public function testRegistryEnableList(): void
    {
        $settings = new SettingRepository($this->db);
        $registry = new PluginRegistry(dirname(__DIR__) . '/plugins', $settings);

        $this->assertFalse($registry->isEnabled('example'));

        $registry->setEnabledSlugs(['example']);
        $this->assertTrue($registry->isEnabled('example'));
    }
}