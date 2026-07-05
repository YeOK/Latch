<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Tests;

use Latch\Core\BulkCacheCollector;
use Latch\Core\Cache;
use Latch\Core\Database;
use Latch\Core\ModerationTrashBatch;
use Latch\Core\ModerationTrashService;
use Latch\Models\BoardRepository;
use Latch\Models\PostRepository;
use Latch\Models\SearchRepository;
use Latch\Models\SettingRepository;
use Latch\Models\TopicRepository;
use Latch\Core\Config;
use Latch\Core\InputValidator;
use Latch\Core\PostFormatter;
use Latch\Core\TopicTags;
use Latch\Models\TagRepository;
use PHPUnit\Framework\TestCase;

final class BulkTopicBatchTest extends TestCase
{
    public function testBulkCacheCollectorDedupesTags(): void
    {
        $collector = new BulkCacheCollector();

        $collector->addTopic(['id' => 1, 'board_id' => 2, 'user_id' => 3]);
        $collector->addTopic(['id' => 4, 'board_id' => 2, 'user_id' => 5]);

        $tags = $collector->collectedTags();

        $this->assertContains(Cache::tagSite(), $tags);
        $this->assertContains(Cache::tagBoard(2), $tags);
        $this->assertContains(Cache::tagTopic(1), $tags);
        $this->assertContains(Cache::tagTopic(4), $tags);
        $this->assertSame(1, count(array_filter($tags, static fn (string $tag): bool => $tag === Cache::tagSite())));
    }

    public function testModerationTrashBatchDefersSearchRemovals(): void
    {
        $dbPath = sys_get_temp_dir() . '/latch-trash-batch-' . bin2hex(random_bytes(4)) . '.sqlite';
        $db = new Database($dbPath);
        $db->pdo()->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT NOT NULL,
                email TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT "member",
                created_at TEXT NOT NULL
             );
             CREATE TABLE boards (
                id INTEGER PRIMARY KEY,
                slug TEXT NOT NULL,
                name TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT "",
                sort_order INTEGER NOT NULL DEFAULT 0,
                requires_login_to_read INTEGER NOT NULL DEFAULT 0,
                staff_only_topics INTEGER NOT NULL DEFAULT 0,
                icon_key TEXT NOT NULL DEFAULT "",
                acl_read TEXT NOT NULL DEFAULT "guest",
                acl_topic TEXT NOT NULL DEFAULT "member",
                acl_reply TEXT NOT NULL DEFAULT "member"
             );
             CREATE TABLE topics (
                id INTEGER PRIMARY KEY,
                board_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                title TEXT NOT NULL,
                slug TEXT NOT NULL,
                is_locked INTEGER NOT NULL DEFAULT 0,
                is_pinned INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                last_post_at TEXT NOT NULL,
                deleted_at TEXT,
                UNIQUE (board_id, slug)
             );
             CREATE TABLE posts (
                id INTEGER PRIMARY KEY,
                topic_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                body TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT,
                deleted_at TEXT,
                approval_status TEXT NOT NULL DEFAULT "approved",
                quarantined_at TEXT,
                quarantined_by_report_id INTEGER,
                trashed_at TEXT,
                trashed_by_user_id INTEGER,
                trash_restore_topic_id INTEGER,
                trash_restore_board_id INTEGER
             );
             CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
             CREATE TABLE tags (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                slug TEXT NOT NULL UNIQUE
             );
             CREATE TABLE topic_tags (
                topic_id INTEGER NOT NULL,
                tag_id INTEGER NOT NULL,
                PRIMARY KEY (topic_id, tag_id)
             );
             CREATE VIRTUAL TABLE search_index USING fts5(
                title, body, tags, topic_id UNINDEXED, board_id UNINDEXED, post_id UNINDEXED
             );
             INSERT INTO users (id, username, email, password_hash, created_at) VALUES
                (1, "mod", "mod@test", "hash", "2026-06-30T10:00:00+00:00");
             INSERT INTO boards (id, slug, name) VALUES
                (1, "general", "General"), (2, "mod-trash", "Moderation trash");'
        );

        $config = new Config(dirname(__DIR__) . '/config');
        $topics = new TopicRepository($db, new PostRepository($db, new InputValidator($config)));
        $posts = new PostRepository($db, new InputValidator($config));
        $settings = new SettingRepository($db);
        $settings->set('moderation_trash_board_id', '2');
        $search = new SearchRepository($db, new PostFormatter(), new TagRepository($db, new TopicTags()));

        $topic = $topics->create(1, 1, 'Batch delete', 'Hello');
        $search->indexTopic((int) $topic['id']);
        $this->assertNotEmpty($search->search('Batch', true, false, 1, 10)['results']);

        $batch = new ModerationTrashBatch();
        $trash = new ModerationTrashService(
            $db,
            new BoardRepository($db),
            $topics,
            $posts,
            $settings,
            $search,
        );

        $archived = $trash->archiveTopic((int) $topic['id'], 1, $batch);
        $this->assertSame(1, $archived);
        $this->assertNotEmpty($batch->pendingRemovals());

        $batch->flush($search);
        $this->assertSame([], $search->search('Batch', true, false, 1, 10)['results']);

        @unlink($dbPath);
    }
}