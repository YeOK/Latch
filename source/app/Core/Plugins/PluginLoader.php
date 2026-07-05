<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core\Plugins;

use Latch\Core\Application;

/**
 * Boots enabled plugins and registers their hook listeners.
 */
final class PluginLoader
{
    /** @var list<PluginManifest> */
    private array $loaded = [];

    /** @var array<string, bool> */
    private array $registeredAutoloaders = [];

    public function __construct(
        private readonly PluginRegistry $registry,
        private readonly HookRegistry $hooks,
        private readonly string $latchVersion,
    ) {
    }

    public function boot(Application $app): void
    {
        $discovered = [];
        foreach ($this->registry->discover() as $manifest) {
            $discovered[$manifest->slug] = $manifest;
        }

        foreach ($this->registry->enabledSlugs() as $slug) {
            $manifest = $discovered[$slug] ?? null;
            if ($manifest === null) {
                continue;
            }

            if (!$manifest->isCompatibleWith($this->latchVersion)) {
                continue;
            }

            $plugin = $this->instantiate($manifest);
            if ($plugin === null) {
                continue;
            }

            $context = new PluginContext($app, $manifest, $this->hooks);
            $plugin->register($context);
            $this->loaded[] = $manifest;
        }
    }

    /**
     * @return list<PluginManifest>
     */
    public function loaded(): array
    {
        return $this->loaded;
    }

    private function instantiate(PluginManifest $manifest): ?PluginInterface
    {
        $this->registerAutoloader($manifest);

        $file = $manifest->bootstrapFile();
        if (!is_file($file)) {
            return null;
        }

        require_once $file;

        $class = $manifest->bootstrapClass();
        if (!class_exists($class)) {
            return null;
        }

        $plugin = new $class();
        if (!$plugin instanceof PluginInterface) {
            return null;
        }

        return $plugin;
    }

    private function registerAutoloader(PluginManifest $manifest): void
    {
        $slug = $manifest->slug;
        if (isset($this->registeredAutoloaders[$slug])) {
            return;
        }

        $prefix = 'Latch\\Plugins\\' . PluginManifest::studlySlug($slug) . '\\';
        $baseDir = $manifest->pluginDir . '/src/';

        spl_autoload_register(static function (string $class) use ($prefix, $baseDir): void {
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $path = $baseDir . str_replace('\\', '/', $relative) . '.php';
            if (is_file($path)) {
                require $path;
            }
        });

        $this->registeredAutoloaders[$slug] = true;
    }
}