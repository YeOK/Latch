<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

/**
 * HTTP request wrapper.
 */
final class Request
{
    public function __construct(private readonly ?Config $config = null)
    {
    }

    public function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public function path(): string
    {
        $uri = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

        return '/' . trim((string) $uri, '/');
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        $value = $_COOKIE[$name] ?? $default;

        return is_string($value) ? $value : $default;
    }

    public function ip(): string
    {
        if ($this->trustCloudflareHeaders()) {
            $cfIp = $this->header('CF-Connecting-IP');
            if ($cfIp !== '' && filter_var($cfIp, FILTER_VALIDATE_IP)) {
                return $cfIp;
            }
        }

        if ($this->trustXForwardedFor()) {
            $xff = $this->header('X-Forwarded-For');
            if ($xff !== '') {
                $first = trim(explode(',', $xff)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) {
                    return $first;
                }
            }
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }

    private function trustCloudflareHeaders(): bool
    {
        if ($this->config !== null && $this->config->get('security.trust_cloudflare') === false) {
            return false;
        }

        // CF-Ray is set by Cloudflare edge — require it so clients cannot spoof CF-Connecting-IP alone.
        return $this->header('CF-Ray') !== '';
    }

    private function trustXForwardedFor(): bool
    {
        return $this->config !== null && (bool) $this->config->get('security.trust_x_forwarded_for', false);
    }

    public function isHttps(): bool
    {
        return self::detectHttps($this->config, $_SERVER);
    }

    /**
     * @param array<string, mixed> $server
     */
    public static function detectHttps(?Config $config, array $server): bool
    {
        if (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') {
            return true;
        }

        if (!self::trustForwardedProto($config, $server)) {
            return false;
        }

        return strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function trustForwardedProto(?Config $config, array $server): bool
    {
        if ($config !== null && (bool) $config->get('security.trust_forwarded_proto', false)) {
            return true;
        }

        if ($config !== null && $config->get('security.trust_cloudflare') === false) {
            return false;
        }

        // Require CF-Ray (same gate as CF-Connecting-IP) so clients cannot spoof proto alone.
        return ($server['HTTP_CF_RAY'] ?? '') !== '';
    }

    public function userAgent(): string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        return is_string($ua) ? $ua : '';
    }

    /**
     * Same-origin path only — blocks open redirects via Referer or stored URLs.
     */
    public function safeRedirectPath(string $url, string $fallback = '/'): string
    {
        $url = trim($url);
        if ($url === '' || !str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return $fallback;
        }

        return $url;
    }

    public function header(string $name, string $default = ''): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $_SERVER[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('Authorization');
        if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonBody(): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return $cache = [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $cache = [];
        }

        return $cache = is_array($decoded) ? $decoded : [];
    }

    public function jsonField(string $key, mixed $default = null): mixed
    {
        $body = $this->jsonBody();

        return $body[$key] ?? $default;
    }
}