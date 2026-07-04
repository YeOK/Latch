<?php

declare(strict_types=1);

namespace Latch\Plugins\MdImport;

use Latch\Core\Application;
use Latch\Core\Plugins\HookName;
use Latch\Core\Plugins\PluginContext;
use Latch\Core\Plugins\PluginInterface;
use Latch\Core\Response;
use Latch\Core\Router;

final class Plugin implements PluginInterface
{
    public function register(PluginContext $context): void
    {
        $pluginPath = $context->path();
        $assetVersion = $context->app()->assetVersion();

        $context->hooks()->add(
            HookName::ROUTE_REGISTER,
            static function (Router $router, Application $app) use ($pluginPath, $assetVersion): void {
                $router->get('/admin/md-import', static function () use ($app): void {
                    (new ImportPage($app))->render();
                });

                $router->post('/admin/md-import', static function () use ($app): void {
                    (new ImportHandler($app))->handle();
                });

                $router->get('/plugin/md-import/md-import.css', static function () use ($pluginPath, $assetVersion): void {
                    self::serveAsset($pluginPath . '/assets/md-import.css', 'text/css', $assetVersion);
                });
            },
        );

        $context->hooks()->add(
            HookName::ADMIN_MENU,
            static fn (): array => [
                'label' => 'Import markdown',
                'href' => '/admin/md-import',
                'match' => '/admin/md-import',
            ],
        );

        $context->hooks()->add(
            HookName::THEME_ASSETS,
            static fn (): string => '/plugin/md-import/md-import.css?v=' . rawurlencode($assetVersion),
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