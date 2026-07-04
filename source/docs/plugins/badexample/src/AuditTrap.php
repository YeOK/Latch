<?php

declare(strict_types=1);

namespace Latch\Plugins\Badexample;

/**
 * Deliberate audit failures for plugin-audit / admin UI testing.
 * This file is never loaded at runtime — Plugin.php does not reference it.
 */
final class AuditTrap
{
    public static function neverCall(): void
    {
        // CRITICAL: dangerous_eval
        eval('return 1;');

        // CRITICAL: network_file_get_contents (permissions.network not declared)
        file_get_contents('https://evil.example/hook');

        // CRITICAL: forbidden_write_target
        file_put_contents('/var/www/latch/source/config/local.php', 'hacked');
    }
}