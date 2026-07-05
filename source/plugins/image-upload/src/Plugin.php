<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Plugins\ImageUpload;

use Latch\Core\Application;
use Latch\Core\Plugins\HookName;
use Latch\Core\Plugins\PluginContext;
use Latch\Core\Plugins\PluginInterface;
use Latch\Core\Plugins\PostSaveContext;
use Latch\Core\Response;
use Latch\Core\Router;

final class Plugin implements PluginInterface
{
    private ?PluginConfig $config = null;

    public function register(PluginContext $context): void
    {
        $this->config = PluginConfig::fromApp($context->app());
        if ($this->config === null) {
            return;
        }

        $config = $this->config;
        $pluginPath = $context->path();
        $assetVersion = $context->app()->assetVersion();

        $context->hooks()->add(
            HookName::ROUTE_REGISTER,
            static function (Router $router, Application $app) use ($config, $pluginPath, $assetVersion): void {
                $router->post('/plugin/image-upload/presign', static function () use ($app, $config): void {
                    (new PresignHandler($app, $config))->handle();
                });

                $router->get('/plugin/image-upload/upload.js', static function () use ($pluginPath, $assetVersion): void {
                    self::serveAsset($pluginPath . '/assets/upload.js', 'application/javascript', $assetVersion);
                });

                $router->get('/plugin/image-upload/upload.css', static function () use ($pluginPath, $assetVersion): void {
                    self::serveAsset($pluginPath . '/assets/upload.css', 'text/css', $assetVersion);
                });
            },
        );

        $context->hooks()->add(
            HookName::THEME_ASSETS,
            static fn (): string => '/plugin/image-upload/upload.css?v=' . rawurlencode($assetVersion),
        );

        $context->hooks()->add(
            HookName::THEME_SCRIPTS,
            static fn (): string => '/plugin/image-upload/upload.js?v=' . rawurlencode($assetVersion),
        );

        $context->hooks()->add(
            HookName::EDITOR_COMPOSE,
            static fn (): string => '<button type="button" class="composer-btn" data-action="image-upload" title="Insert image">'
                . '<span class="composer-btn-label">Image</span></button>',
        );

        $context->hooks()->add(
            HookName::POST_FORMAT_IMAGE_HOST,
            static fn (bool $allowed, string $host): bool => $allowed || $config->isAllowedPublicHost($host),
        );

        $context->hooks()->add(
            HookName::CSP_IMG_SRC,
            static fn (): string => $config->publicHost,
        );

        $context->hooks()->add(
            HookName::CSP_CONNECT_SRC,
            static fn (): string => $config->r2Host,
        );

        $guard = new BodyGuard($config);
        $context->hooks()->add(
            HookName::POST_BEFORE_SAVE,
            static function (PostSaveContext $ctx) use ($guard): void {
                $reason = $guard->validate($ctx);
                if ($reason !== null) {
                    $ctx->reject($reason);
                }
            },
            20,
        );
    }

    private static function serveAsset(string $path, string $contentType, string $assetVersion): void
    {
        if (!is_file($path)) {
            Response::notFound();

            return;
        }

        $etag = '"' . hash('sha256', $path . '|' . filemtime($path) . '|' . $assetVersion) . '"';
        http_response_code(200);
        header('Content-Type: ' . $contentType . '; charset=utf-8');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: ' . $etag);

        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if (is_string($ifNoneMatch) && trim($ifNoneMatch) === $etag) {
            http_response_code(304);
            exit;
        }

        readfile($path);
        exit;
    }
}