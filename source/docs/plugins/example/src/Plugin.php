<?php

declare(strict_types=1);

namespace Latch\Plugins\Example;

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
        $hooks = $context->hooks();

        $hooks->add(HookName::ROUTE_REGISTER, function (Router $router, Application $app): void {
            $router->get('/plugin/example', static function () use ($app): void {
                Response::json([
                    'ok' => true,
                    'plugin' => 'example',
                    'message' => 'Latch example plugin is active.',
                    'latch_version' => $app->latchVersion(),
                ]);
            });
        });

        $hooks->add(HookName::LAYOUT_FOOTER, static function (): string {
            return '<p class="footer-plugin-note muted">Example plugin active — <a href="/plugin/example">/plugin/example</a></p>';
        });
    }
}