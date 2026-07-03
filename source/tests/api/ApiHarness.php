<?php

declare(strict_types=1);

/**
 * Live HTTP smoke tests for /api/v1 and /oauth/token.
 */
final class ApiHarness
{
    private string $baseUrl;
    private ?string $clientId;
    private ?string $clientSecret;
    /** @var list<string> */
    private array $expectGuestBoardSlugs;

    /** @var list<string> */
    private array $passed = [];

    /** @var list<string> */
    private array $failed = [];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
        $this->clientId = isset($config['client_id']) ? (string) $config['client_id'] : null;
        $this->clientSecret = isset($config['client_secret']) ? (string) $config['client_secret'] : null;
        $raw = $config['expect_guest_board_slugs'] ?? [];
        $this->expectGuestBoardSlugs = is_array($raw) ? array_values(array_map('strval', $raw)) : [];
    }

    public function run(): int
    {
        if ($this->baseUrl === '') {
            $this->fail('config', 'base_url is required');

            return 1;
        }

        $this->testHealth();
        $this->testGuestApiMeta();
        $this->testGuestBoards();
        $this->testInvalidOAuthClient();

        if ($this->clientId === null || $this->clientId === '' || $this->clientSecret === null || $this->clientSecret === '') {
            $this->fail('oauth', 'client_id and client_secret required in config.local.php for token tests');
        } else {
            $token = $this->testClientCredentialsToken();
            if ($token !== null) {
                $this->testAuthenticatedApiMeta($token);
                $this->testAuthenticatedBoards($token);
                $this->testBoardTopicsIfPresent($token);
            }
        }

        foreach ($this->passed as $line) {
            fwrite(STDOUT, "  OK   {$line}\n");
        }
        foreach ($this->failed as $line) {
            fwrite(STDERR, "  FAIL {$line}\n");
        }

        $total = count($this->passed) + count($this->failed);
        fwrite(STDOUT, "\nAPI harness: " . count($this->passed) . "/{$total} passed\n");

        return $this->failed === [] ? 0 : 1;
    }

    private function testHealth(): void
    {
        $response = $this->request('GET', '/health');
        if ($response['status'] !== 200) {
            $this->fail('GET /health', 'expected HTTP 200, got ' . $response['status']);

            return;
        }

        $json = $this->decodeJson($response['body']);
        if (($json['status'] ?? '') !== 'ok') {
            $this->fail('GET /health', 'status not ok');

            return;
        }

        $this->pass('GET /health');
    }

    private function testGuestApiMeta(): void
    {
        $response = $this->request('GET', '/api/v1');
        if ($response['status'] !== 200) {
            $this->fail('GET /api/v1 (guest)', 'expected HTTP 200, got ' . $response['status']);

            return;
        }

        $json = $this->decodeJson($response['body']);
        if (($json['data']['version'] ?? '') !== 'v1') {
            $this->fail('GET /api/v1 (guest)', 'missing data.version v1');

            return;
        }

        if (($json['data']['authenticated'] ?? true) !== false) {
            $this->fail('GET /api/v1 (guest)', 'expected authenticated=false');

            return;
        }

        $this->pass('GET /api/v1 (guest)');
    }

    private function testGuestBoards(): void
    {
        $response = $this->request('GET', '/api/v1/boards');
        if ($response['status'] !== 200) {
            $this->fail('GET /api/v1/boards (guest)', 'expected HTTP 200, got ' . $response['status']);

            return;
        }

        $json = $this->decodeJson($response['body']);
        if (!is_array($json['data'] ?? null)) {
            $this->fail('GET /api/v1/boards (guest)', 'data is not an array');

            return;
        }

        if ($this->expectGuestBoardSlugs !== []) {
            $slugs = array_map(
                static fn (array $row): string => (string) ($row['slug'] ?? ''),
                $json['data'],
            );
            foreach ($this->expectGuestBoardSlugs as $expected) {
                if (!in_array($expected, $slugs, true)) {
                    $this->fail(
                        'GET /api/v1/boards (guest)',
                        "expected board slug '{$expected}' in [" . implode(', ', $slugs) . ']',
                    );

                    return;
                }
            }
        }

        $this->pass('GET /api/v1/boards (guest)');
    }

    private function testInvalidOAuthClient(): void
    {
        $response = $this->request('POST', '/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => 'latch_invalid_test_client',
            'client_secret' => 'invalid',
        ]);
        if ($response['status'] !== 401) {
            $this->fail('POST /oauth/token (invalid client)', 'expected HTTP 401, got ' . $response['status']);

            return;
        }

        $this->pass('POST /oauth/token (invalid client → 401)');
    }

    private function testClientCredentialsToken(): ?string
    {
        $response = $this->request('POST', '/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => 'read',
        ]);

        if ($response['status'] !== 200) {
            $this->fail('POST /oauth/token', 'expected HTTP 200, got ' . $response['status'] . ' — ' . $response['body']);

            return null;
        }

        $json = $this->decodeJson($response['body']);
        $token = (string) ($json['access_token'] ?? '');
        if ($token === '' || !str_starts_with($token, 'latch_at_')) {
            $this->fail('POST /oauth/token', 'missing access_token');

            return null;
        }

        if (($json['token_type'] ?? '') !== 'Bearer') {
            $this->fail('POST /oauth/token', 'token_type is not Bearer');

            return null;
        }

        $this->pass('POST /oauth/token (client_credentials)');

        return $token;
    }

    private function testAuthenticatedApiMeta(string $token): void
    {
        $response = $this->request('GET', '/api/v1', bearer: $token);
        if ($response['status'] !== 200) {
            $this->fail('GET /api/v1 (bearer)', 'expected HTTP 200, got ' . $response['status']);

            return;
        }

        $json = $this->decodeJson($response['body']);
        if (($json['data']['client_id'] ?? '') !== $this->clientId) {
            $this->fail('GET /api/v1 (bearer)', 'client_id mismatch');

            return;
        }

        $this->pass('GET /api/v1 (bearer)');
    }

    private function testAuthenticatedBoards(string $token): void
    {
        $response = $this->request('GET', '/api/v1/boards', bearer: $token);
        if ($response['status'] !== 200) {
            $this->fail('GET /api/v1/boards (bearer)', 'expected HTTP 200, got ' . $response['status']);

            return;
        }

        $this->pass('GET /api/v1/boards (bearer)');
    }

    private function testBoardTopicsIfPresent(string $token): void
    {
        $response = $this->request('GET', '/api/v1/boards', bearer: $token);
        $json = $this->decodeJson($response['body']);
        $boards = is_array($json['data'] ?? null) ? $json['data'] : [];
        if ($boards === []) {
            $this->pass('GET /api/v1/boards/{slug}/topics (skipped — no boards)');

            return;
        }

        $slug = (string) ($boards[0]['slug'] ?? '');
        if ($slug === '') {
            return;
        }

        $topicsResponse = $this->request('GET', '/api/v1/boards/' . rawurlencode($slug) . '/topics', bearer: $token);
        if ($topicsResponse['status'] !== 200) {
            $this->fail("GET /api/v1/boards/{$slug}/topics", 'expected HTTP 200, got ' . $topicsResponse['status']);

            return;
        }

        $this->pass("GET /api/v1/boards/{$slug}/topics (bearer)");

        $topicsJson = $this->decodeJson($topicsResponse['body']);
        $topics = is_array($topicsJson['data'] ?? null) ? $topicsJson['data'] : [];
        if ($topics === []) {
            $this->pass('GET /api/v1/topics/{id}/posts (skipped — no topics)');

            return;
        }

        $topicId = (int) ($topics[0]['id'] ?? 0);
        if ($topicId <= 0) {
            return;
        }

        $postsResponse = $this->request('GET', '/api/v1/topics/' . $topicId . '/posts', bearer: $token);
        if ($postsResponse['status'] !== 200) {
            $this->fail("GET /api/v1/topics/{$topicId}/posts", 'expected HTTP 200, got ' . $postsResponse['status']);

            return;
        }

        $this->pass("GET /api/v1/topics/{$topicId}/posts (bearer)");
    }

    /**
     * @param array<string, string> $form
     * @return array{status: int, body: string}
     */
    private function request(string $method, string $path, array $form = [], ?string $bearer = null): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => 'curl_init failed'];
        }

        $headers = ['Accept: application/json'];
        if ($bearer !== null) {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);

        if ($form !== []) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        }

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => $status,
            'body' => is_string($body) ? $body : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $body): array
    {
        $json = json_decode($body, true);

        return is_array($json) ? $json : [];
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