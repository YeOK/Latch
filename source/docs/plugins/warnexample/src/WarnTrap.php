<?php

declare(strict_types=1);

namespace Latch\Plugins\Warnexample;

/**
 * Deliberate markup audit warnings for plugin-audit / admin UI testing.
 * This file is never loaded at runtime — Plugin.php does not reference it.
 */
final class WarnTrap
{
    public static function footerHtml(): string
    {
        return '<script>alert(1)</script>';
    }

    public static function handlerHtml(): string
    {
        return '<img onerror=alert(1) src=x>';
    }

    public static function javascriptUrl(): string
    {
        return '<a href="javascript:void(0)">click</a>';
    }
}