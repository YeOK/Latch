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

    /** Enabled plugins that declare user.before_register but failed to boot. */
    private array $registrationGateFailures = [];

    public function __construct(
        private readonly PluginRegistry $registry,
        private readonly HookRegistry $hooks,
        private readonly string $latchVersion,
        private readonly PluginDatabaseManager $databaseManager,
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

            if (!$this->prepareDatabase($manifest)) {
                $this->recordRegistrationGateFailure($manifest);

                continue;
            }

            $plugin = $this->instantiate($manifest);
            if ($plugin === null) {
                $this->recordRegistrationGateFailure($manifest);

                continue;
            }

            $context = new PluginContext($app, $manifest, $this->hooks);
            try {
                $plugin->register($context);
            } catch (\Throwable) {
                $this->recordRegistrationGateFailure($manifest);

                continue;
            }

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

    /**
     * True when an enabled registration-gate plugin (user.before_register) did not boot.
     * Callers should refuse new accounts rather than fail-open.
     */
    public function registrationGateFailed(): bool
    {
        return $this->registrationGateFailures !== [];
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

    private function prepareDatabase(PluginManifest $manifest): bool
    {
        if (!$this->databaseManager->usesDatabase($manifest)) {
            return true;
        }

        try {
            $this->databaseManager->migrate($manifest);
        } catch (\Throwable) {
            return false;
        }

        return true;
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
            if ($relative === '' || str_contains($relative, '..')) {
                return;
            }

            $path = $baseDir . str_replace('\\', '/', $relative) . '.php';
            $baseReal = realpath($baseDir);
            $fileReal = realpath($path);
            if (
                $baseReal === false
                || $fileReal === false
                || !str_starts_with($fileReal, $baseReal . DIRECTORY_SEPARATOR)
            ) {
                return;
            }

            require $fileReal;
        });

        $this->registeredAutoloaders[$slug] = true;
    }

    private function recordRegistrationGateFailure(PluginManifest $manifest): void
    {
        if (in_array(HookName::USER_BEFORE_REGISTER, $manifest->hooks, true)) {
            $this->registrationGateFailures[] = $manifest->slug;
        }
    }
}