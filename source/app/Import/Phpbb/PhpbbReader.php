<?php

declare(strict_types=1);

namespace Latch\Import\Phpbb;

use RuntimeException;

/**
 * Load phpBB data from a JSON bundle or export from MySQL.
 */
final class PhpbbReader
{
    public function loadBundleFile(string $path): PhpbbBundle
    {
        if (!is_file($path)) {
            throw new RuntimeException("Bundle not found: {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Could not read bundle: {$path}");
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Bundle must be a JSON object.');
        }

        return $this->parseBundle($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function parseBundle(array $data): PhpbbBundle
    {
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $forums = $this->requireList($data, 'forums');
        $users = $this->requireList($data, 'users');
        $topics = $this->requireList($data, 'topics');
        $posts = $this->requireList($data, 'posts');

        if (($meta['source'] ?? '') !== 'phpbb') {
            $meta['source'] = 'phpbb';
        }

        return new PhpbbBundle($meta, $forums, $users, $topics, $posts);
    }

    public function exportToFile(string $dsn, string $outPath, string $tablePrefix = 'phpbb_'): void
    {
        $pdo = $this->connectMysql($dsn);
        $bundle = $this->exportFromPdo($pdo, $tablePrefix);
        $json = json_encode([
            'meta' => $bundle->meta,
            'forums' => $bundle->forums,
            'users' => $bundle->users,
            'topics' => $bundle->topics,
            'posts' => $bundle->posts,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new RuntimeException('Failed to encode export bundle.');
        }

        if (file_put_contents($outPath, $json . "\n") === false) {
            throw new RuntimeException("Could not write bundle: {$outPath}");
        }
    }

    public function exportFromPdo(\PDO $pdo, string $prefix = 'phpbb_'): PhpbbBundle
    {
        $forums = $this->fetchForums($pdo, $prefix);
        $users = $this->fetchUsers($pdo, $prefix);
        $topics = $this->fetchTopics($pdo, $prefix);
        $posts = $this->fetchPosts($pdo, $prefix);

        return new PhpbbBundle(
            [
                'source' => 'phpbb',
                'version' => '3.3',
                'exported_at' => gmdate('c'),
                'table_prefix' => $prefix,
            ],
            $forums,
            $users,
            $topics,
            $posts,
        );
    }

    private function connectMysql(string $dsn): \PDO
    {
        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('Export requires php-pdo_mysql (pdo_mysql extension).');
        }

        $parsed = $this->parseMysqlDsn($dsn);
        $pdoDsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $parsed['host'],
            $parsed['port'],
            $parsed['database'],
        );

        return new \PDO($pdoDsn, $parsed['user'], $parsed['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * @return array{host: string, port: int, database: string, user: string, password: string}
     */
    private function parseMysqlDsn(string $dsn): array
    {
        if (!str_starts_with($dsn, 'mysqli://')) {
            throw new RuntimeException("MySQL DSN must start with mysqli:// (got: {$dsn})");
        }

        $parts = parse_url($dsn);
        if ($parts === false || !isset($parts['host'], $parts['path'])) {
            throw new RuntimeException('Invalid mysqli:// DSN.');
        }

        $database = ltrim((string) $parts['path'], '/');
        if ($database === '') {
            throw new RuntimeException('MySQL DSN must include database name in path.');
        }

        return [
            'host' => (string) $parts['host'],
            'port' => (int) ($parts['port'] ?? 3306),
            'database' => $database,
            'user' => rawurldecode((string) ($parts['user'] ?? '')),
            'password' => rawurldecode((string) ($parts['pass'] ?? '')),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchForums(\PDO $pdo, string $prefix): array
    {
        $sql = "SELECT forum_id AS id, forum_name AS title, forum_desc AS description, left_id AS sort_order
                FROM {$prefix}forums
                WHERE forum_type IN (1, 0)
                ORDER BY left_id ASC";

        return $pdo->query($sql)->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchUsers(\PDO $pdo, string $prefix): array
    {
        $adminIds = $this->adminUserIds($pdo, $prefix);
        $modIds = $this->moderatorUserIds($pdo, $prefix);

        $sql = "SELECT user_id AS id, username, user_email AS email, user_type, user_regdate AS registered_at
                FROM {$prefix}users
                WHERE user_type IN (0, 3)
                ORDER BY user_id ASC";

        $rows = $pdo->query($sql)->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if ($id <= 1) {
                continue;
            }

            $role = 'member';
            if (in_array($id, $adminIds, true) || (int) ($row['user_type'] ?? 0) === 3) {
                $role = 'admin';
            } elseif (in_array($id, $modIds, true)) {
                $role = 'mod';
            }

            $out[] = [
                'id' => $id,
                'username' => (string) $row['username'],
                'email' => (string) $row['email'],
                'role' => $role,
                'user_type' => (int) ($row['user_type'] ?? 0),
                'registered_at' => gmdate('c', (int) ($row['registered_at'] ?? time())),
            ];
        }

        return $out;
    }

    /** @return list<int> */
    private function adminUserIds(\PDO $pdo, string $prefix): array
    {
        $sql = "SELECT u.user_id
                FROM {$prefix}users u
                INNER JOIN {$prefix}user_group ug ON ug.user_id = u.user_id AND ug.group_leader = 1
                INNER JOIN {$prefix}groups g ON g.group_id = ug.group_id
                WHERE g.group_name = 'ADMINISTRATORS'";

        return array_map('intval', $pdo->query($sql)->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<int> */
    private function moderatorUserIds(\PDO $pdo, string $prefix): array
    {
        $sql = "SELECT u.user_id
                FROM {$prefix}users u
                INNER JOIN {$prefix}user_group ug ON ug.user_id = u.user_id
                INNER JOIN {$prefix}groups g ON g.group_id = ug.group_id
                WHERE g.group_name = 'GLOBAL_MODERATORS'";

        return array_map('intval', $pdo->query($sql)->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchTopics(\PDO $pdo, string $prefix): array
    {
        $sql = "SELECT topic_id AS id, forum_id, topic_title AS title, topic_poster AS user_id,
                       topic_time AS created_at, topic_last_post_time AS last_post_at,
                       topic_status AS status, topic_type AS type
                FROM {$prefix}topics
                ORDER BY topic_id ASC";

        $rows = $pdo->query($sql)->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'forum_id' => (int) $row['forum_id'],
                'user_id' => (int) $row['user_id'],
                'title' => (string) $row['title'],
                'created_at' => gmdate('c', (int) $row['created_at']),
                'last_post_at' => gmdate('c', (int) $row['last_post_at']),
                'is_locked' => ((int) ($row['status'] ?? 0) & 1) === 1 ? 1 : 0,
                'is_pinned' => in_array((int) ($row['type'] ?? 0), [1, 3], true) ? 1 : 0,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchPosts(\PDO $pdo, string $prefix): array
    {
        $sql = "SELECT post_id AS id, topic_id, forum_id, poster_id AS user_id,
                       post_time AS created_at, post_text AS body_bbcode, post_subject AS subject
                FROM {$prefix}posts
                ORDER BY post_id ASC";

        $rows = $pdo->query($sql)->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'topic_id' => (int) $row['topic_id'],
                'forum_id' => (int) $row['forum_id'],
                'user_id' => (int) $row['user_id'],
                'created_at' => gmdate('c', (int) $row['created_at']),
                'body_bbcode' => (string) $row['body_bbcode'],
                'subject' => (string) ($row['subject'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private function requireList(array $data, string $key): array
    {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            throw new RuntimeException("Bundle missing array key: {$key}");
        }

        return array_values(array_map(
            static fn (mixed $row): array => is_array($row) ? $row : [],
            $data[$key],
        ));
    }
}