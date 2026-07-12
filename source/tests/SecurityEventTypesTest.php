<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class SecurityEventTypesTest extends TestCase
{
    public function testConfigListsEveryEmittedSecurityEvent(): void
    {
        $appDir = dirname(__DIR__) . '/app';
        $configured = require dirname(__DIR__) . '/config/default.php';
        $types = $configured['logs']['security_event_types'] ?? [];
        $this->assertIsArray($types);

        $configuredSet = array_fill_keys(array_map('strval', $types), true);
        $emitted = $this->collectEmittedEvents($appDir);

        $missing = [];
        foreach ($emitted as $event) {
            if (!isset($configuredSet[$event])) {
                $missing[] = $event;
            }
        }

        sort($missing);
        $this->assertSame([], $missing, 'Add missing events to logs.security_event_types in config/default.php: ' . implode(', ', $missing));
    }

    /**
     * @return list<string>
     */
    private function collectEmittedEvents(string $appDir): array
    {
        $events = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir));

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if (!is_string($contents)) {
                continue;
            }

            if (preg_match_all("/(?:securityLog\(\)->log|->securityLog->log)\(\s*'([^']+)'/", $contents, $matches)) {
                foreach ($matches[1] as $event) {
                    $events[$event] = true;
                }
            }
        }

        return array_keys($events);
    }
}