<?php

declare(strict_types=1);

/**
 * Minimal HTTP client for live smoke/security harnesses (curl + cookie jar).
 */
final class HttpClient
{
    private string $cookieJar;

    public function __construct(private readonly string $baseUrl)
    {
        $this->cookieJar = sys_get_temp_dir() . '/latch-http-' . bin2hex(random_bytes(6)) . '.cookies';
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function cleanup(): void
    {
        if (is_file($this->cookieJar)) {
            @unlink($this->cookieJar);
        }
    }

    /**
     * @param array<string, string> $form
     * @return array{status: int, body: string, headers: array<string, string>, effective_url: string}
     */
    public function request(
        string $method,
        string $path,
        array $form = [],
        bool $followRedirects = false,
        array $extraHeaders = [],
    ): array {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'status' => 0,
                'body' => 'curl_init failed',
                'headers' => [],
                'effective_url' => $url,
            ];
        }

        $headers = array_merge(['Accept: text/html,application/json;q=0.9,*/*;q=0.8'], $extraHeaders);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_MAXREDIRS => $followRedirects ? 5 : 0,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
        ]);

        if ($form !== []) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if (!is_string($raw)) {
            return [
                'status' => $status,
                'body' => '',
                'headers' => [],
                'effective_url' => $effectiveUrl,
            ];
        }

        $headerBlob = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);

        return [
            'status' => $status,
            'body' => $body,
            'headers' => self::parseHeaders($headerBlob),
            'effective_url' => $effectiveUrl,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function parseHeaders(string $blob): array
    {
        $headers = [];
        foreach (preg_split("/\r\n|\n|\r/", $blob) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return $headers;
    }

    public static function extractCsrfToken(string $html): ?string
    {
        if (preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}