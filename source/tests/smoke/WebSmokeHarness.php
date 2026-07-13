<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


require_once dirname(__DIR__) . '/http/HttpClient.php';
require_once dirname(__DIR__) . '/security/WebSecurityHarness.php';

/**
 * Live HTTP smoke tests — guest, member, and admin URL probes.
 */
final class WebSmokeHarness
{
    /** @var list<string> */
    private array $passed = [];

    /** @var list<string> */
    private array $failed = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly array $config,
    ) {
    }

    public function run(): int
    {
        $baseUrl = rtrim((string) ($this->config['base_url'] ?? ''), '/');
        if ($baseUrl === '') {
            $this->fail('config', 'base_url is required');

            return $this->finish();
        }

        fwrite(STDOUT, "Web smoke harness → {$baseUrl}\n\n");

        fwrite(STDOUT, "==> Security probes (guest)\n");
        $security = new WebSecurityHarness($baseUrl);
        $securityCode = $security->run();
        if ($securityCode !== 0) {
            return $securityCode;
        }

        fwrite(STDOUT, "\n==> Guest smoke\n");
        $guestHttp = new HttpClient($baseUrl);
        $this->runGuestProbes($guestHttp);
        $guestHttp->cleanup();

        $memberUser = trim((string) ($this->config['member_username'] ?? ''));
        $memberPass = (string) ($this->config['member_password'] ?? '');
        if ($memberUser !== '' && $memberPass !== '') {
            fwrite(STDOUT, "\n==> Member smoke\n");
            $memberHttp = new HttpClient($baseUrl);
            $this->runMemberProbes($memberHttp, $memberUser, $memberPass);
            $memberHttp->cleanup();
        } else {
            $this->pass('Member smoke (skipped — set member_username + member_password)');
        }

        $adminUser = trim((string) ($this->config['admin_username'] ?? ''));
        $adminPass = (string) ($this->config['admin_password'] ?? '');
        if ($adminUser !== '' && $adminPass !== '') {
            fwrite(STDOUT, "\n==> Admin smoke\n");
            $adminHttp = new HttpClient($baseUrl);
            $this->runAdminProbes($adminHttp, $adminUser, $adminPass);
            $adminHttp->cleanup();
        } else {
            $this->pass('Admin smoke (skipped — set admin_username + admin_password)');
        }

        return $this->finish();
    }

    private function runGuestProbes(HttpClient $http): void
    {
        $this->testHome($http, 'guest');
        $this->testLoginPage($http, 'guest');
        $this->testFeed($http, 'guest');
        $this->testGuestAdminRedirect($http);
        $this->testConfiguredUrls(
            $http,
            'guest',
            $this->config['guest_urls'] ?? ['/'],
            200,
        );
    }

    /**
     * @param list<string> $paths
     */
    private function runMemberProbes(HttpClient $http, string $username, string $password): void
    {
        if (!$this->login($http, $username, $password, 'member')) {
            return;
        }

        $this->testConfiguredUrls(
            $http,
            'member',
            $this->config['member_urls'] ?? [],
            200,
        );

        $this->testMemberTopicAndReply($http);
    }

    /**
     * @param list<string> $paths
     */
    private function runAdminProbes(HttpClient $http, string $username, string $password): void
    {
        if (!$this->login($http, $username, $password, 'admin')) {
            return;
        }

        $adminUrls = $this->config['admin_urls'] ?? ['/admin', '/admin/plugins'];
        $this->testConfiguredUrls($http, 'admin', $adminUrls, 200);

        $plugins = $http->request('GET', '/admin/plugins', followRedirects: true);
        if ($plugins['status'] !== 200) {
            $this->fail('GET /admin/plugins (admin)', 'expected HTTP 200, got ' . $plugins['status']);
        } elseif (str_contains($plugins['body'], 'Could not load this page')) {
            $this->fail('GET /admin/plugins (admin)', 'account overlay SPA error');
        } else {
            $this->pass('GET /admin/plugins (admin)');
        }
    }

    private function testHome(HttpClient $http, string $role): void
    {
        $response = $http->request('GET', '/', followRedirects: true);
        if ($response['status'] !== 200) {
            $this->fail("GET / ({$role})", 'expected HTTP 200, got ' . $response['status']);

            return;
        }

        $this->pass("GET / ({$role})");
    }

    private function testLoginPage(HttpClient $http, string $role): void
    {
        $response = $http->request('GET', '/login');
        if ($response['status'] !== 200) {
            $this->fail("GET /login ({$role})", 'expected HTTP 200, got ' . $response['status']);

            return;
        }

        if (HttpClient::extractCsrfToken($response['body']) === null) {
            $this->fail("GET /login ({$role})", 'missing CSRF field');

            return;
        }

        $this->pass("GET /login ({$role})");
    }

    private function testFeed(HttpClient $http, string $role): void
    {
        $response = $http->request('GET', '/feed.xml', followRedirects: true);
        if ($response['status'] !== 200) {
            $this->fail("GET /feed.xml ({$role})", 'expected HTTP 200, got ' . $response['status']);

            return;
        }

        if (!str_contains($response['body'], '<rss')) {
            $this->fail("GET /feed.xml ({$role})", 'expected RSS root element');

            return;
        }

        $this->pass("GET /feed.xml ({$role})");
    }

    private function testGuestAdminRedirect(HttpClient $http): void
    {
        $response = $http->request('GET', '/admin');
        if ($response['status'] !== 302) {
            $this->fail('GET /admin (guest)', 'expected HTTP 302, got ' . $response['status']);

            return;
        }

        $location = $response['headers']['location'] ?? '';
        if (!str_contains($location, '/login')) {
            $this->fail('GET /admin (guest)', 'expected redirect to login');

            return;
        }

        $this->pass('GET /admin (guest) → login');
    }

    /**
     * @param list<string>|mixed $paths
     */
    private function testConfiguredUrls(HttpClient $http, string $role, mixed $paths, int $expectedStatus): void
    {
        if (!is_array($paths) || $paths === []) {
            return;
        }

        foreach ($paths as $path) {
            $path = trim((string) $path);
            if ($path === '' || !str_starts_with($path, '/')) {
                continue;
            }

            $response = $http->request('GET', $path, followRedirects: true);
            if ($response['status'] !== $expectedStatus) {
                $this->fail("GET {$path} ({$role})", 'expected HTTP ' . $expectedStatus . ', got ' . $response['status']);
                continue;
            }

            if ($response['status'] >= 500) {
                $this->fail("GET {$path} ({$role})", 'server error');
                continue;
            }

            $this->pass("GET {$path} ({$role})");
        }
    }

    private function testMemberTopicAndReply(HttpClient $http): void
    {
        $boardSlug = trim((string) ($this->config['board_slug'] ?? 'general'));
        if ($boardSlug === '') {
            $boardSlug = 'general';
        }

        $newTopicPage = $http->request('GET', '/board/' . rawurlencode($boardSlug) . '/new');
        if ($newTopicPage['status'] !== 200) {
            $this->fail('GET /board/{slug}/new (member)', 'expected HTTP 200, got ' . $newTopicPage['status']);

            return;
        }

        $csrf = HttpClient::extractCsrfToken($newTopicPage['body']);
        if ($csrf === null) {
            $this->fail('POST /board/{slug}/new (member)', 'missing CSRF token');

            return;
        }

        $title = 'Smoke test ' . gmdate('Y-m-d H:i:s') . ' ' . bin2hex(random_bytes(3));
        $body = "Automated smoke topic.\n\n```\necho hello\n```\n\n:smile:";

        $create = $http->request('POST', '/board/' . rawurlencode($boardSlug) . '/new', [
            '_csrf' => $csrf,
            'title' => $title,
            'body' => $body,
            'tags' => '',
        ]);

        if ($create['status'] !== 302) {
            $this->fail('POST /board/{slug}/new (member)', 'expected HTTP 302, got ' . $create['status']);

            return;
        }

        $topicPath = $this->pathFromLocation($http, $create['headers']['location'] ?? '');
        if ($topicPath === null) {
            $this->fail('POST /board/{slug}/new (member)', 'missing redirect to new topic');

            return;
        }

        $topicPage = $http->request('GET', $topicPath, followRedirects: true);
        if ($topicPage['status'] !== 200 || !str_contains($topicPage['body'], $title)) {
            $this->fail('Topic view (member)', 'created topic not visible');

            return;
        }

        if (!str_contains($topicPage['body'], 'code-block') || !str_contains($topicPage['body'], 'echo hello')) {
            $this->fail('Topic view (member)', 'code fence not rendered');

            return;
        }

        $this->pass('Create topic with code fence (member)');

        if (!preg_match('#/topic/(\d+)#', $topicPath, $matches)) {
            $this->fail('POST /topic/{id}/reply (member)', 'could not parse topic id');

            return;
        }

        $topicId = $matches[1];
        $replyCsrf = HttpClient::extractCsrfToken($topicPage['body']);
        if ($replyCsrf === null) {
            $this->fail('POST /topic/{id}/reply (member)', 'missing CSRF token');

            return;
        }

        $reply = $http->request('POST', '/topic/' . $topicId . '/reply', [
            '_csrf' => $replyCsrf,
            'body' => 'Smoke reply with `inline` code.',
        ]);

        if ($reply['status'] !== 302) {
            $this->fail('POST /topic/{id}/reply (member)', 'expected HTTP 302, got ' . $reply['status']);

            return;
        }

        $afterReply = $http->request('GET', $topicPath, followRedirects: true);
        if (!str_contains($afterReply['body'], 'Smoke reply with')) {
            $this->fail('Topic reply (member)', 'reply body not visible');

            return;
        }

        $this->pass('Reply to topic (member)');
    }

    private function login(HttpClient $http, string $username, string $password, string $role): bool
    {
        $page = $http->request('GET', '/login');
        $csrf = HttpClient::extractCsrfToken($page['body']);
        if ($csrf === null) {
            $this->fail("POST /login ({$role})", 'missing CSRF token');

            return false;
        }

        $response = $http->request('POST', '/login', [
            '_csrf' => $csrf,
            'username' => $username,
            'password' => $password,
        ]);

        if ($response['status'] === 302) {
            $location = $response['headers']['location'] ?? '';
            if (str_contains($location, '/login/2fa')) {
                $this->fail("POST /login ({$role})", 'account requires TOTP — use a test account without 2FA');

                return false;
            }

            $this->pass("POST /login ({$role})");

            return true;
        }

        $this->fail("POST /login ({$role})", 'expected HTTP 302, got ' . $response['status']);

        return false;
    }

    private function pathFromLocation(HttpClient $http, string $location): ?string
    {
        if ($location === '') {
            return null;
        }

        $base = $http->baseUrl();
        if (str_starts_with($location, $base)) {
            return substr($location, strlen($base)) ?: '/';
        }

        if (str_starts_with($location, '/')) {
            return $location;
        }

        return null;
    }

    private function finish(): int
    {
        foreach ($this->passed as $line) {
            fwrite(STDOUT, "  OK   {$line}\n");
        }
        foreach ($this->failed as $line) {
            fwrite(STDERR, "  FAIL {$line}\n");
        }

        $total = count($this->passed) + count($this->failed);
        fwrite(STDOUT, "\nWeb smoke harness: " . count($this->passed) . "/{$total} passed\n");

        return $this->failed === [] ? 0 : 1;
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