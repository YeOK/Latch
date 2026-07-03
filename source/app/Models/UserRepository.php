<?php

declare(strict_types=1);

namespace Latch\Models;

use Latch\Core\BoardAcl;
use Latch\Core\Database;
use Latch\Core\InputValidator;
use Latch\Core\ThemeMode;
use Latch\Support\UserDependencyCleanup;

final class UserRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly ?InputValidator $input = null,
    ) {
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM users WHERE username = :username COLLATE NOCASE LIMIT 1');
        $stmt->execute(['username' => $username]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM users WHERE email = :email COLLATE NOCASE LIMIT 1');
        $stmt->execute(['email' => strtolower(trim($email))]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function all(): array
    {
        return $this->db->pdo()->query('SELECT * FROM users ORDER BY created_at DESC')->fetchAll();
    }

    public function countAll(): int
    {
        return (int) $this->db->pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    /**
     * @return array{users: list<array>, total: int, page: int, per_page: int}
     */
    public function listAdmin(string $filter, string $search, int $page, int $perPage = 50): array
    {
        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $where = ['1=1'];
        $params = [];

        if ($filter === 'staff') {
            $where[] = "role IN ('admin', 'mod')";
        } elseif ($filter === 'members') {
            $where[] = "role = 'member'";
            $where[] = 'banned_at IS NULL';
            $where[] = '(banned_until IS NULL OR banned_until <= :now_active)';
            $params['now_active'] = gmdate('c');
        } elseif ($filter === 'banned') {
            $where[] = '(banned_at IS NOT NULL OR (banned_until IS NOT NULL AND banned_until > :now_banned))';
            $params['now_banned'] = gmdate('c');
        }

        $search = trim($search);
        if ($search !== '') {
            $where[] = '(username LIKE :search OR email LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->db->pdo()->prepare("SELECT COUNT(*) FROM users WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT u.*,
                       (SELECT COUNT(*) FROM user_warnings w WHERE w.user_id = u.id) AS warning_count
                FROM users u
                WHERE {$whereSql}
                ORDER BY u.created_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return [
            'users' => $stmt->fetchAll(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    public function listStaff(): array
    {
        $stmt = $this->db->pdo()->query(
            "SELECT u.*,
                    (SELECT COUNT(*) FROM user_warnings w WHERE w.user_id = u.id) AS warning_count
             FROM users u
             WHERE u.role IN ('admin', 'mod')
             ORDER BY CASE u.role WHEN 'admin' THEN 0 ELSE 1 END, u.username COLLATE NOCASE ASC"
        );

        return $stmt->fetchAll();
    }

    public function countBanned(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COUNT(*) FROM users
             WHERE banned_at IS NOT NULL OR (banned_until IS NOT NULL AND banned_until > :now)'
        );
        $stmt->execute(['now' => gmdate('c')]);

        return (int) $stmt->fetchColumn();
    }

    public function create(
        string $username,
        string $email,
        string $password,
        string $role = 'member',
        ?string $defaultThemeMode = null,
    ): array {
        $this->input?->assertUsername($username);

        $themeMode = ThemeMode::normalizePreference($defaultThemeMode ?? ThemeMode::SYSTEM);

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO users (username, email, password_hash, role, theme_mode, created_at)
             VALUES (:username, :email, :password_hash, :role, :theme_mode, :created_at)'
        );
        $stmt->execute([
            'username' => $username,
            'email' => strtolower(trim($email)),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'theme_mode' => $themeMode,
            'created_at' => gmdate('c'),
        ]);

        return $this->findById((int) $this->db->pdo()->lastInsertId()) ?? [];
    }

    /**
     * Social/OIDC sign-up — random password the user never receives.
     */
    public function createSocial(
        string $username,
        string $email,
        string $role = 'member',
        ?string $defaultThemeMode = null,
    ): array {
        return $this->create(
            $username,
            $email,
            bin2hex(random_bytes(32)),
            $role,
            $defaultThemeMode,
        );
    }

    public function updateRole(int $id, string $role): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE users SET role = :role WHERE id = :id');
        $stmt->execute(['role' => $role, 'id' => $id]);
    }

    public function ban(int $id, ?string $until = null, ?string $reason = null): void
    {
        $reason = $reason !== null ? trim($reason) : null;
        if ($reason === '') {
            $reason = null;
        }

        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET banned_at = :banned_at, banned_until = :banned_until, ban_reason = :ban_reason WHERE id = :id'
        );
        $stmt->execute([
            'banned_at' => gmdate('c'),
            'banned_until' => $until,
            'ban_reason' => $reason,
            'id' => $id,
        ]);
    }

    public function wantsEmailNotifications(array $user): bool
    {
        return (int) ($user['notify_email'] ?? 1) === 1;
    }

    public function updateNotifyEmail(int $id, bool $enabled): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE users SET notify_email = :notify_email WHERE id = :id');
        $stmt->execute([
            'notify_email' => $enabled ? 1 : 0,
            'id' => $id,
        ]);
    }

    public function acceptsMessages(array $user): bool
    {
        return (int) ($user['accept_messages'] ?? 1) === 1;
    }

    public function updateAcceptMessages(int $id, bool $enabled): void
    {
        if (!$this->hasAcceptMessagesColumn()) {
            return;
        }

        $stmt = $this->db->pdo()->prepare('UPDATE users SET accept_messages = :accept_messages WHERE id = :id');
        $stmt->execute([
            'accept_messages' => $enabled ? 1 : 0,
            'id' => $id,
        ]);
    }

    public function unban(int $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET banned_at = NULL, banned_until = NULL, ban_reason = NULL,
                    failed_login_count = 0, locked_until = NULL
             WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    public function clearExpiredBan(int $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET banned_at = NULL, banned_until = NULL
             WHERE id = :id AND banned_until IS NOT NULL AND banned_until <= :now'
        );
        $stmt->execute(['id' => $id, 'now' => gmdate('c')]);
    }

    public function sweepExpiredBans(): int
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET banned_at = NULL, banned_until = NULL
             WHERE banned_until IS NOT NULL AND banned_until <= :now'
        );
        $stmt->execute(['now' => gmdate('c')]);

        return $stmt->rowCount();
    }

    public function isBanned(array $user): bool
    {
        if (($user['banned_at'] ?? null) !== null) {
            $until = $user['banned_until'] ?? null;
            if ($until !== null && strtotime((string) $until) <= time()) {
                return false;
            }

            return true;
        }

        $until = $user['banned_until'] ?? null;

        return $until !== null && strtotime((string) $until) > time();
    }

    public function banLoginMessage(array $user): string
    {
        $until = $user['banned_until'] ?? null;
        if ($until !== null && strtotime((string) $until) > time()) {
            $formatted = gmdate('j M Y H:i', strtotime((string) $until)) . ' UTC';

            return "Your account is banned until {$formatted}.";
        }

        return 'Your account has been permanently banned. Contact an administrator if you believe this is an error.';
    }

    public function touchLogin(int $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET last_login_at = :t, failed_login_count = 0, locked_until = NULL WHERE id = :id'
        );
        $stmt->execute(['t' => gmdate('c'), 'id' => $id]);
    }

    public function updatePassword(int $id, string $password): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET password_hash = :hash, password_changed_at = :changed_at, failed_login_count = 0, locked_until = NULL
             WHERE id = :id'
        );
        $stmt->execute([
            'hash' => password_hash($password, PASSWORD_DEFAULT),
            'changed_at' => gmdate('c'),
            'id' => $id,
        ]);
    }

    public function updateEmail(int $id, string $email): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET email = :email, email_verified_at = :verified_at WHERE id = :id'
        );
        $stmt->execute([
            'email' => strtolower(trim($email)),
            'verified_at' => gmdate('c'),
            'id' => $id,
        ]);
    }

    public function markEmailVerified(int $id): void
    {
        $stmt = $this->db->pdo()->prepare('UPDATE users SET email_verified_at = :t WHERE id = :id');
        $stmt->execute(['t' => gmdate('c'), 'id' => $id]);
    }

    public function isEmailVerified(array $user): bool
    {
        return $user['email_verified_at'] !== null;
    }

    public function recordFailedLogin(int $id, int $maxAttempts, int $lockoutMinutes): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET failed_login_count = failed_login_count + 1 WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);

        $user = $this->findById($id);
        if ($user === null) {
            return;
        }

        if ((int) ($user['failed_login_count'] ?? 0) >= $maxAttempts) {
            $lockedUntil = gmdate('c', time() + $lockoutMinutes * 60);
            $stmt = $this->db->pdo()->prepare('UPDATE users SET locked_until = :locked_until WHERE id = :id');
            $stmt->execute(['locked_until' => $lockedUntil, 'id' => $id]);
        }
    }

    public function isLocked(array $user): bool
    {
        $lockedUntil = $user['locked_until'] ?? null;
        if ($lockedUntil === null) {
            return false;
        }

        return strtotime((string) $lockedUntil) > time();
    }

    public function updateProfile(int $id, string $bio): void
    {
        $bio = trim($bio);
        $this->input?->assertBio($bio);

        $stmt = $this->db->pdo()->prepare('UPDATE users SET bio = :bio WHERE id = :id');
        $stmt->execute([
            'bio' => $bio,
            'id' => $id,
        ]);
    }

    public function updateThemeMode(int $id, string $mode): void
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['light', 'dark', 'system'], true)) {
            $mode = 'system';
        }

        $stmt = $this->db->pdo()->prepare('UPDATE users SET theme_mode = :mode WHERE id = :id');
        $stmt->execute(['mode' => $mode, 'id' => $id]);
    }

    public function updateLocale(int $id, string $locale): void
    {
        $locale = \Latch\Core\Locale::normalize($locale);
        $stmt = $this->db->pdo()->prepare('UPDATE users SET locale = :locale WHERE id = :id');
        $stmt->execute(['locale' => $locale, 'id' => $id]);
    }

    public function enableTotp(int $id, string $secretEnc): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET totp_secret_enc = :secret, totp_enabled_at = :enabled_at WHERE id = :id'
        );
        $stmt->execute([
            'secret' => $secretEnc,
            'enabled_at' => gmdate('c'),
            'id' => $id,
        ]);
    }

    public function disableTotp(int $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET totp_secret_enc = NULL, totp_enabled_at = NULL WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    public function updateTotpSecretEnc(int $id, string $secretEnc): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET totp_secret_enc = :secret WHERE id = :id'
        );
        $stmt->execute(['secret' => $secretEnc, 'id' => $id]);
    }

    /**
     * @return list<array{id: int, totp_secret_enc: string}>
     */
    public function listTotpEnabled(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT id, totp_secret_enc FROM users WHERE totp_enabled_at IS NOT NULL AND totp_secret_enc IS NOT NULL'
        );

        return $stmt->fetchAll();
    }

    public function updateReputation(int $id, float $score, ?int $rank, string $computedAt): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET reputation_score = :score, reputation_rank = :rank,
                    reputation_computed_at = :computed_at WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'score' => $score,
            'rank' => $rank,
            'computed_at' => $computedAt,
        ]);
    }

    public function clearReputation(int $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET reputation_score = NULL, reputation_rank = NULL,
                    reputation_computed_at = NULL, rank_override = NULL WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    public function setRankOverride(int $id, ?int $rank): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET rank_override = :rank_override WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'rank_override' => $rank,
        ]);
    }

    public function isAnonymised(array $user): bool
    {
        return str_starts_with((string) $user['username'], 'deleted_')
            || str_ends_with((string) ($user['email'] ?? ''), '@deleted.local');
    }

    /**
     * @return array{post_count: int, topic_count: int}
     */
    public function profileStats(int $userId, bool $loggedIn, bool $includeQuarantined = false, ?string $userRole = null): array
    {
        $accessSql = BoardAcl::sqlBoardReadFilter($loggedIn, $userRole);
        $quarantineSql = $includeQuarantined ? '' : ' AND p.quarantined_at IS NULL AND p.approval_status = \'approved\'';

        $postStmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM posts p
             JOIN topics t ON t.id = p.topic_id AND t.deleted_at IS NULL
             JOIN boards b ON b.id = t.board_id
             WHERE p.user_id = :user_id AND p.deleted_at IS NULL{$accessSql}{$quarantineSql}"
        );
        $postStmt->execute(['user_id' => $userId]);

        $topicStmt = $this->db->pdo()->prepare(
            "SELECT COUNT(*) FROM topics t
             JOIN boards b ON b.id = t.board_id
             WHERE t.user_id = :user_id AND t.deleted_at IS NULL{$accessSql}"
        );
        $topicStmt->execute(['user_id' => $userId]);

        return [
            'post_count' => (int) $postStmt->fetchColumn(),
            'topic_count' => (int) $topicStmt->fetchColumn(),
        ];
    }

    public function anonymise(int $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET username = :username, email = :email, password_hash = :hash, banned_at = :banned_at
             WHERE id = :id'
        );
        $stmt->execute([
            'username' => 'deleted_' . $id,
            'email' => 'deleted_' . $id . '@deleted.local',
            'hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            'banned_at' => gmdate('c'),
            'id' => $id,
        ]);
    }

    /**
     * Remove a member account with no posts or topics (spam cleanup).
     */
    private function hasAcceptMessagesColumn(): bool
    {
        static $cache = [];

        $cacheKey = spl_object_id($this->db->pdo());
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $cols = $this->db->pdo()->query('PRAGMA table_info(users)')->fetchAll(\PDO::FETCH_COLUMN, 1);
        $cache[$cacheKey] = in_array('accept_messages', $cols, true);

        return $cache[$cacheKey];
    }

    public function purge(int $id): void
    {
        if ($id === 1) {
            throw new \RuntimeException('The founder account cannot be deleted.');
        }

        $user = $this->findById($id);
        if ($user === null) {
            throw new \RuntimeException('User not found.');
        }

        if ((string) $user['role'] !== 'member') {
            throw new \RuntimeException('Only member accounts can be purged.');
        }

        $counts = $this->profileStats($id, true, true);
        if ($counts['post_count'] > 0 || $counts['topic_count'] > 0) {
            throw new \RuntimeException('User has posts or topics and cannot be purged.');
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            (new UserDependencyCleanup())->deleteForUser($pdo, $id);
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }
}