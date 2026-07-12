<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Support\Logs;

use Latch\Core\Config;

final class LogViewer
{
    private const MAX_LINES = 500;

    public function __construct(
        private readonly LogSourceRegistry $registry,
        private readonly LogFileReader $reader,
        private readonly LogLineRedactor $redactor,
        private readonly SecurityLogParser $securityParser,
        private readonly int $defaultLimit,
        /** @var list<string> */
        private readonly array $securityEventTypes,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        $logs = $config->get('logs', []);
        if (!is_array($logs)) {
            $logs = [];
        }

        return new self(
            LogSourceRegistry::fromConfig($config),
            new LogFileReader(
                (int) ($logs['tail_window_bytes'] ?? 2_097_152),
                (int) ($logs['search_scan_bytes'] ?? 524_288),
            ),
            new LogLineRedactor((bool) ($logs['mask_emails'] ?? false)),
            new SecurityLogParser(),
            max(1, (int) ($logs['max_lines_per_request'] ?? 200)),
            self::securityEventTypesFromConfig($logs),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSources(): array
    {
        return $this->registry->listSources();
    }

    /**
     * @param array<string, mixed>|list<string> $input
     * @return array{
     *   source: string,
     *   limit: int,
     *   cursor: ?int,
     *   fingerprint: ?array{size: int, mtime: int},
     *   filters: array{event?: string, ip?: string, username?: string, since?: string, until?: string, q?: string}
     * }
     */
    public static function parseFilters(array $input, LogSourceRegistry $registry, int $defaultLimit, array $securityEventTypes): array
    {
        $params = self::normalizeInput($input);

        $source = trim((string) ($params['source'] ?? ''));
        if ($source === '') {
            throw new LogViewerException('Log source is required.');
        }

        if ($registry->getSource($source) === null) {
            throw new LogViewerException('Unknown log source.');
        }

        $limit = $defaultLimit;
        if (isset($params['limit'])) {
            $limit = (int) $params['limit'];
        } elseif (isset($params['lines'])) {
            $limit = (int) $params['lines'];
        }
        $limit = max(1, min(self::MAX_LINES, $limit));

        $cursor = null;
        if (isset($params['cursor']) && $params['cursor'] !== '') {
            $cursor = (int) $params['cursor'];
            if ($cursor < 0) {
                throw new LogViewerException('Invalid cursor.');
            }
        }

        $fingerprint = null;
        $fpSize = $params['fp_size'] ?? null;
        $fpMtime = $params['fp_mtime'] ?? null;
        if ($fpSize !== null || $fpMtime !== null) {
            if ($fpSize === null || $fpMtime === null) {
                throw new LogViewerException('Fingerprint requires both fp_size and fp_mtime.');
            }
            $fingerprint = [
                'size' => (int) $fpSize,
                'mtime' => (int) $fpMtime,
            ];
        }

        $filters = [];
        if (isset($params['event']) && trim((string) $params['event']) !== '') {
            $event = trim((string) $params['event']);
            if (!in_array($event, $securityEventTypes, true)) {
                throw new LogViewerException('Unknown security event type.');
            }
            $filters['event'] = $event;
        }

        if (isset($params['ip']) && trim((string) $params['ip']) !== '') {
            $ip = trim((string) $params['ip']);
            if (strlen($ip) > 45) {
                throw new LogViewerException('IP filter is too long.');
            }
            $filters['ip'] = $ip;
        }

        if (isset($params['username']) && trim((string) $params['username']) !== '') {
            $username = trim((string) $params['username']);
            if (strlen($username) > 32) {
                throw new LogViewerException('Username filter is too long.');
            }
            $filters['username'] = $username;
        }

        if (isset($params['since']) && trim((string) $params['since']) !== '') {
            $since = trim((string) $params['since']);
            if (strtotime($since) === false) {
                throw new LogViewerException('Invalid since timestamp.');
            }
            $filters['since'] = $since;
        }

        if (isset($params['until']) && trim((string) $params['until']) !== '') {
            $until = trim((string) $params['until']);
            if (strtotime($until) === false) {
                throw new LogViewerException('Invalid until timestamp.');
            }
            $filters['until'] = $until;
        }

        if (isset($filters['since'], $filters['until']) && strtotime($filters['since']) > strtotime($filters['until'])) {
            throw new LogViewerException('since must be before until.');
        }

        if (isset($params['q']) && trim((string) $params['q']) !== '') {
            $q = trim((string) $params['q']);
            if (strlen($q) > 200) {
                throw new LogViewerException('Search query is too long.');
            }
            $filters['q'] = $q;
        }

        return [
            'source' => $source,
            'limit' => $limit,
            'cursor' => $cursor,
            'fingerprint' => $fingerprint,
            'filters' => $filters,
        ];
    }

    /**
     * @param array{event?: string, ip?: string, username?: string, since?: string, until?: string, q?: string} $filters
     * @return array{
     *   source: array<string, mixed>,
     *   lines: list<string>,
     *   parsed: list<array<string, mixed>|null>,
     *   next_cursor: ?int,
     *   fingerprint: array{size: int, mtime: int},
     *   rotated: bool,
     *   scan_budget_exhausted: bool,
     *   matches_exhausted: bool,
     *   bytes_scanned: int
     * }
     */
    public function tail(
        string $sourceId,
        int $limit,
        ?int $cursor,
        ?array $fingerprint,
        array $filters = [],
    ): array {
        $source = $this->registry->getSource($sourceId);
        if ($source === null) {
            throw new LogViewerException('Unknown log source.');
        }

        if ($source['status'] === 'denied' || $source['status'] === 'permission_denied') {
            throw new LogViewerException('Log source is not readable.');
        }

        if ($source['status'] === 'missing') {
            return [
                'source' => $source,
                'lines' => [],
                'parsed' => [],
                'next_cursor' => null,
                'fingerprint' => ['size' => 0, 'mtime' => 0],
                'rotated' => false,
                'scan_budget_exhausted' => false,
                'matches_exhausted' => false,
                'bytes_scanned' => 0,
            ];
        }

        $path = (string) $source['path'];
        $matcher = $this->buildMatcher($source, $filters);

        $result = $this->reader->tail($path, $limit, $cursor, $fingerprint, $matcher);

        $lines = [];
        $parsed = [];
        foreach ($result['lines'] as $line) {
            $redacted = $this->redactor->redact($line);
            $lines[] = $redacted;
            if ($source['format'] === 'json_lines') {
                $parsed[] = $this->securityParser->parseLine($redacted);
            }
        }

        return [
            'source' => $source,
            'lines' => $lines,
            'parsed' => $parsed,
            'next_cursor' => $result['next_cursor'],
            'fingerprint' => $result['fingerprint'],
            'rotated' => $result['rotated'],
            'scan_budget_exhausted' => $result['scan_budget_exhausted'],
            'matches_exhausted' => $result['matches_exhausted'],
            'bytes_scanned' => $result['bytes_scanned'],
        ];
    }

    /** @return list<string> */
    public function securityEventTypes(): array
    {
        return $this->securityEventTypes;
    }

    public function registry(): LogSourceRegistry
    {
        return $this->registry;
    }

    /**
     * Apply tail filters and redaction for a single line (CLI follow mode).
     *
     * @param array{event?: string, ip?: string, username?: string, since?: string, until?: string, q?: string} $filters
     */
    public function formatCliLine(string $sourceId, string $line, array $filters = []): ?string
    {
        $source = $this->registry->getSource($sourceId);
        if ($source === null) {
            return null;
        }

        if ($filters !== []) {
            $matcher = $this->buildMatcher($source, $filters);
            if ($matcher !== null && !$matcher($line)) {
                return null;
            }
        }

        return $this->redactor->redact($line);
    }

    /**
     * @param array<string, mixed>|list<string> $input
     * @return array{
     *   source: string,
     *   limit: int,
     *   cursor: ?int,
     *   fingerprint: ?array{size: int, mtime: int},
     *   filters: array{event?: string, ip?: string, username?: string, since?: string, until?: string, q?: string}
     * }
     */
    public function parseRequestFilters(array $input): array
    {
        return self::parseFilters($input, $this->registry, $this->defaultLimit, $this->securityEventTypes);
    }

    /**
     * @param array<string, mixed> $logsConfig
     * @return list<string>
     */
    private static function securityEventTypesFromConfig(array $logsConfig): array
    {
        $types = $logsConfig['security_event_types'] ?? [];

        return is_array($types) ? array_values(array_map('strval', $types)) : [];
    }

    /**
     * @param array<string, mixed>|list<string> $input
     * @return array<string, string>
     */
    private static function normalizeInput(array $input): array
    {
        if ($input === [] || array_is_list($input)) {
            $params = [];
            foreach ($input as $arg) {
                if (!is_string($arg) || !str_starts_with($arg, '--')) {
                    continue;
                }
                $body = substr($arg, 2);
                $eq = strpos($body, '=');
                if ($eq === false) {
                    $params[$body] = '1';
                    continue;
                }
                $params[substr($body, 0, $eq)] = substr($body, $eq + 1);
            }

            return $params;
        }

        $params = [];
        foreach ($input as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $params[$key] = (string) $value;
            }
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $source
     * @param array{event?: string, ip?: string, username?: string, since?: string, until?: string, q?: string} $filters
     */
    private function buildMatcher(array $source, array $filters): ?callable
    {
        if ($filters === []) {
            return null;
        }

        if ($source['format'] === 'json_lines') {
            $securityFilters = array_intersect_key($filters, array_flip(['event', 'ip', 'username', 'since', 'until']));
            if ($securityFilters === []) {
                return null;
            }

            $parser = $this->securityParser;

            return static function (string $line) use ($securityFilters, $parser): bool {
                return $parser->matchesLine($line, $securityFilters);
            };
        }

        if (!isset($filters['q'])) {
            return null;
        }

        $needle = strtolower($filters['q']);

        return static function (string $line) use ($needle): bool {
            return str_contains(strtolower($line), $needle);
        };
    }
}