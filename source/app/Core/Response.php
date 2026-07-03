<?php

declare(strict_types=1);

namespace Latch\Core;

/**
 * HTTP response helpers.
 */
final class Response
{
    public static function html(string $body, int $status = 200, bool $cacheable = false): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');

        if ($cacheable) {
            $etag = '"' . hash('sha256', $body) . '"';
            header('ETag: ' . $etag);
            header('Cache-Control: public, max-age=60, must-revalidate');

            $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
            if (is_string($ifNoneMatch) && trim($ifNoneMatch) === $etag) {
                http_response_code(304);
                exit;
            }
        } else {
            header('Cache-Control: no-store');
        }

        echo $body;
    }

    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function sitemapXml(string $body, int $status = 200, bool $cacheable = false): void
    {
        http_response_code($status);
        header('Content-Type: application/xml; charset=utf-8');

        if ($cacheable) {
            $etag = '"' . hash('sha256', $body) . '"';
            header('ETag: ' . $etag);
            header('Cache-Control: public, max-age=300, must-revalidate');

            $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
            if (is_string($ifNoneMatch) && trim($ifNoneMatch) === $etag) {
                http_response_code(304);
                exit;
            }
        } else {
            header('Cache-Control: no-store');
        }

        echo $body;
        exit;
    }

    public static function plainText(string $body, int $status = 200, bool $cacheable = false): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');

        if ($cacheable) {
            $etag = '"' . hash('sha256', $body) . '"';
            header('ETag: ' . $etag);
            header('Cache-Control: public, max-age=3600, must-revalidate');

            $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
            if (is_string($ifNoneMatch) && trim($ifNoneMatch) === $etag) {
                http_response_code(304);
                exit;
            }
        } else {
            header('Cache-Control: no-store');
        }

        echo $body;
        exit;
    }

    public static function xml(string $body, int $status = 200, bool $cacheable = false): void
    {
        http_response_code($status);
        header('Content-Type: application/rss+xml; charset=utf-8');

        if ($cacheable) {
            $etag = '"' . hash('sha256', $body) . '"';
            header('ETag: ' . $etag);
            header('Cache-Control: public, max-age=120, must-revalidate');

            $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
            if (is_string($ifNoneMatch) && trim($ifNoneMatch) === $etag) {
                http_response_code(304);
                exit;
            }
        } else {
            header('Cache-Control: no-store');
        }

        echo $body;
        exit;
    }

    public static function redirect(string $url, int $status = 302): void
    {
        http_response_code($status);
        header('Location: ' . $url);
        exit;
    }

    public static function notFound(string $message = 'Not found'): void
    {
        self::html('<h1>404</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>', 404);
        exit;
    }

    public static function forbidden(string $message = 'Forbidden'): void
    {
        self::html('<h1>403</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>', 403);
        exit;
    }
}