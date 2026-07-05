<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig theme engine — loads active theme with default fallback.
 */
final class View
{
    private Environment $twig;
    private PostFormatter $postFormatter;
    private ?Translator $translator = null;

    public function __construct(Config $config, private readonly Csrf $csrf)
    {
        $themesPath = (string) $config->get('paths.themes');
        $active = (string) $config->get('theme.active', 'default');
        $activePath = $themesPath . '/' . $active;
        $defaultPath = $themesPath . '/default';

        $paths = array_values(array_unique([
            is_dir($activePath) ? $activePath : $defaultPath,
            $defaultPath,
        ]));

        $loader = new FilesystemLoader($paths);
        $cachePath = $this->resolveTwigCachePath((string) $config->get('paths.storage') . '/cache/twig');

        $this->twig = new Environment($loader, [
            'cache' => $cachePath,
            'autoescape' => 'html',
            'strict_variables' => true,
        ]);

        $this->postFormatter = new PostFormatter();
        $dateFormatter = new DateTimeFormatter();

        $this->twig->addFunction(new TwigFunction('csrf_field', fn (): string => $this->csrf->field(), ['is_safe' => ['html']]));
        $this->twig->addFunction(new TwigFunction('post_smileys', fn (): array => PostFormatter::smileys()));
        $this->twig->addFilter(new TwigFilter('format_post', fn (string $body): string => $this->postFormatter->format($body), ['is_safe' => ['html']]));
        $this->twig->addFilter(new TwigFilter('format_datetime', fn (?string $value): string => $dateFormatter->format($value)));
        $this->twig->addFilter(new TwigFilter('format_date', fn (?string $value): string => $dateFormatter->formatDate($value)));
        $this->twig->addFilter(new TwigFilter('json_encode', static fn (mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)));
        $this->twig->addFunction(new TwigFunction('trans', function (string $key, array $replace = []): string {
            return $this->translator?->get($key, $replace) ?? $key;
        }));
    }

    public function bindTranslator(Translator $translator): void
    {
        $this->translator = $translator;
    }

    public function bindPostFormatter(PostFormatter $formatter): void
    {
        $this->postFormatter = $formatter;
    }

    public function render(string $template, array $data = []): string
    {
        return $this->twig->render($template, $data);
    }

    private function resolveTwigCachePath(string $cachePath): string|false
    {
        if (!is_dir($cachePath) && !@mkdir($cachePath, 02770, true)) {
            return false;
        }

        @chmod($cachePath, 02770);

        // CLI (non-apache user) must not use filesystem cache — it creates subdirs apache cannot write.
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_geteuid())['name'] ?? '';
            if ($user !== '' && $user !== 'apache') {
                return false;
            }
        }

        return is_writable($cachePath) ? $cachePath : false;
    }
}