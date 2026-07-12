<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Support;

use Latch\Core\Response;

/**
 * File-based site lock — blocks web traffic without touching SQLite.
 *
 * Used before updates/migrations so operators can quiesce reads and writes.
 * Enable from admin (one DB write to auth, then file only) or CLI.
 * Disable via CLI or token URL stored in the lock file at enable time.
 */
final class SiteLock
{
    public const FILENAME = 'site-lock.json';

    public static function lockPath(string $storagePath): string
    {
        return rtrim($storagePath, '/') . '/' . self::FILENAME;
    }

    public static function isLocked(string $storagePath): bool
    {
        return is_file(self::lockPath($storagePath));
    }

    /**
     * @return array{enabled_at: string, message: string, enabled_by: ?string, unlock_token: string}|null
     */
    public static function read(string $storagePath): ?array
    {
        $path = self::lockPath($storagePath);
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data) || !isset($data['unlock_token']) || !is_string($data['unlock_token'])) {
            return null;
        }

        return [
            'enabled_at' => is_string($data['enabled_at'] ?? null) ? $data['enabled_at'] : '',
            'message' => is_string($data['message'] ?? null) ? $data['message'] : 'Site maintenance in progress.',
            'enabled_by' => is_string($data['enabled_by'] ?? null) ? $data['enabled_by'] : null,
            'unlock_token' => $data['unlock_token'],
        ];
    }

    /**
     * @return array{unlock_token: string, unlock_path: string}
     */
    public static function enable(string $storagePath, string $message = '', ?string $enabledBy = null): array
    {
        $dir = rtrim($storagePath, '/');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create storage directory for site lock.');
        }

        $token = bin2hex(random_bytes(16));
        $message = trim($message) !== '' ? trim($message) : 'Site maintenance in progress. Please try again shortly.';

        $payload = [
            'enabled_at' => gmdate('c'),
            'message' => $message,
            'enabled_by' => $enabledBy,
            'unlock_token' => $token,
        ];

        $path = self::lockPath($storagePath);
        $written = file_put_contents(
            $path,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            LOCK_EX,
        );

        if ($written === false) {
            throw new \RuntimeException('Failed to write site lock file.');
        }

        @chmod($path, 0660);

        return [
            'unlock_token' => $token,
            'unlock_path' => '/maintenance/unlock',
        ];
    }

    /**
     * @return 'not_locked'|'disabled'|'denied'
     */
    public static function disable(string $storagePath): string
    {
        $path = self::lockPath($storagePath);
        if (!is_file($path)) {
            return 'not_locked';
        }

        if (@unlink($path)) {
            return 'disabled';
        }

        return 'denied';
    }

    public static function storageDirWritable(string $storagePath): bool
    {
        $dir = rtrim($storagePath, '/');

        return is_dir($dir) && is_writable($dir);
    }

    public static function cliUnlockHint(): string
    {
        return self::cliHint('lock', 'off');
    }

    public static function cliHint(string ...$args): string
    {
        $command = implode(' ', $args);
        if (is_file('/usr/bin/latch')) {
            return 'sudo latch ' . $command;
        }

        $user = getenv('LATCH_WEB_USER') ?: getenv('WEB_USER') ?: 'apache';

        return 'sudo -u ' . $user . ' php bin/latch ' . $command;
    }

    public static function isExemptWebPath(string $path): bool
    {
        if ($path === '/maintenance/unlock' || $path === '/admin/site-lock/enabled') {
            return true;
        }

        if (str_starts_with($path, '/assets/') || str_starts_with($path, '/branding/')) {
            return true;
        }

        return false;
    }

    public static function respondLocked(string $requestPath): void
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $wantsJson = str_starts_with($requestPath, '/api/')
            || str_contains($accept, 'application/json');

        if ($wantsJson) {
            Response::json([
                'error' => 'maintenance',
                'message' => 'Site is temporarily unavailable for maintenance.',
            ], 503);
        }

        $body = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Maintenance</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem auto; max-width: 36rem; line-height: 1.5; color: #1a1a1a; }
        h1 { font-size: 1.5rem; }
        p { color: #444; }
    </style>
</head>
<body>
    <h1>Down for maintenance</h1>
    <p>The forum is temporarily offline while we perform an update. Please try again in a few minutes.</p>
</body>
</html>
HTML;

        Response::html($body, 503);
        exit;
    }

    public static function handleUnlockWeb(string $storagePath): void
    {
        $state = self::read($storagePath);
        if ($state === null) {
            Response::html(
                '<!DOCTYPE html><html><body><p>Site is not locked.</p><p><a href="/">Home</a></p></body></html>',
                200,
            );
            exit;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if ($method === 'POST') {
            $token = trim((string) ($_POST['token'] ?? ''));
            if ($token !== '' && hash_equals($state['unlock_token'], $token)) {
                self::disable($storagePath);
                Response::redirect('/');
            }

            Response::html(self::unlockFormHtml($state['message'], true), 400);
            exit;
        }

        Response::html(self::unlockFormHtml($state['message'], false), 200);
        exit;
    }

    private static function unlockFormHtml(string $message, bool $invalidToken): string
    {
        $error = $invalidToken
            ? '<p style="color:#a00;">Invalid unlock token.</p>'
            : '';
        $escaped = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>End maintenance</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 2rem auto; max-width: 28rem; line-height: 1.5; }
        label { display: block; margin: 1rem 0 0.25rem; }
        input { width: 100%; padding: 0.5rem; box-sizing: border-box; }
        button { margin-top: 1rem; padding: 0.5rem 1rem; }
    </style>
</head>
<body>
    <h1>End maintenance</h1>
    <p>{$escaped}</p>
    {$error}
    <form method="post" action="/maintenance/unlock">
        <label for="token">Unlock token</label>
        <input id="token" name="token" type="password" autocomplete="off" required>
        <button type="submit">Bring site back online</button>
    </form>
</body>
</html>
HTML;
    }
}