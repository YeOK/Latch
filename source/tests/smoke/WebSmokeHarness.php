<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/http/HttpClient.php';
require_once dirname(__DIR__) . '/security/WebSecurityHarness.php';

/**
 * Live HTTP smoke tests — read-only by default; optional member flow when credentials supplied.
 */
final class WebSmokeHarness
{
    private HttpClient $http;

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
        $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
        $this->http = new HttpClient($baseUrl);
    }

    public function run(): int
    {
        $baseUrl = $this->http->baseUrl();
        if ($baseUrl === '') {
            $this->fail('config', 'base_url is required');

            return $this->finish();
        }

        fwrite(STDOUT, "Web smoke harness → {$baseUrl}\n\n");

        fwrite(STDOUT, "==> Security probes\n");
        $security = new WebSecurityHarness($baseUrl);
        $securityCode = $security->run();
        if ($securityCode !== 0) {
            return $securityCode;
        }

        fwrite(STDOUT, "\n==> Read-only smoke\n");
        $this->testHome();
        $this->testLoginPage();
        $this->testFeed();

        $username = trim((string) ($this->config['member_username'] ?? ''));
        $password = (string) ($this->config['member_password'] ?? '');
        if ($username !== '' && $password !== '') {
            fwrite(STDOUT, "\n==> Member session flow\n");
            $this->testMemberTopicAndReply($username, $password);
        } else {
            $this->pass('Member flow (skipped — set member_username + member_password in config)');
        }

        return $this->finish();
    }

    private function testHome(): void
    {
        $response = $this->http->request('GET', '/', followRedirects: true);
        if ($response['status'] !== 200) {
            $this->fail('GET /', 'expected HTTP 200, got ' . $response['status']);

            return;
        }

        $this->pass('GET /');
    }

    private function testLoginPage(): void
    {
        $response = $this->http->request('GET', '/login');
        if ($response['status'] !== 200) {
            $this->fail('GET /login', 'expected HTTP 200, got ' . $response['status']);

            return;
        }

        if (HttpClient::extractCsrfToken($response['body']) === null) {
            $this->fail('GET /login', 'missing CSRF field');

            return;
        }

        $this->pass('GET /login');
    }

    private function testFeed(): void
    {
        $response = $this->http->request('GET', '/feed.xml', followRedirects: true);
        if ($response['status'] !== 200) {
            $this->fail('GET /feed.xml', 'expected HTTP 200, got ' . $response['status']);

            return;
        }

        if (!str_contains($response['body'], '<rss')) {
            $this->fail('GET /feed.xml', 'expected RSS root element');

            return;
        }

        $this->pass('GET /feed.xml');
    }

    private function testMemberTopicAndReply(string $username, string $password): void
    {
        if (!$this->loginMember($username, $password)) {
            return;
        }

        $boardSlug = trim((string) ($this->config['board_slug'] ?? 'general'));
        if ($boardSlug === '') {
            $boardSlug = 'general';
        }

        $newTopicPage = $this->http->request('GET', '/board/' . rawurlencode($boardSlug) . '/new');
        if ($newTopicPage['status'] !== 200) {
            $this->fail('GET /board/{slug}/new', 'expected HTTP 200, got ' . $newTopicPage['status']);

            return;
        }

        $csrf = HttpClient::extractCsrfToken($newTopicPage['body']);
        if ($csrf === null) {
            $this->fail('POST /board/{slug}/new', 'missing CSRF token');

            return;
        }

        $title = 'Smoke test ' . gmdate('Y-m-d H:i:s') . ' ' . bin2hex(random_bytes(3));
        $body = "Automated smoke topic.\n\n```\necho hello\n```\n\n:smile:";

        $create = $this->http->request('POST', '/board/' . rawurlencode($boardSlug) . '/new', [
            '_csrf' => $csrf,
            'title' => $title,
            'body' => $body,
            'tags' => '',
        ]);

        if ($create['status'] !== 302) {
            $this->fail('POST /board/{slug}/new', 'expected HTTP 302, got ' . $create['status']);

            return;
        }

        $topicPath = $this->pathFromLocation($create['headers']['location'] ?? '');
        if ($topicPath === null) {
            $this->fail('POST /board/{slug}/new', 'missing redirect to new topic');

            return;
        }

        $topicPage = $this->http->request('GET', $topicPath, followRedirects: true);
        if ($topicPage['status'] !== 200 || !str_contains($topicPage['body'], $title)) {
            $this->fail('Topic view', 'created topic not visible');

            return;
        }

        if (!str_contains($topicPage['body'], 'code-block') || !str_contains($topicPage['body'], 'echo hello')) {
            $this->fail('Topic view', 'code fence not rendered');

            return;
        }

        $this->pass('Create topic with code fence');

        if (!preg_match('#/topic/(\d+)#', $topicPath, $matches)) {
            $this->fail('POST /topic/{id}/reply', 'could not parse topic id');

            return;
        }

        $topicId = $matches[1];
        $replyCsrf = HttpClient::extractCsrfToken($topicPage['body']);
        if ($replyCsrf === null) {
            $this->fail('POST /topic/{id}/reply', 'missing CSRF token');

            return;
        }

        $reply = $this->http->request('POST', '/topic/' . $topicId . '/reply', [
            '_csrf' => $replyCsrf,
            'body' => 'Smoke reply with `inline` code.',
        ]);

        if ($reply['status'] !== 302) {
            $this->fail('POST /topic/{id}/reply', 'expected HTTP 302, got ' . $reply['status']);

            return;
        }

        $afterReply = $this->http->request('GET', $topicPath, followRedirects: true);
        if (!str_contains($afterReply['body'], 'Smoke reply with')) {
            $this->fail('Topic reply', 'reply body not visible');

            return;
        }

        $this->pass('Reply to topic');
    }

    private function loginMember(string $username, string $password): bool
    {
        $page = $this->http->request('GET', '/login');
        $csrf = HttpClient::extractCsrfToken($page['body']);
        if ($csrf === null) {
            $this->fail('POST /login (member)', 'missing CSRF token');

            return false;
        }

        $response = $this->http->request('POST', '/login', [
            '_csrf' => $csrf,
            'username' => $username,
            'password' => $password,
        ]);

        if ($response['status'] === 302) {
            $location = $response['headers']['location'] ?? '';
            if (str_contains($location, '/login/2fa')) {
                $this->fail('POST /login (member)', 'account requires TOTP — use a member test account without 2FA');

                return false;
            }

            $this->pass('POST /login (member)');

            return true;
        }

        $this->fail('POST /login (member)', 'expected HTTP 302, got ' . $response['status']);

        return false;
    }

    private function pathFromLocation(string $location): ?string
    {
        if ($location === '') {
            return null;
        }

        $base = $this->http->baseUrl();
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

        $this->http->cleanup();

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