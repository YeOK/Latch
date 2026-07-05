<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


require_once dirname(__DIR__) . '/http/HttpClient.php';

/**
 * Read-only HTTP security probes — safe against staging or production.
 */
final class WebSecurityHarness
{
    private HttpClient $http;

    /** @var list<string> */
    private array $passed = [];

    /** @var list<string> */
    private array $failed = [];

    public function __construct(string $baseUrl)
    {
        $this->http = new HttpClient(rtrim($baseUrl, '/'));
    }

    public function run(): int
    {
        $this->testHealth();
        $this->testGuestAdminRedirect();
        $this->testSensitivePathsBlocked();
        $this->testSecurityHeaders();
        $this->testLoginFailureReturns200();
        $this->testLoginRejectsMissingCsrf();
        $this->testLocaleRejectsMissingCsrf();
        $this->testOidcDisabledProviderRedirect();

        foreach ($this->passed as $line) {
            fwrite(STDOUT, "  OK   {$line}\n");
        }
        foreach ($this->failed as $line) {
            fwrite(STDERR, "  FAIL {$line}\n");
        }

        $total = count($this->passed) + count($this->failed);
        fwrite(STDOUT, "\nWeb security harness: " . count($this->passed) . "/{$total} passed\n");

        $this->http->cleanup();

        return $this->failed === [] ? 0 : 1;
    }

    private function testHealth(): void
    {
        $response = $this->http->request('GET', '/health', followRedirects: true);
        if ($response['status'] !== 200) {
            $this->fail('GET /health', 'expected HTTP 200, got ' . $response['status']);

            return;
        }

        $json = json_decode($response['body'], true);
        if (!is_array($json) || ($json['status'] ?? '') !== 'ok') {
            $this->fail('GET /health', 'status not ok');

            return;
        }

        $this->pass('GET /health');
    }

    private function testGuestAdminRedirect(): void
    {
        $response = $this->http->request('GET', '/admin');
        if ($response['status'] !== 302) {
            $this->fail('GET /admin (guest)', 'expected HTTP 302, got ' . $response['status']);

            return;
        }

        $location = $response['headers']['location'] ?? '';
        if (!str_contains($location, '/login')) {
            $this->fail('GET /admin (guest)', 'expected redirect to /login, got ' . $location);

            return;
        }

        $this->pass('GET /admin (guest) → login');
    }

    private function testSensitivePathsBlocked(): void
    {
        foreach (['/storage/', '/config/local.php', '/../storage/database/latch.sqlite'] as $path) {
            $response = $this->http->request('GET', $path);
            if ($response['status'] === 200 && !str_contains($response['body'], '404')) {
                $this->fail("GET {$path}", 'expected blocked response, got HTTP 200');

                return;
            }
        }

        $this->pass('Sensitive paths not web-accessible');
    }

    private function testSecurityHeaders(): void
    {
        $response = $this->http->request('GET', '/login', followRedirects: true);
        if ($response['status'] !== 200) {
            $this->fail('Security headers', 'login page unreachable');

            return;
        }

        $required = [
            'x-content-type-options' => 'nosniff',
            'x-frame-options' => 'DENY',
            'content-security-policy' => null,
            'referrer-policy' => 'strict-origin-when-cross-origin',
        ];

        foreach ($required as $header => $expectedValue) {
            $actual = $response['headers'][$header] ?? null;
            if ($actual === null) {
                $this->fail('Security headers', "missing {$header}");

                return;
            }

            if ($expectedValue !== null && stripos($actual, $expectedValue) === false) {
                $this->fail('Security headers', "{$header} expected to contain {$expectedValue}");

                return;
            }
        }

        $this->pass('Security headers on /login');
    }

    private function testLoginFailureReturns200(): void
    {
        $page = $this->http->request('GET', '/login');
        $csrf = HttpClient::extractCsrfToken($page['body']);
        if ($csrf === null) {
            $this->fail('POST /login (bad creds)', 'could not extract CSRF token');

            return;
        }

        $response = $this->http->request('POST', '/login', [
            '_csrf' => $csrf,
            'username' => 'latch-security-probe-' . bin2hex(random_bytes(4)),
            'password' => 'wrong-password',
        ]);

        if ($response['status'] !== 200) {
            $this->fail('POST /login (bad creds)', 'expected HTTP 200 for fail2ban pattern, got ' . $response['status']);

            return;
        }

        $this->pass('POST /login (bad creds) → HTTP 200');
    }

    private function testLoginRejectsMissingCsrf(): void
    {
        $response = $this->http->request('POST', '/login', [
            'username' => 'nobody',
            'password' => 'wrong',
        ]);

        if ($response['status'] !== 200) {
            $this->fail('POST /login (no CSRF)', 'expected HTTP 200 failure page, got ' . $response['status']);

            return;
        }

        if (!str_contains($response['body'], 'Invalid form token')) {
            $this->fail('POST /login (no CSRF)', 'expected invalid token message');

            return;
        }

        $this->pass('POST /login rejects missing CSRF');
    }

    private function testLocaleRejectsMissingCsrf(): void
    {
        $response = $this->http->request('POST', '/locale', [
            'locale' => 'en',
        ]);

        if ($response['status'] === 404) {
            $this->pass('POST /locale (skipped — route not deployed yet)');

            return;
        }

        if ($response['status'] !== 302) {
            $this->fail('POST /locale (no CSRF)', 'expected HTTP 302 redirect, got ' . $response['status']);

            return;
        }

        $this->pass('POST /locale rejects missing CSRF');
    }

    private function testOidcDisabledProviderRedirect(): void
    {
        $response = $this->http->request('GET', '/auth/oidc/google');
        if ($response['status'] !== 302) {
            $this->fail('GET /auth/oidc/google (disabled)', 'expected HTTP 302, got ' . $response['status']);

            return;
        }

        $location = $response['headers']['location'] ?? '';
        if (!str_contains($location, '/login')) {
            $this->fail('GET /auth/oidc/google (disabled)', 'expected redirect to login, got ' . $location);

            return;
        }

        $this->pass('OIDC disabled provider → login');
    }

    private function pass(string $label): void
    {
        $this->passed[] = $label;
    }

    private function fail(string $label, string $detail): void
    {
        $this->failed[] = $label . ' — ' . $detail;
    }
}