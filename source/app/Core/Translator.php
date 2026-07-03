<?php

declare(strict_types=1);

namespace Latch\Core;

use Latch\Core\Plugins\HookName;
use Latch\Core\Plugins\HookRegistry;

/**
 * Loads PHP translation files from lang/ with English fallback and plugin merge hook.
 */
final class Translator
{
    /** @var array<string, mixed> */
    private array $strings;

    /** @var array<string, mixed> */
    private array $fallback;

    public function __construct(
        private readonly string $langPath,
        private readonly string $locale,
        private readonly HookRegistry $hooks,
    ) {
        $this->fallback = $this->loadFile(self::pathFor($langPath, 'en'));
        $this->strings = $locale === 'en'
            ? $this->fallback
            : $this->loadFile(self::pathFor($langPath, $locale));
        $this->strings = $this->hooks->filter(HookName::LOCALE_TRANSLATIONS, $this->strings, $locale);
    }

    /**
     * @param array<string, string|int|float> $replace
     */
    public function get(string $key, array $replace = []): string
    {
        $value = $this->resolve($key);
        if ($value === null) {
            return $key;
        }

        foreach ($replace as $name => $replacement) {
            $value = str_replace(':' . $name, (string) $replacement, $value);
        }

        return $value;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    private function resolve(string $key): ?string
    {
        $value = $this->dotGet($this->strings, $key);
        if ($value !== null) {
            return $value;
        }

        if ($this->locale !== 'en') {
            $value = $this->dotGet($this->fallback, $key);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function dotGet(array $data, string $key): ?string
    {
        $cursor = $data;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return is_string($cursor) ? $cursor : null;
    }

    private static function pathFor(string $langPath, string $locale): string
    {
        return rtrim($langPath, '/') . '/' . $locale . '.php';
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $data = require $path;

        return is_array($data) ? $data : [];
    }
}