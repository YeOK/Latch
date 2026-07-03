<?php

declare(strict_types=1);

/**
 * Latch front controller — the only PHP entry point exposed to the web.
 */

define('LATCH_ROOT', dirname(__DIR__));
define('LATCH_START', microtime(true));

@ini_set('expose_php', '0');
if (function_exists('header_remove')) {
    header_remove('X-Powered-By');
}

$autoload = LATCH_ROOT . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Latch is not installed yet.\n\n";
    echo "From the source directory run:\n";
    echo "  php composer.phar install --no-dev\n";
    echo "  php bin/latch install\n";
    exit;
}

require $autoload;

use Latch\Core\Application;
use Latch\Core\Config;
use Latch\Support\SiteLock;

$config = new Config(LATCH_ROOT . '/config');
$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

if ($config->isInstalled()) {
    $storagePath = (string) $config->get('paths.storage');

    if ($requestPath === '/maintenance/unlock') {
        SiteLock::handleUnlockWeb($storagePath);
    }

    if (SiteLock::isLocked($storagePath) && !SiteLock::isExemptWebPath($requestPath)) {
        SiteLock::respondLocked($requestPath);
    }
}

$app = new Application();
$app->run();