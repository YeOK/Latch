<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Support\SystemInfo;
use PHPUnit\Framework\TestCase;

final class SystemInfoTest extends TestCase
{
    public function testDatabaseSizesIncludeWalSidecars(): void
    {
        $dir = sys_get_temp_dir() . '/latch-system-info-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $dbPath = $dir . '/forum.sqlite';

        file_put_contents($dbPath, str_repeat('x', 1000));
        file_put_contents($dbPath . '-wal', str_repeat('y', 250));
        file_put_contents($dbPath . '-shm', str_repeat('z', 32));

        $sizes = SystemInfo::databaseSizes($dbPath);

        $this->assertSame(1000, $sizes['main']);
        $this->assertSame(250, $sizes['wal']);
        $this->assertSame(32, $sizes['shm']);
        $this->assertSame(1282, $sizes['total']);

        @unlink($dbPath);
        @unlink($dbPath . '-wal');
        @unlink($dbPath . '-shm');
        @rmdir($dir);
    }

    public function testRelativeTimeLabel(): void
    {
        $this->assertNull(SystemInfo::relativeTimeLabel(null));
        $this->assertSame('just now', SystemInfo::relativeTimeLabel(gmdate('c')));
        $this->assertSame('2h ago', SystemInfo::relativeTimeLabel(gmdate('c', time() - 7200)));
    }

    public function testMailSummary(): void
    {
        $this->assertSame(
            ['label' => 'Disabled', 'alert' => true],
            SystemInfo::mailSummary(['enabled' => false, 'transport' => 'msmtp', 'configured' => true]),
        );
        $this->assertSame(
            ['label' => 'Not configured', 'alert' => true],
            SystemInfo::mailSummary(['enabled' => true, 'transport' => 'msmtp', 'configured' => false]),
        );
        $this->assertSame(
            ['label' => 'msmtp · ready', 'alert' => false],
            SystemInfo::mailSummary(['enabled' => true, 'transport' => 'msmtp', 'configured' => true]),
        );
    }
}