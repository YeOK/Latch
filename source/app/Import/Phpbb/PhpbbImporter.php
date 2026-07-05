<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Import\Phpbb;

use Latch\Core\Database;
use Latch\Support\Str;
use RuntimeException;

/**
 * Import a phpBB bundle into Latch SQLite.
 */
final class PhpbbImporter
{
    private const SOURCE = 'phpbb';

    /** @var array<int, int> */
    private array $userMap = [];

    /** @var array<int, int> */
    private array $boardMap = [];

    /** @var array<int, int> */
    private array $topicMap = [];

    public function __construct(
        private readonly Database $db,
        private readonly BbcodeConverter $converter,
    ) {
    }

    public function dryRun(PhpbbBundle $bundle): ImportReport
    {
        return $this->import($bundle, write: false);
    }

    public function confirm(PhpbbBundle $bundle): ImportReport
    {
        return $this->import($bundle, write: true);
    }

    private function import(PhpbbBundle $bundle, bool $write): ImportReport
    {
        $report = new ImportReport();
        $this->userMap = [];
        $this->boardMap = [];
        $this->topicMap = [];

        try {
            $this->preflight($bundle, $report, $write);
        } catch (RuntimeException $e) {
            $report->addError($e->getMessage());

            return $report;
        }

        $postsByTopic = $this->groupPostsByTopic($bundle->posts);
        $bbcodeWarnings = 0;

        foreach ($bundle->users as $row) {
            if ($this->shouldSkipUser($row)) {
                $report->increment('skipped_users');
                continue;
            }
            $report->increment('users');
            if ($write) {
                $this->importUser($row, $report);
            }
        }

        foreach ($bundle->forums as $row) {
            $report->increment('boards');
            if ($write) {
                $this->importBoard($row, $bundle->meta);
            }
        }

        foreach ($bundle->topics as $row) {
            $report->increment('topics');
            $topicId = (int) ($row['id'] ?? 0);
            $topicPosts = $postsByTopic[$topicId] ?? [];
            $report->increment('posts', max(1, count($topicPosts)));

            if (!$write) {
                foreach ($topicPosts as $post) {
                    $this->converter->convert((string) ($post['body_bbcode'] ?? ''));
                    $bbcodeWarnings += count($this->converter->warnings());
                }
                continue;
            }

            $this->importTopic($row, $topicPosts, $report);
        }

        $report->setCount('bbcode_warnings', $bbcodeWarnings);

        if ($write) {
            if ($report->count('users') > 0) {
                $report->addWarning(
                    'Imported users need password reset — phpBB phpass hashes are not ported.',
                );
            }
            $this->db->pdo()->exec('PRAGMA foreign_key_check');
        }

        return $report;
    }

    private function preflight(PhpbbBundle $bundle, ImportReport $report, bool $write): void
    {
        if ($bundle->forums === []) {
            throw new RuntimeException('Bundle has no forums.');
        }

        if ($bundle->topics === []) {
            throw new RuntimeException('Bundle has no topics.');
        }

        $pdo = $this->db->pdo();
        $topicCount = (int) $pdo->query('SELECT COUNT(*) FROM topics')->fetchColumn();
        $postCount = (int) $pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();

        if ($topicCount > 0 || $postCount > 0) {
            throw new RuntimeException(
                'Import requires an empty forum (no topics/posts). v1 does not support --merge.',
            );
        }

        if ($write && !$this->importMapTableExists()) {
            throw new RuntimeException('import_map table missing — run php bin/latch migrate first.');
        }
    }

    private function importMapTableExists(): bool
    {
        $row = $this->db->pdo()->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'import_map'",
        )->fetch();

        return is_array($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function shouldSkipUser(array $row): bool
    {
        $userType = (int) ($row['user_type'] ?? 0);
        if ($userType === 2) {
            return true;
        }

        $username = trim((string) ($row['username'] ?? ''));
        if ($username === '' || str_starts_with(strtolower($username), 'bot')) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function importUser(array $row, ImportReport $report): void
    {
        $sourceId = (int) ($row['id'] ?? 0);
        $username = trim((string) ($row['username'] ?? ''));
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        $role = (string) ($row['role'] ?? 'member');
        if (!in_array($role, ['admin', 'mod', 'member'], true)) {
            $role = 'member';
        }

        $createdAt = (string) ($row['registered_at'] ?? $row['created_at'] ?? gmdate('c'));

        $username = $this->uniqueUsername($username);
        $email = $this->uniqueEmail($email, $username);

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO users (
                username, email, password_hash, role, theme_mode, created_at, email_verified_at
             ) VALUES (
                :username, :email, :password_hash, :role, :theme_mode, :created_at, :email_verified_at
             )',
        );
        $stmt->execute([
            'username' => $username,
            'email' => $email,
            'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            'role' => $role,
            'theme_mode' => 'system',
            'created_at' => $createdAt,
            'email_verified_at' => $createdAt,
        ]);

        $targetId = (int) $this->db->pdo()->lastInsertId();
        $this->userMap[$sourceId] = $targetId;
        $this->recordMap('user', $sourceId, $targetId);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $meta
     */
    private function importBoard(array $row, array $meta): void
    {
        $sourceId = (int) ($row['id'] ?? 0);
        $title = trim((string) ($row['title'] ?? $row['name'] ?? 'Forum'));
        $description = trim((string) ($row['description'] ?? ''));

        $slugMap = is_array($meta['slug_map'] ?? null) ? $meta['slug_map'] : [];
        $slugKey = (string) $sourceId;
        $slug = isset($slugMap[$slugKey]) ? Str::slug((string) $slugMap[$slugKey]) : null;
        if ($slug === null || $slug === '') {
            $slug = Str::uniqueSlug($title, fn (string $s): bool => $this->boardSlugExists($s));
        }

        $sortOrder = (int) ($row['sort_order'] ?? 0);

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO boards (slug, name, description, sort_order, requires_login_to_read)
             VALUES (:slug, :name, :description, :sort_order, 0)',
        );
        $stmt->execute([
            'slug' => $slug,
            'name' => $title,
            'description' => $description,
            'sort_order' => $sortOrder,
        ]);

        $targetId = (int) $this->db->pdo()->lastInsertId();
        $this->boardMap[$sourceId] = $targetId;
        $this->recordMap('board', $sourceId, $targetId);
    }

    /**
     * @param array<string, mixed> $topicRow
     * @param list<array<string, mixed>> $posts
     */
    private function importTopic(array $topicRow, array $posts, ImportReport $report): void
    {
        $sourceTopicId = (int) ($topicRow['id'] ?? 0);
        $forumId = $this->boardMap[(int) ($topicRow['forum_id'] ?? 0)] ?? 0;
        if ($forumId <= 0) {
            $report->addWarning("Skipped topic {$sourceTopicId} — unknown forum_id.");

            return;
        }

        $userId = $this->resolveUserId((int) ($topicRow['user_id'] ?? 0));
        $title = trim((string) ($topicRow['title'] ?? 'Topic'));
        $createdAt = (string) ($topicRow['created_at'] ?? gmdate('c'));
        $lastPostAt = (string) ($topicRow['last_post_at'] ?? $createdAt);
        $isLocked = (int) ($topicRow['is_locked'] ?? 0) === 1 ? 1 : 0;
        $isPinned = (int) ($topicRow['is_pinned'] ?? 0) === 1 ? 1 : 0;

        if ($posts === []) {
            $posts = [[
                'body_bbcode' => '',
                'user_id' => $topicRow['user_id'] ?? 0,
                'created_at' => $createdAt,
            ]];
        }

        usort($posts, static fn (array $a, array $b): int => ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0)));

        $first = $posts[0];
        $firstBody = $this->convertBody((string) ($first['body_bbcode'] ?? ''), $report);
        $firstUserId = $this->resolveUserId((int) ($first['user_id'] ?? $userId));
        $firstCreated = (string) ($first['created_at'] ?? $createdAt);

        $slug = Str::uniqueSlug($title, fn (string $s): bool => $this->topicSlugExists($forumId, $s));

        $this->db->begin();
        try {
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO topics (
                    board_id, user_id, title, slug, is_locked, is_pinned, created_at, last_post_at
                 ) VALUES (
                    :board_id, :user_id, :title, :slug, :is_locked, :is_pinned, :created_at, :last_post_at
                 )',
            );
            $stmt->execute([
                'board_id' => $forumId,
                'user_id' => $firstUserId,
                'title' => $title,
                'slug' => $slug,
                'is_locked' => $isLocked,
                'is_pinned' => $isPinned,
                'created_at' => $firstCreated,
                'last_post_at' => $lastPostAt,
            ]);

            $targetTopicId = (int) $this->db->pdo()->lastInsertId();
            $this->topicMap[$sourceTopicId] = $targetTopicId;
            $this->recordMap('topic', $sourceTopicId, $targetTopicId);

            $this->insertPost($targetTopicId, $firstUserId, $firstBody, $firstCreated);
            $this->recordMap('post', (int) ($first['id'] ?? 0), (int) $this->db->pdo()->lastInsertId());

            for ($i = 1, $n = count($posts); $i < $n; $i++) {
                $post = $posts[$i];
                $body = $this->convertBody((string) ($post['body_bbcode'] ?? ''), $report);
                $postUserId = $this->resolveUserId((int) ($post['user_id'] ?? $userId));
                $postCreated = (string) ($post['created_at'] ?? $lastPostAt);
                $this->insertPost($targetTopicId, $postUserId, $body, $postCreated);
                $this->recordMap('post', (int) ($post['id'] ?? 0), (int) $this->db->pdo()->lastInsertId());
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function insertPost(int $topicId, int $userId, string $body, string $createdAt): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO posts (topic_id, user_id, body, created_at, approval_status)
             VALUES (:topic_id, :user_id, :body, :created_at, :approval_status)',
        );
        $stmt->execute([
            'topic_id' => $topicId,
            'user_id' => $userId,
            'body' => $body !== '' ? $body : '(empty post)',
            'created_at' => $createdAt,
            'approval_status' => 'approved',
        ]);
    }

    private function convertBody(string $bbcode, ImportReport $report): string
    {
        $body = $this->converter->convert($bbcode);
        foreach ($this->converter->warnings() as $warning) {
            $report->addWarning($warning);
            $report->increment('bbcode_warnings');
        }

        return $body;
    }

    private function resolveUserId(int $sourceUserId): int
    {
        if ($sourceUserId <= 1) {
            return $this->fallbackUserId();
        }

        return $this->userMap[$sourceUserId] ?? $this->fallbackUserId();
    }

    private function fallbackUserId(): int
    {
        if ($this->userMap !== []) {
            return (int) reset($this->userMap);
        }

        $row = $this->db->pdo()->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetch();
        if (is_array($row)) {
            return (int) $row['id'];
        }

        throw new RuntimeException('No users available to attribute imported content.');
    }

    private function recordMap(string $entity, int $sourceId, int $targetId): void
    {
        if ($sourceId <= 0 || $targetId <= 0) {
            return;
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT OR IGNORE INTO import_map (source, entity, source_id, target_id, created_at)
             VALUES (:source, :entity, :source_id, :target_id, :created_at)',
        );
        $stmt->execute([
            'source' => self::SOURCE,
            'entity' => $entity,
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'created_at' => gmdate('c'),
        ]);
    }

    private function uniqueUsername(string $username): string
    {
        $base = $username;
        $candidate = $base;
        $suffix = 2;

        while ($this->usernameExists($candidate)) {
            $candidate = $base . '_' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function uniqueEmail(string $email, string $username): string
    {
        if ($email === '' || !str_contains($email, '@')) {
            $email = Str::slug($username) . '@imported.local';
        }

        $base = $email;
        $candidate = $base;
        $suffix = 2;

        while ($this->emailExists($candidate)) {
            [$local, $domain] = explode('@', $base, 2);
            $candidate = $local . '+' . $suffix . '@' . $domain;
            $suffix++;
        }

        return $candidate;
    }

    private function usernameExists(string $username): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM users WHERE username = :u COLLATE NOCASE LIMIT 1');
        $stmt->execute(['u' => $username]);

        return (bool) $stmt->fetchColumn();
    }

    private function emailExists(string $email): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM users WHERE email = :e COLLATE NOCASE LIMIT 1');
        $stmt->execute(['e' => $email]);

        return (bool) $stmt->fetchColumn();
    }

    private function boardSlugExists(string $slug): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM boards WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);

        return (bool) $stmt->fetchColumn();
    }

    private function topicSlugExists(int $boardId, string $slug): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT 1 FROM topics WHERE board_id = :board_id AND slug = :slug LIMIT 1',
        );
        $stmt->execute(['board_id' => $boardId, 'slug' => $slug]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param list<array<string, mixed>> $posts
     * @return array<int, list<array<string, mixed>>>
     */
    private function groupPostsByTopic(array $posts): array
    {
        $grouped = [];
        foreach ($posts as $post) {
            $topicId = (int) ($post['topic_id'] ?? 0);
            $grouped[$topicId][] = $post;
        }

        return $grouped;
    }
}