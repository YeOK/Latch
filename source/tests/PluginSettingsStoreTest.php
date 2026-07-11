<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Plugins\PluginManifest;
use Latch\Core\Plugins\PluginSettingsField;
use Latch\Core\Plugins\PluginSettingsSchema;
use Latch\Core\Plugins\PluginSettingsStore;
use Latch\Core\Plugins\PluginSettingsValidator;
use Latch\Core\Plugins\PluginSettingsForm;
use Latch\Core\Config;
use PHPUnit\Framework\TestCase;

final class PluginSettingsStoreTest extends TestCase
{
    private string $storageRoot;
    private string $pluginDir;

    protected function setUp(): void
    {
        $this->storageRoot = sys_get_temp_dir() . '/latch-plugin-settings-' . bin2hex(random_bytes(4));
        mkdir($this->storageRoot, 0775, true);
        $this->pluginDir = dirname(__DIR__) . '/plugins/word-filter';
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->storageRoot);
    }

    public function testWordFilterManifestParsesSettingsSchema(): void
    {
        $manifest = PluginManifest::fromDirectory($this->pluginDir);

        $this->assertTrue($manifest->hasSettingsUi());
        $this->assertSame('mode', $manifest->settingsSchema->settingsFields[0]->key);
        $this->assertSame(PluginSettingsField::TYPE_SELECT, $manifest->settingsSchema->settingsFields[0]->type);
    }

    public function testStoreMergesDefaultsAndSavedValues(): void
    {
        $manifest = PluginManifest::fromDirectory($this->pluginDir);
        $store = PluginSettingsStore::forPlugin($manifest, $this->storageRoot);

        $this->assertSame('block', $store->all()['mode']);
        $this->assertFalse($store->all()['case_sensitive']);

        $store->save([
            'mode' => 'mask',
            'case_sensitive' => true,
            'staff_bypass' => true,
            'apply_to' => ['body'],
            'extra_words' => ['xyzzy'],
        ]);

        $reloaded = PluginSettingsStore::forPlugin($manifest, $this->storageRoot);
        $values = $reloaded->all();

        $this->assertSame('mask', $values['mode']);
        $this->assertTrue($values['case_sensitive']);
        $this->assertSame(['body'], $values['apply_to']);
        $this->assertSame(['xyzzy'], $values['extra_words']);
    }

    public function testValidatorSanitizesMultiselectAndStringList(): void
    {
        $manifest = PluginManifest::fromDirectory($this->pluginDir);
        $validator = new PluginSettingsValidator();

        $result = $validator->validate([
            'mode' => 'block',
            'case_sensitive' => '1',
            'staff_bypass' => '1',
            'apply_to' => ['body', 'topic_title', 'invalid'],
            'extra_words' => "alpha\n# comment\n\nbeta\n",
        ], $manifest->settingsSchema);

        $this->assertNull($result['error']);
        $this->assertSame(['body', 'topic_title'], $result['values']['apply_to']);
        $this->assertSame(['alpha', 'beta'], $result['values']['extra_words']);
    }

    public function testFormBuildsRowsForWordFilter(): void
    {
        $manifest = PluginManifest::fromDirectory($this->pluginDir);
        $store = PluginSettingsStore::forPlugin($manifest, $this->storageRoot);
        $config = new Config(dirname(__DIR__) . '/config');
        $form = (new PluginSettingsForm())->build($manifest->settingsSchema, $store->all(), $config);

        $this->assertCount(5, $form['settings_fields']);
        $this->assertSame('mode', $form['settings_fields'][0]['key']);
    }

    public function testValidatorRejectsInvalidSelect(): void
    {
        $schema = new PluginSettingsSchema([
            new PluginSettingsField('mode', PluginSettingsField::TYPE_SELECT, 'Mode', 'block', [
                ['value' => 'block', 'label' => 'Block'],
            ]),
        ], []);

        $result = (new PluginSettingsValidator())->validate(['mode' => 'evil'], $schema);

        $this->assertNotNull($result['error']);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . '/' . $entry;
            if (is_dir($full)) {
                $this->removeTree($full);
                continue;
            }

            @unlink($full);
        }

        @rmdir($path);
    }
}