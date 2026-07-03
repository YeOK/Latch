<?php

declare(strict_types=1);

namespace Latch\Core\Oidc;

/**
 * Minimal HTTP client for OIDC token and profile endpoints.
 */
final class OidcHttpClient
{
    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}|null
     */
    public function postForm(string $url, array $fields, array $headers = []): ?array
    {
        $headerLines = array_merge(
            ['Content-Type: application/x-www-form-urlencoded'],
            $headers,
        );

        return $this->request('POST', $url, http_build_query($fields), $headerLines);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}|null
     */
    public function get(string $url, array $headers = []): ?array
    {
        return $this->request('GET', $url, null, $headers);
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: string}|null
     */
    private function request(string $method, string $url, ?string $body, array $headers): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $body ?? '',
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            return null;
        }

        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/\d{3}/', $http_response_header[0], $match)) {
            $status = (int) $match[0];
        }

        return ['status' => $status, 'body' => $raw];
    }
}