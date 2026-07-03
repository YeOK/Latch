<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Core\MentionParser;
use Latch\Core\NotificationService;
use Latch\Core\PostFormatter;
use Latch\Models\NotificationRepository;
use Latch\Models\PostRepository;
use Latch\Models\UserRepository;
use Latch\Core\Database;
use PHPUnit\Framework\TestCase;

final class MentionParserTest extends TestCase
{
    private MentionParser $parser;

    protected function setUp(): void
    {
        $this->parser = new MentionParser();
    }

    public function testExtractsMentions(): void
    {
        $names = $this->parser->usernames('Hey @dave and @Dave01 — thoughts?');

        $this->assertSame(['dave', 'Dave01'], $names);
    }

    public function testIgnoresEmailAddresses(): void
    {
        $names = $this->parser->usernames('Contact yeok@henpen.org or @yeok');

        $this->assertSame(['yeok'], $names);
    }

    public function testLinkifiesMentionsInFormattedPost(): void
    {
        $html = (new PostFormatter($this->parser))->format('Thanks @dave for the help.');

        $this->assertStringContainsString('href="/user/dave"', $html);
        $this->assertStringContainsString('class="mention"', $html);
        $this->assertStringContainsString('@dave', $html);
    }

    public function testMentionCreatesNotification(): void
    {
        $dbPath = sys_get_temp_dir() . '/latch-mention-' . bin2hex(random_bytes(4)) . '.sqlite';
        $db = new Database($dbPath);
        $pdo = $db->pdo();
        $pdo->exec(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, email TEXT);
             CREATE TABLE user_notifications (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                event_type TEXT NOT NULL,
                message TEXT NOT NULL,
                url TEXT NOT NULL,
                actor_id INTEGER,
                topic_id INTEGER,
                post_id INTEGER,
                meta_json TEXT,
                created_at TEXT NOT NULL,
                read_at TEXT
             );'
        );
        $pdo->exec(
            "INSERT INTO users (id, username, email) VALUES
                (1, 'alice', 'a@test'),
                (2, 'bob', 'b@test');"
        );

        $users = new UserRepository($db);
        $notifications = new NotificationRepository($db);
        $service = new NotificationService($notifications, $users, null, $this->parser);

        $topic = ['id' => 5, 'user_id' => 1, 'title' => 'Plans'];
        $post = [
            'id' => 9,
            'body' => '@bob can you review this?',
            'approval_status' => PostRepository::APPROVAL_APPROVED,
        ];
        $actor = ['id' => 1, 'username' => 'alice'];

        $service->onReply($topic, $post, $actor);

        $this->assertSame(0, $notifications->countUnread(1));
        $this->assertSame(1, $notifications->countUnread(2));
        $item = $notifications->listForUser(2)[0];
        $this->assertSame(NotificationRepository::TYPE_MENTION, $item['event_type']);

        @unlink($dbPath);
    }
}