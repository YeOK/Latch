<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

/**
 * Priority-ordered plugin hook registry.
 */
final class HookRegistry
{
    /** @var array<string, list<array{priority: int, callback: callable, plugin_slug: ?string}>> */
    private array $hooks = [];

    public function add(string $hook, callable $callback, int $priority = 10, ?string $pluginSlug = null): void
    {
        $this->hooks[$hook][] = [
            'priority' => $priority,
            'callback' => $callback,
            'plugin_slug' => $pluginSlug,
        ];
    }

    /**
     * @return list<array{priority: int, callback: callable, plugin_slug: ?string}>
     */
    public function entries(string $hook): array
    {
        return $this->sorted($hook);
    }

    public function dispatch(string $hook, mixed ...$args): void
    {
        foreach ($this->sorted($hook) as $entry) {
            ($entry['callback'])(...$args);
        }
    }

    /**
     * @return list<mixed>
     */
    public function collect(string $hook, mixed ...$args): array
    {
        $results = [];
        foreach ($this->sorted($hook) as $entry) {
            $result = ($entry['callback'])(...$args);
            if ($result === null || $result === '') {
                continue;
            }
            if (is_array($result)) {
                if (self::isAssociativeMenuItem($result)) {
                    $results[] = $result;
                    continue;
                }

                foreach ($result as $item) {
                    if ($item !== null && $item !== '') {
                        $results[] = $item;
                    }
                }
            } else {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    public function filter(string $hook, mixed $value, mixed ...$args): mixed
    {
        foreach ($this->sorted($hook) as $entry) {
            $value = ($entry['callback'])($value, ...$args);
        }

        return $value;
    }

    public function has(string $hook): bool
    {
        return isset($this->hooks[$hook]) && $this->hooks[$hook] !== [];
    }

    /**
     * @return list<array{priority: int, callback: callable, plugin_slug: ?string}>
     */
    private function sorted(string $hook): array
    {
        $entries = $this->hooks[$hook] ?? [];
        if ($entries === []) {
            return [];
        }

        usort(
            $entries,
            static fn (array $a, array $b): int => $a['priority'] <=> $b['priority'],
        );

        return $entries;
    }

    /**
     * @param array<mixed> $value
     */
    public static function isAssociativeMenuItem(array $value): bool
    {
        return isset($value['label'], $value['href']) && is_string($value['label']) && is_string($value['href']);
    }
}