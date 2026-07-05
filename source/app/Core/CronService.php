<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

use Latch\Models\ApiAuditLogRepository;
use Latch\Models\AuditLogRepository;
use Latch\Models\EmailChangeRepository;
use Latch\Models\EmailVerificationRepository;
use Latch\Models\NotificationRepository;
use Latch\Models\OAuthTokenRepository;
use Latch\Models\PasswordResetRepository;
use Latch\Models\SettingRepository;
use Latch\Models\UserRepository;
use Latch\Models\UserSessionRepository;
use Latch\Support\ForeignKeyCheck;
use Latch\Support\UserDependencyCleanup;

/**
 * Scheduled maintenance: DB prunes and reputation jobs. Never purges guest page cache.
 */
final class CronService
{
    public function __construct(
        private readonly Database $db,
        private readonly SettingRepository $settings,
        private readonly PasswordResetRepository $passwordResets,
        private readonly EmailVerificationRepository $emailVerifications,
        private readonly EmailChangeRepository $emailChanges,
        private readonly UserSessionRepository $userSessions,
        private readonly UserRepository $users,
        private readonly NotificationRepository $notifications,
        private readonly RateLimiter $rateLimiter,
        private readonly OAuthTokenRepository $oauthTokens,
        private readonly ApiAuditLogRepository $apiAuditLog,
        private readonly ?ReputationService $reputation = null,
    ) {
    }

    /**
     * @return array<string, int|bool>
     */
    public function runHourly(): array
    {
        $started = hrtime(true);
        $stats = [
            'search_attempts' => $this->rateLimiter->pruneSearchAttempts(),
            'api_rate_attempts' => $this->rateLimiter->pruneApiRateAttempts(),
            'reputation_queue' => 0,
        ];

        if ($this->reputation !== null && $this->reputationQueueExists()) {
            $stats['reputation_queue'] = $this->reputation->recomputeQueued();
        }

        $this->recordRun('hourly', $this->elapsedMs($started), $stats);

        return $stats;
    }

    /**
     * @return array<string, int|bool>
     */
    public function runDaily(): array
    {
        $started = hrtime(true);
        $stats = [
            'password_resets' => $this->passwordResets->pruneExpired(),
            'email_verifications' => $this->emailVerifications->pruneExpired(),
            'email_changes' => $this->emailChanges->pruneExpired(),
            'stale_sessions' => $this->userSessions->pruneStale(90),
            'oauth_tokens' => $this->oauthTokens->pruneExpired(),
            'api_audit_log' => $this->apiAuditLog->prune(),
            'login_attempts' => $this->pruneLoginAttempts(),
            'read_notifications' => $this->pruneReadNotifications(),
            'notification_cap' => $this->capNotifications(),
            'expired_bans' => $this->users->sweepExpiredBans(),
            'user_orphans' => $this->pruneUserOrphans(),
            'deleted_users' => $this->pruneExpiredDeletedUsers(),
            'reputation_members' => 0,
        ];

        if ($this->reputation !== null && $this->reputationColumnsExist()) {
            $stats['reputation_members'] = $this->reputation->recomputeAll();
        }

        $this->settings->set('last_cron_daily_at', gmdate('c'));
        $this->recordRun('daily', $this->elapsedMs($started), $stats);

        return $stats;
    }

    /**
     * @return array<string, int|bool>
     */
    public function runWeekly(bool $pruneAuditLog = false): array
    {
        $started = hrtime(true);
        $pdo = $this->db->pdo();

        $pdo->exec('PRAGMA optimize');
        $pdo->exec('ANALYZE');

        $stats = [
            'dm_hard_deleted' => $this->purgeSoftDeletedMessages(),
            'dm_user_retention' => $this->pruneDmUserRetention(),
            'topic_reads_orphans' => $this->pruneOrphanTopicReads(),
            'topic_reads_stale' => $this->pruneStaleTopicReads(180),
            'foreign_key_violations' => $this->countForeignKeyViolations(),
            'audit_log' => 0,
        ];

        if ($pruneAuditLog) {
            $stats['audit_log'] = $this->pruneAuditLog();
        }

        $this->recordRun('weekly', $this->elapsedMs($started), $stats);

        return $stats;
    }

    public function lastDailyRunAt(): ?string
    {
        return $this->settings->get('last_cron_daily_at');
    }

    private function pruneLoginAttempts(): int
    {
        $days = max(1, (int) ($this->settings->get('cron_login_attempts_retain_days', '14') ?? '14'));
        $cutoff = gmdate('c', time() - ($days * 86400));
        $stmt = $this->db->pdo()->prepare('DELETE FROM login_attempts WHERE attempted_at < :cutoff');
        $stmt->execute(['cutoff' => $cutoff]);

        return $stmt->rowCount();
    }

    private function pruneReadNotifications(): int
    {
        if (!$this->notificationsTableExists()) {
            return 0;
        }

        $days = max(1, (int) ($this->settings->get('cron_read_notification_retain_days', '90') ?? '90'));
        $cutoff = gmdate('c', time() - ($days * 86400));
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM user_notifications
             WHERE read_at IS NOT NULL AND created_at < :cutoff'
        );
        $stmt->execute(['cutoff' => $cutoff]);

        return $stmt->rowCount();
    }

    private function capNotifications(): int
    {
        if (!$this->notificationsTableExists()) {
            return 0;
        }

        $cap = (int) ($this->settings->get('cron_notification_cap', '500') ?? '500');
        if ($cap <= 0) {
            return 0;
        }

        $pdo = $this->db->pdo();
        $users = $pdo->query(
            'SELECT user_id, COUNT(*) AS total
             FROM user_notifications
             GROUP BY user_id
             HAVING total > ' . $cap
        )->fetchAll();

        $deleted = 0;
        foreach ($users as $row) {
            $userId = (int) $row['user_id'];
            $excess = (int) $row['total'] - $cap;
            if ($excess <= 0) {
                continue;
            }

            $select = $pdo->prepare(
                'SELECT id FROM user_notifications
                 WHERE user_id = :user_id AND read_at IS NOT NULL
                 ORDER BY created_at ASC, id ASC
                 LIMIT :limit'
            );
            $select->bindValue('user_id', $userId, \PDO::PARAM_INT);
            $select->bindValue('limit', $excess, \PDO::PARAM_INT);
            $select->execute();
            $ids = $select->fetchAll(\PDO::FETCH_COLUMN);
            if ($ids === []) {
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $delete = $pdo->prepare("DELETE FROM user_notifications WHERE id IN ({$placeholders})");
            foreach ($ids as $i => $id) {
                $delete->bindValue($i + 1, (int) $id, \PDO::PARAM_INT);
            }
            $delete->execute();
            $deleted += $delete->rowCount();
        }

        return $deleted;
    }

    private function purgeSoftDeletedMessages(): int
    {
        if (!$this->dmTableExists()) {
            return 0;
        }

        $cutoff = gmdate('c', time() - (30 * 86400));
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM dm_messages
             WHERE deleted_at IS NOT NULL AND deleted_at < :cutoff'
        );
        $stmt->execute(['cutoff' => $cutoff]);

        return $stmt->rowCount();
    }

    private function pruneDmUserRetention(): int
    {
        if (!$this->dmTableExists()) {
            return 0;
        }

        $days = (int) ($this->settings->get('dm_retain_user_days', '0') ?? '0');
        if ($days <= 0) {
            return 0;
        }

        $cutoff = gmdate('c', time() - ($days * 86400));
        $stmt = $this->db->pdo()->prepare(
            "DELETE FROM dm_messages
             WHERE kind = 'user' AND deleted_at IS NULL AND created_at < :cutoff"
        );
        $stmt->execute(['cutoff' => $cutoff]);

        return $stmt->rowCount();
    }

    private function pruneUserOrphans(): int
    {
        $removed = (new UserDependencyCleanup())->pruneOrphans($this->db->pdo());

        return array_sum($removed);
    }

    private function pruneExpiredDeletedUsers(): int
    {
        $days = max(1, (int) ($this->settings->get('cron_deleted_user_retain_days', '30') ?? '30'));

        return $this->users->purgeExpiredDeleted($days);
    }

    private function countForeignKeyViolations(): int
    {
        $pdo = $this->db->pdo();
        $pdo->exec('PRAGMA foreign_keys = ON');
        $violations = $pdo->query('PRAGMA foreign_key_check')->fetchAll();

        return count(ForeignKeyCheck::partitionViolations($violations)['unexpected']);
    }

    private function pruneOrphanTopicReads(): int
    {
        if (!$this->topicReadsTableExists()) {
            return 0;
        }

        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM topic_reads
             WHERE topic_id NOT IN (SELECT id FROM topics WHERE deleted_at IS NULL)'
        );
        $stmt->execute();

        return $stmt->rowCount();
    }

    private function pruneStaleTopicReads(int $days): int
    {
        if (!$this->topicReadsTableExists() || $days <= 0) {
            return 0;
        }

        $cutoff = gmdate('c', time() - ($days * 86400));
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM topic_reads WHERE last_read_at < :cutoff'
        );
        $stmt->execute(['cutoff' => $cutoff]);

        return $stmt->rowCount();
    }

    private function pruneAuditLog(): int
    {
        if (!$this->auditLogTableExists()) {
            return 0;
        }

        $days = max(30, (int) ($this->settings->get('audit_log_retain_days', '365') ?? '365'));
        $cutoff = gmdate('c', time() - ($days * 86400));
        $stmt = $this->db->pdo()->prepare('DELETE FROM audit_log WHERE created_at < :cutoff');
        $stmt->execute(['cutoff' => $cutoff]);

        return $stmt->rowCount();
    }

    /**
     * @param array<string, int|bool> $stats
     */
    private function recordRun(string $job, int $durationMs, array $stats): void
    {
        if (!$this->maintenanceRunsTableExists()) {
            return;
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO maintenance_runs (job, ran_at, duration_ms, stats_json)
             VALUES (:job, :ran_at, :duration_ms, :stats_json)'
        );
        $stmt->execute([
            'job' => $job,
            'ran_at' => gmdate('c'),
            'duration_ms' => $durationMs,
            'stats_json' => json_encode($stats, JSON_THROW_ON_ERROR),
        ]);
    }

    private function elapsedMs(int $startedHrtime): int
    {
        return (int) round((hrtime(true) - $startedHrtime) / 1_000_000);
    }

    private function maintenanceRunsTableExists(): bool
    {
        return $this->tableExists('maintenance_runs');
    }

    private function reputationQueueExists(): bool
    {
        return $this->tableExists('reputation_queue');
    }

    private function reputationColumnsExist(): bool
    {
        $stmt = $this->db->pdo()->query('PRAGMA table_info(users)');
        $columns = array_column($stmt->fetchAll(), 'name');

        return in_array('reputation_score', $columns, true);
    }

    private function notificationsTableExists(): bool
    {
        return $this->tableExists('user_notifications');
    }

    private function dmTableExists(): bool
    {
        return $this->tableExists('dm_messages');
    }

    private function topicReadsTableExists(): bool
    {
        return $this->tableExists('topic_reads');
    }

    private function auditLogTableExists(): bool
    {
        return $this->tableExists('audit_log');
    }

    private function tableExists(string $name): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1"
        );
        $stmt->execute(['name' => $name]);

        return (bool) $stmt->fetchColumn();
    }
}