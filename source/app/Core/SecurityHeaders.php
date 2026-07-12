<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

/**
 * Applies consistent HTTP security headers.
 */
final class SecurityHeaders
{
    /**
     * @param list<string> $extraImgSrc Hostnames (no scheme) appended to CSP img-src.
     * @param list<string> $extraConnectSrc Hostnames (no scheme) appended to CSP connect-src.
     * @param list<string> $extraFrameSrc Hostnames (no scheme) appended to CSP frame-src.
     * @param list<string> $extraScriptSrc Hostnames (no scheme) appended to CSP script-src.
     */
    public static function apply(
        bool $isHttps = false,
        ?string $cspNonce = null,
        array $extraImgSrc = [],
        array $extraConnectSrc = [],
        array $extraFrameSrc = [],
        array $extraScriptSrc = [],
    ): void
    {
        if (function_exists('header_remove')) {
            header_remove('X-Powered-By');
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

        if ($isHttps) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        $scriptSrc = $cspNonce !== null
            ? "'self' 'nonce-{$cspNonce}'"
            : "'self'";

        foreach ($extraScriptSrc as $host) {
            $host = trim((string) $host);
            if ($host !== '' && preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?$/', $host) === 1) {
                $scriptSrc .= ' https://' . $host;
            }
        }

        $imgSrc = "'self' https://www.gravatar.com https://secure.gravatar.com data:";
        foreach ($extraImgSrc as $host) {
            $host = trim((string) $host);
            if ($host !== '' && preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?$/', $host) === 1) {
                $imgSrc .= ' https://' . $host;
            }
        }

        $connectSrc = "'self'";
        foreach ($extraConnectSrc as $host) {
            $host = trim((string) $host);
            if ($host !== '' && preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?$/', $host) === 1) {
                $connectSrc .= ' https://' . $host;
            }
        }

        $frameSrc = 'https://challenges.cloudflare.com';
        foreach ($extraFrameSrc as $host) {
            $host = trim((string) $host);
            if ($host !== '' && preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?$/', $host) === 1) {
                $frameSrc .= ' https://' . $host;
            }
        }

        header(
            'Content-Security-Policy: default-src \'self\'; '
            . "script-src {$scriptSrc} https://challenges.cloudflare.com; "
            . 'style-src \'self\'; '
            . "img-src {$imgSrc}; "
            . "connect-src {$connectSrc}; "
            . "font-src \'self\'; "
            . "frame-src {$frameSrc}; "
            . 'form-action \'self\'; '
            . 'frame-ancestors \'none\'; '
            . 'base-uri \'self\''
        );
    }

    /**
     * Replace CSP nonce attributes in cached HTML so they match the current request header.
     */
    public static function rewriteHtmlNonces(string $html, string $nonce): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/i', $nonce)) {
            return $html;
        }

        return preg_replace(
            '/\bnonce="[a-f0-9]{32}"/i',
            'nonce="' . $nonce . '"',
            $html
        ) ?? $html;
    }

    public static function detectHttps(Config $config, Request $request): bool
    {
        if ($request->isHttps()) {
            return true;
        }

        $siteUrl = (string) $config->get('site.url', '');

        return str_starts_with(strtolower($siteUrl), 'https://');
    }
}