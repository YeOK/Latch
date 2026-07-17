<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\BoardIcons\BoardIconRegistry;
use Latch\Core\Config;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BoardIconRegistryTest extends TestCase
{
    private string $configDir;

    protected function setUp(): void
    {
        $themes = dirname(__DIR__) . '/themes';
        $this->configDir = sys_get_temp_dir() . '/latch-bir-' . bin2hex(random_bytes(4));
        mkdir($this->configDir);
        file_put_contents(
            $this->configDir . '/default.php',
            '<?php return ["paths" => ["themes" => ' . var_export($themes, true) . ']];',
        );
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->configDir . '/*') ?: []);
        @rmdir($this->configDir);
    }

    public function testRegisterAndKeywords(): void
    {
        $registry = new BoardIconRegistry(new Config($this->configDir));
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M1 1h1" stroke="currentColor"/></svg>';

        $registry->register('custom-lab', $svg);
        $registry->registerKeywords('custom-lab', ['lab', 'science', 'research']);

        $this->assertTrue($registry->has('custom-lab'));
        $this->assertSame('custom-lab', $registry->suggestKey('Science Lab', 'research-lab'));
    }

    public function testRejectsUnsafeSvg(): void
    {
        $registry = new BoardIconRegistry(new Config($this->configDir));

        $this->expectException(RuntimeException::class);
        $registry->register('evil', '<svg><script>alert(1)</script></svg>');
    }
}
