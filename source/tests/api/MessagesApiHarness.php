<?php

declare(strict_types=1);

/**
 * Interactive harness for messages API + user-delegated OAuth (PKCE).
 *
 * Usage:
 *   php bin/latch test-api-messages authorize   # browser login → save token
 *   php bin/latch test-api-messages run         # smoke tests with saved token
 *   php bin/latch test-api-messages             # authorize (if needed) + run
 */
final class MessagesApiHarness
{
    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private string $tokenCachePath;
    private string $pkceCachePath;
    private ?string $messageRecipient;

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
        $this->clientId = (string) ($config['client_id'] ?? '');
        $this->clientSecret = (string) ($config['client_secret'] ?? '');
        $this->redirectUri = (string) (
            $config['redirect_uri'] ?? 'https://latch.network/oauth/cli-callback'
        );
        $this->tokenCachePath = (string) ($config['token_cache'] ?? dirname(__DIR__) . '/api/user-token.local.json');
        $this->pkceCachePath = (string) ($config['pkce_cache'] ?? dirname(__DIR__) . '/api/pkce.local.json');
        $recipient = trim((string) ($config['message_recipient'] ?? ''));
        $this->messageRecipient = $recipient !== '' ? $recipient : null;
    }

    public function authorizeInteractive(): int
    {
        if ($this->baseUrl === '' || $this->clientId === '' || $this->clientSecret === '') {
            fwrite(STDERR, "Config requires base_url, client_id, and client_secret.\n");
            fwrite(STDERR, "Create a client on the server:\n");
            fwrite(STDERR, "  php bin/latch api-client create --name=\"Local API Harness\" \\\n");
            fwrite(STDERR, "    --redirect=https://latch.network/oauth/cli-callback \\\n");
            fwrite(STDERR, "    --scopes=read,messages:read,messages:write\n");
            fwrite(STDERR, "Then copy tests/api/config.example.php → config.local.php\n");

            return 1;
        }

        $verifier = $this->randomUrlSafe(48);
        $challenge = $this->pkceChallenge($verifier);
        $state = $this->randomUrlSafe(16);

        $params = [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => 'read messages:read messages:write',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];

        $authorizeUrl = $this->baseUrl . '/oauth/authorize?' . http_build_query($params);

        $this->writeJson($this->pkceCachePath, [
            'verifier' => $verifier,
            'state' => $state,
            'redirect_uri' => $this->redirectUri,
            'created_at' => gmdate('c'),
        ]);

        fwrite(STDOUT, "Messages API — user authorization (PKCE)\n\n");

        if ($this->isLocalhostRedirect()) {
            fwrite(STDERR, "WARNING: redirect_uri is {$this->redirectUri}\n");
            fwrite(STDERR, "  Browsers cannot reach localhost after Allow on latch.network.\n");
            fwrite(STDERR, "  Set redirect_uri to https://latch.network/oauth/cli-callback in config.local.php\n\n");
        }

        fwrite(STDOUT, "1. Open this URL in your browser:\n\n");
        fwrite(STDOUT, $authorizeUrl . "\n\n");
        fwrite(STDOUT, "2. If you are not logged in, sign in (and complete 2FA if prompted),\n");
        fwrite(STDOUT, "   then open the SAME URL again from step 1.\n");
        fwrite(STDOUT, "3. Click Allow. You should land on latch.network with \"Authorization complete\"\n");
        fwrite(STDOUT, "   and a code starting with latch_ac_. Copy that code — not the state value.\n\n");
        fwrite(STDOUT, "4. Paste the code (or full callback URL) into this terminal:\n> ");

        $input = trim((string) fgets(STDIN));
        if ($input === '') {
            fwrite(STDERR, "No input.\n");

            return 1;
        }

        $code = $this->parseAuthorizationCode($input);
        if ($code === '') {
            fwrite(STDERR, "Could not parse authorization code from input.\n");

            return 1;
        }

        if (!str_starts_with($code, 'latch_ac_')) {
            $expectedState = (string) ($this->readJson($this->pkceCachePath)['state'] ?? '');
            if ($expectedState !== '' && hash_equals($expectedState, $code)) {
                fwrite(STDERR, "That is the state value from the authorize URL, not the authorization code.\n");
            } else {
                fwrite(STDERR, "Expected a code starting with latch_ac_ from the redirect URL after you click Allow.\n");
            }
            fwrite(STDERR, "Open the authorize link → Allow → copy the latch_ac_ code from:\n");
            fwrite(STDERR, "  {$this->redirectUri}?code=latch_ac_…&state=…\n");
            fwrite(STDERR, "Do not paste the authorize URL or the state= value from step 1.\n");

            return 1;
        }

        $pkce = $this->readJson($this->pkceCachePath);
        if (($pkce['state'] ?? '') !== '' && !str_contains($input, 'state=')) {
            // User pasted code only — still OK.
        } elseif (($pkce['state'] ?? '') !== '' && str_contains($input, 'state=')) {
            parse_str((string) parse_url($input, PHP_URL_QUERY), $query);
            if (($query['state'] ?? '') !== ($pkce['state'] ?? '')) {
                fwrite(STDERR, "State mismatch — this redirect is from a different authorize attempt.\n");
                fwrite(STDERR, "Expected state: " . ($pkce['state'] ?? '') . "\n");
                fwrite(STDERR, "Got state:      " . ($query['state'] ?? '') . "\n");
                fwrite(STDERR, "Run authorize again, open the NEW URL, click Allow, then paste that redirect.\n");
                fwrite(STDERR, "Or paste only the code= value (without state) from the latest redirect.\n");

                return 1;
            }
        }

        $verifier = (string) ($pkce['verifier'] ?? $verifier);
        $tokenResponse = $this->request('POST', '/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
            'code_verifier' => $verifier,
        ]);

        if ($tokenResponse['status'] !== 200) {
            fwrite(STDERR, "Token exchange failed (HTTP {$tokenResponse['status']}):\n");
            fwrite(STDERR, $tokenResponse['body'] . "\n");

            return 1;
        }

        $json = $this->decodeJson($tokenResponse['body']);
        $accessToken = (string) ($json['access_token'] ?? '');
        if ($accessToken === '') {
            fwrite(STDERR, "Token response missing access_token.\n");

            return 1;
        }

        $scopes = (string) ($json['scope'] ?? '');
        fwrite(STDOUT, "\nToken received. Scopes: {$scopes}\n");

        $this->writeJson($this->tokenCachePath, [
            'access_token' => $accessToken,
            'refresh_token' => (string) ($json['refresh_token'] ?? ''),
            'scope' => $scopes,
            'expires_in' => (int) ($json['expires_in'] ?? 0),
            'obtained_at' => gmdate('c'),
        ]);

        fwrite(STDOUT, "Saved to {$this->tokenCachePath}\n");

        return 0;
    }

    public function run(): int
    {
        if ($this->baseUrl === '') {
            $this->fail('config', 'base_url is required');

            return $this->report();
        }

        $this->testClientCredentialsRejectsMessageScopes();

        $token = $this->loadAccessToken();
        if ($token === null) {
            fwrite(STDERR, "No user token. Run: php bin/latch test-api-messages authorize\n");

            return 1;
        }

        $this->testMessagesRequireUserToken();
        $this->testListMessages($token);
        $conversationId = $this->testStartConversation($token);
        if ($conversationId !== null) {
            $this->testSendMessage($token, $conversationId);
            $this->testMarkRead($token, $conversationId);
        }

        return $this->report();
    }

    public function runAll(): int
    {
        if ($this->loadAccessToken() === null) {
            fwrite(STDOUT, "No cached user token — starting authorization…\n\n");
            if ($this->authorizeInteractive() !== 0) {
                return 1;
            }
            fwrite(STDOUT, "\n");
        }

        return $this->run();
    }

    private function testClientCredentialsRejectsMessageScopes(): void
    {
        if ($this->clientId === '' || $this->clientSecret === '') {
            $this->fail('client_credentials scope filter', 'client_id/secret not configured — skipped');

            return;
        }

        $response = $this->request('POST', '/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => 'read messages:read messages:write',
        ]);

        if ($response['status'] !== 200) {
            $this->fail('POST /oauth/token (client_credentials + messages)', 'expected HTTP 200, got ' . $response['status']);

            return;
        }

        $json = $this->decodeJson($response['body']);
        $scope = (string) ($json['scope'] ?? '');
        if (str_contains($scope, 'messages:')) {
            $this->fail('POST /oauth/token (client_credentials + messages)', "scope must not include messages:*, got: {$scope}");

            return;
        }

        $this->pass('POST /oauth/token strips messages:* from client_credentials');
    }

    private function testMessagesRequireUserToken(): void
    {
        $response = $this->request('GET', '/api/v1/messages');
        if ($response['status'] !== 401) {
            $this->fail('GET /api/v1/messages (no token)', 'expected HTTP 401, got ' . $response['status']);

            return;
        }

        $this->pass('GET /api/v1/messages (no token → 401)');

        if ($this->clientId === '' || $this->clientSecret === '') {
            return;
        }

        $machineToken = $this->machineReadToken();
        if ($machineToken === null) {
            return;
        }

        $response = $this->request('GET', '/api/v1/messages', bearer: $machineToken);
        if ($response['status'] !== 401) {
            $this->fail('GET /api/v1/messages (client_credentials)', 'expected HTTP 401, got ' . $response['status']);

            return;
        }

        $this->pass('GET /api/v1/messages (client_credentials → 401)');
    }

    private function testListMessages(string $token): void
    {
        $response = $this->request('GET', '/api/v1/messages', bearer: $token);
        if ($response['status'] !== 200) {
            $this->fail('GET /api/v1/messages', 'expected HTTP 200, got ' . $response['status'] . ' — ' . $response['body']);

            return;
        }

        $json = $this->decodeJson($response['body']);
        if (!is_array($json['data'] ?? null)) {
            $this->fail('GET /api/v1/messages', 'response missing data array');

            return;
        }

        $this->pass('GET /api/v1/messages (user token)');
    }

    private function testStartConversation(string $token): ?int
    {
        if ($this->messageRecipient === null) {
            $this->pass('POST /api/v1/messages (skipped — set message_recipient in config)');

            return null;
        }

        $response = $this->requestJson('POST', '/api/v1/messages', [
            'username' => $this->messageRecipient,
            'body' => 'API harness test message at ' . gmdate('c'),
        ], bearer: $token);

        if ($response['status'] !== 200 && $response['status'] !== 201) {
            $this->fail('POST /api/v1/messages', 'expected HTTP 200/201, got ' . $response['status'] . ' — ' . $response['body']);

            return null;
        }

        $json = $this->decodeJson($response['body']);
        $conversationId = (int) ($json['data']['conversation_id'] ?? 0);
        if ($conversationId <= 0) {
            $this->fail('POST /api/v1/messages', 'missing conversation_id');

            return null;
        }

        $this->pass('POST /api/v1/messages (start + send)');

        return $conversationId;
    }

    private function testSendMessage(string $token, int $conversationId): void
    {
        $response = $this->requestJson(
            'POST',
            '/api/v1/messages/' . $conversationId . '/send',
            ['body' => 'Follow-up from API harness at ' . gmdate('c')],
            bearer: $token,
        );

        if ($response['status'] !== 201) {
            $this->fail('POST /api/v1/messages/{id}/send', 'expected HTTP 201, got ' . $response['status'] . ' — ' . $response['body']);

            return;
        }

        $this->pass('POST /api/v1/messages/{id}/send');
    }

    private function testMarkRead(string $token, int $conversationId): void
    {
        $show = $this->request('GET', '/api/v1/messages/' . $conversationId, bearer: $token);
        if ($show['status'] !== 200) {
            $this->fail('GET /api/v1/messages/{id}', 'expected HTTP 200, got ' . $show['status']);

            return;
        }

        $this->pass('GET /api/v1/messages/{id}');

        $response = $this->requestJson('POST', '/api/v1/messages/' . $conversationId . '/read', [], bearer: $token);
        if ($response['status'] !== 200) {
            $this->fail('POST /api/v1/messages/{id}/read', 'expected HTTP 200, got ' . $response['status']);

            return;
        }

        $this->pass('POST /api/v1/messages/{id}/read');
    }

    private function machineReadToken(): ?string
    {
        $response = $this->request('POST', '/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope' => 'read',
        ]);

        if ($response['status'] !== 200) {
            return null;
        }

        $json = $this->decodeJson($response['body']);

        return (string) ($json['access_token'] ?? '') ?: null;
    }

    private function loadAccessToken(): ?string
    {
        $cache = $this->readJson($this->tokenCachePath);
        $token = (string) ($cache['access_token'] ?? '');

        return $token !== '' ? $token : null;
    }

    private function parseAuthorizationCode(string $input): string
    {
        if (str_contains($input, 'code=')) {
            parse_str((string) parse_url($input, PHP_URL_QUERY), $query);

            return trim((string) ($query['code'] ?? ''));
        }

        return trim($input);
    }

    private function pkceChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function randomUrlSafe(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private function isLocalhostRedirect(): bool
    {
        $host = (string) parse_url($this->redirectUri, PHP_URL_HOST);

        return in_array(strtolower($host), ['127.0.0.1', 'localhost', '::1'], true);
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
            CURLOPT_FOLLOWLOCATION => false,
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
     * @param array<string, mixed> $payload
     * @return array{status: int, body: string}
     */
    private function requestJson(string $method, string $path, array $payload, ?string $bearer = null): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => 'curl_init failed'];
        }

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($bearer !== null) {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $json,
        ]);

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

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $json = json_decode($raw, true);

        return is_array($json) ? $json : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    private function report(): int
    {
        foreach ($this->passed as $line) {
            fwrite(STDOUT, "  OK   {$line}\n");
        }
        foreach ($this->failed as $line) {
            fwrite(STDERR, "  FAIL {$line}\n");
        }

        $total = count($this->passed) + count($this->failed);
        fwrite(STDOUT, "\nMessages API harness: " . count($this->passed) . "/{$total} passed\n");

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