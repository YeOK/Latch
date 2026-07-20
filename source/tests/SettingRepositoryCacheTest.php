<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\Database;
use Latch\Models\SettingRepository;
use PHPUnit\Framework\TestCase;

final class SettingRepositoryCacheTest extends TestCase
{
    private string $dbPath;
    private SettingRepository $settings;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-settings-' . bin2hex(random_bytes(4)) . '.sqlite';
        $db = new Database($this->dbPath);
        $db->pdo()->exec(
            'CREATE TABLE settings (
                key TEXT PRIMARY KEY NOT NULL,
                value TEXT NOT NULL
             );
             INSERT INTO settings (key, value) VALUES ("site_name", "Test Forum");'
        );
        $this->settings = new SettingRepository($db);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testRepeatedGetsUseHydratedCache(): void
    {
        $this->assertSame('Test Forum', $this->settings->get('site_name'));
        $this->assertSame('Test Forum', $this->settings->get('site_name'));
        $this->assertNull($this->settings->get('missing_key'));
        $this->assertSame('fallback', $this->settings->get('missing_key', 'fallback'));
    }

    public function testSetUpdatesCache(): void
    {
        $this->settings->set('site_name', 'Renamed');
        $this->assertSame('Renamed', $this->settings->get('site_name'));
        $this->settings->setBool('feature_x', true);
        $this->assertTrue($this->settings->getBool('feature_x'));
    }
}
