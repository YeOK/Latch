<?php

declare(strict_types=1);

namespace Latch\Tests;

use Latch\Core\Config;
use Latch\Core\Database;
use Latch\Core\EmailNotificationService;
use Latch\Core\Mail;
use Latch\Core\NotificationService;
use Latch\Core\ThemeMode;
use Latch\Models\NotificationRepository;
use Latch\Models\SettingRepository;
use Latch\Models\UserRepository;
use PHPUnit\Framework\TestCase;

final class Phase2OptionalTest extends TestCase
{
    private string $dbPath;
    private Database $db;
    private UserRepository $users;
    private NotificationRepository $notifications;
    private NotificationService $notificationService;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/latch-p2opt-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database($this->dbPath);
        $pdo = $this->db->pdo();
        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                username TEXT,
                email TEXT,
                banned_at TEXT,
                banned_until TEXT,
                ban_reason TEXT,
                failed_login_count INTEGER NOT NULL DEFAULT 0,
                locked_until TEXT,
                notify_email INTEGER NOT NULL DEFAULT 1,
                theme_mode TEXT
             );
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
             );
             CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);'
        );
        $pdo->exec(
            "INSERT INTO users (id, username, email, notify_email) VALUES
                (1, 'staff', 'staff@test', 1),
                (2, 'member', 'member@test', 1);"
        );

        $this->users = new UserRepository($this->db);
        $this->notifications = new NotificationRepository($this->db);
        $this->notificationService = new NotificationService($this->notifications, $this->users);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
    }

    public function testBanStoresReason(): void
    {
        $this->users->ban(2, null, 'Repeated spam');
        $user = $this->users->findById(2);

        $this->assertNotNull($user['banned_at']);
        $this->assertSame('Repeated spam', $user['ban_reason']);
    }

    public function testUnbanClearsReason(): void
    {
        $this->users->ban(2, null, 'Test');
        $this->users->unban(2);
        $user = $this->users->findById(2);

        $this->assertNull($user['banned_at']);
        $this->assertNull($user['ban_reason']);
    }

    public function testNotifyEmailPreference(): void
    {
        $user = $this->users->findById(2);
        $this->assertTrue($this->users->wantsEmailNotifications($user));

        $this->users->updateNotifyEmail(2, false);
        $user = $this->users->findById(2);
        $this->assertFalse($this->users->wantsEmailNotifications($user));
    }

    public function testOnUserWarnedCreatesNotification(): void
    {
        $staff = ['id' => 1, 'username' => 'staff'];
        $this->notificationService->onUserWarned(2, $staff, 'Harassment', 42);

        $this->assertSame(1, $this->notifications->countUnread(2));
        $items = $this->notifications->listForUser(2);
        $this->assertSame(NotificationRepository::TYPE_USER_WARN, $items[0]['event_type']);
        $this->assertStringContainsString('Harassment', $items[0]['message']);
    }

    public function testThemeModeUsesSiteDefaultForGuests(): void
    {
        $mode = new ThemeMode();
        $this->assertSame('dark', $mode->preference(null, null, ThemeMode::DARK));
    }

    public function testEmailNotificationRespectsUserOptOut(): void
    {
        $settings = new SettingRepository($this->db);
        $settings->setBool('mail_enabled', true);
        $settings->set('mail_from_email', 'noreply@test');
        $settings->set('mail_from_name', 'Test');
        $settings->setBool('email_notify_warnings', true);

        $this->users->updateNotifyEmail(2, false);
        $user = $this->users->findById(2);
        $this->assertNotNull($user);
        $this->assertFalse($this->users->wantsEmailNotifications($user));

        $mail = new Mail(new Config(LATCH_ROOT . '/config'), $settings);
        $service = new EmailNotificationService($mail, $settings, $this->users);
        $service->maybeSend(2, NotificationRepository::TYPE_USER_WARN, 'Warning', '/profile');
    }
}