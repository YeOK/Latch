<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Plugins\ForumStats;

use Latch\Core\Plugins\HookName;
use Latch\Core\Plugins\PluginContext;
use Latch\Core\Plugins\PluginInterface;
use Latch\Core\Response;
use Latch\Core\Router;

final class Plugin implements PluginInterface
{
    public function register(PluginContext $context): void
    {
        $cssPath = $context->path() . '/assets/stats.css';
        $assetVersion = $context->app()->assetVersion();

        $context->hooks()->add(
            HookName::ROUTE_REGISTER,
            static function (Router $router) use ($cssPath, $assetVersion): void {
                $router->get('/plugin/forum-stats/stats.css', static function () use ($cssPath, $assetVersion): void {
                    if (!is_file($cssPath)) {
                        Response::notFound();

                        return;
                    }

                    $etag = '"' . hash('sha256', $cssPath . '|' . filemtime($cssPath) . '|' . $assetVersion) . '"';
                    http_response_code(200);
                    header('Content-Type: text/css; charset=utf-8');
                    header('Cache-Control: public, max-age=31536000, immutable');
                    header('ETag: ' . $etag);

                    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
                    if (is_string($ifNoneMatch) && trim($ifNoneMatch) === $etag) {
                        http_response_code(304);
                        exit;
                    }

                    readfile($cssPath);
                    exit;
                });
            },
        );

        $context->hooks()->add(
            HookName::THEME_ASSETS,
            static fn (): string => '/plugin/forum-stats/stats.css?v=' . rawurlencode($assetVersion),
        );

        $context->hooks()->add(
            HookName::HOME_AFTER_BOARDS,
            static function () use ($context): string {
                $app = $context->app();
                if ($app->request()->path() !== '/') {
                    return '';
                }

                $posts = $app->posts()->countAll();
                $topics = $app->topics()->countAll();
                $members = $app->users()->countAll();

                return (new StatsPanel())->render($posts, $topics, $members);
            },
        );
    }
}