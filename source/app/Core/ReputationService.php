<?php

declare(strict_types=1);

namespace Latch\Core;

use Latch\Models\PostRepository;
use Latch\Models\SettingRepository;
use Latch\Models\UserRepository;
use Latch\Core\Database;

/**
 * Composite member reputation (ranks 1–5). Staff are excluded from scoring.
 */
final class ReputationService
{
    /** @var array<int, string> */
    public const RANK_LABELS = [
        1 => 'New',
        2 => 'Regular',
        3 => 'Established',
        4 => 'Trusted',
        5 => 'Core',
    ];

    /** @var array<string, float|int> */
    private const DEFAULT_WEIGHTS = [
        'topic' => 3.0,
        'reply' => 2.0,
        'dislike' => 1.5,
        'read' => 2.0,
        'watch' => 1.5,
        'active_day' => 2.0,
        'like_cap' => 10,
        'warning_penalty' => 15.0,
    ];

    /** @var array<int, array{min_score: float, min_age_days: int}> */
    private const DEFAULT_THRESHOLDS = [
        1 => ['min_score' => 0.0, 'min_age_days' => 0],
        2 => ['min_score' => 15.0, 'min_age_days' => 7],
        3 => ['min_score' => 40.0, 'min_age_days' => 30],
        4 => ['min_score' => 80.0, 'min_age_days' => 90],
        5 => ['min_score' => 130.0, 'min_age_days' => 180],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly SettingRepository $settings,
    ) {
    }

    public static function rankLabel(int $rank): string
    {
        return self::RANK_LABELS[$rank] ?? 'Member';
    }

    /**
     * @return array{score: float, rank: int|null, components: array<string, float|int>, is_member: bool}
     */
    public function profileViewForUser(array $user): array
    {
        $role = (string) ($user['role'] ?? Auth::ROLE_MEMBER);
        if ($role !== Auth::ROLE_MEMBER) {
            return [
                'score' => 0.0,
                'rank' => null,
                'components' => [],
                'is_member' => false,
            ];
        }

        $rank = isset($user['reputation_rank']) ? (int) $user['reputation_rank'] : null;
        $score = isset($user['reputation_score']) ? (float) $user['reputation_score'] : 0.0;
        $components = [];

        if ($rank === null || ($user['reputation_computed_at'] ?? null) === null) {
            $computed = $this->computeForUser((int) $user['id']);
            $rank = $computed['rank'];
            $score = $computed['score'];
            $components = $computed['components'];
        } else {
            $components = $this->loadLatestComponents((int) $user['id']);
        }

        return [
            'score' => $score,
            'rank' => $rank,
            'label' => $rank !== null ? self::rankLabel($rank) : null,
            'components' => $components,
            'is_member' => true,
            'next_rank' => $this->nextRankHint($rank, $score),
        ];
    }

    /**
     * @return array{score: float, rank: int|null, components: array<string, float|int>}
     */
    public function computeForUser(int $userId): array
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new \InvalidArgumentException('User not found: ' . $userId);
        }

        $role = (string) ($user['role'] ?? Auth::ROLE_MEMBER);
        if ($role !== Auth::ROLE_MEMBER) {
            $this->users->clearReputation($userId);

            return ['score' => 0.0, 'rank' => null, 'components' => []];
        }

        if ($this->users->isBanned($user)) {
            $this->users->updateReputation($userId, 0.0, null, gmdate('c'));

            return ['score' => 0.0, 'rank' => null, 'components' => []];
        }

        $components = $this->aggregateComponents($userId, (string) $user['created_at']);
        $weights = $this->weights();
        $score = $this->scoreFromComponents($components, $weights);
        $rank = $this->resolveRank(
            $score,
            (int) $components['account_age_days'],
            (int) $components['warnings_90d'],
            $this->thresholds(),
        );

        if (isset($user['rank_override']) && $user['rank_override'] !== null && $user['rank_override'] !== '') {
            $rank = max(1, min(5, (int) $user['rank_override']));
        }

        $computedAt = gmdate('c');
        $this->users->updateReputation($userId, $score, $rank, $computedAt);
        $this->recordSnapshot($userId, $score, $rank, $components, $computedAt);

        return [
            'score' => $score,
            'rank' => $rank,
            'components' => $components,
        ];
    }

    public function recomputeAll(): int
    {
        $stmt = $this->db->pdo()->query(
            "SELECT id FROM users WHERE role = 'member' AND username NOT LIKE 'deleted_%'"
        );
        $count = 0;
        foreach ($stmt->fetchAll() as $row) {
            $this->computeForUser((int) $row['id']);
            $count++;
        }

        return $count;
    }

    public function enqueueUser(int $userId): void
    {
        if (!$this->reputationQueueTableExists()) {
            return;
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO reputation_queue (user_id, queued_at)
             VALUES (:user_id, :queued_at)
             ON CONFLICT(user_id) DO UPDATE SET queued_at = excluded.queued_at'
        );
        $stmt->execute([
            'user_id' => $userId,
            'queued_at' => gmdate('c'),
        ]);
    }

    /**
     * @return array<string, float|int>
     */
    public function configuredWeights(): array
    {
        return $this->weights();
    }

    /**
     * @return array<int, array{min_score: float, min_age_days: int}>
     */
    public function configuredThresholds(): array
    {
        return $this->thresholds();
    }

    /**
     * Validate and persist reputation_weights / reputation_thresholds from admin settings form.
     *
     * @param array<string, mixed> $weightsInput
     * @param array<int|string, mixed> $thresholdsInput
     */
    public function saveAdminSettings(array $weightsInput, array $thresholdsInput): void
    {
        $weights = self::DEFAULT_WEIGHTS;
        foreach (array_keys(self::DEFAULT_WEIGHTS) as $key) {
            if (!array_key_exists($key, $weightsInput)) {
                continue;
            }
            $value = $weightsInput[$key];
            if ($key === 'like_cap') {
                $weights[$key] = max(1, min(100, (int) $value));
            } else {
                $weights[$key] = max(0.0, (float) $value);
            }
        }

        $thresholds = self::DEFAULT_THRESHOLDS;
        foreach (self::DEFAULT_THRESHOLDS as $rank => $defaults) {
            $row = $thresholdsInput[$rank] ?? $thresholdsInput[(string) $rank] ?? [];
            if (!is_array($row)) {
                continue;
            }
            $thresholds[$rank] = [
                'min_score' => max(0.0, (float) ($row['min_score'] ?? $defaults['min_score'])),
                'min_age_days' => max(0, (int) ($row['min_age_days'] ?? $defaults['min_age_days'])),
            ];
        }

        $this->settings->set('reputation_weights', json_encode($weights, JSON_THROW_ON_ERROR));
        $this->settings->set('reputation_thresholds', json_encode($thresholds, JSON_THROW_ON_ERROR));
    }

    public function recomputeQueued(): int
    {
        if (!$this->reputationQueueTableExists()) {
            return 0;
        }

        $pdo = $this->db->pdo();
        $stmt = $pdo->query('SELECT user_id FROM reputation_queue ORDER BY queued_at ASC');
        $count = 0;

        foreach ($stmt->fetchAll() as $row) {
            $userId = (int) $row['user_id'];
            $this->computeForUser($userId);
            $pdo->prepare('DELETE FROM reputation_queue WHERE user_id = :user_id')
                ->execute(['user_id' => $userId]);
            $count++;
        }

        return $count;
    }

    private function reputationQueueTableExists(): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'reputation_queue' LIMIT 1"
        );
        $stmt->execute();
        $exists = (bool) $stmt->fetchColumn();

        return $exists;
    }

    /**
     * @return array<string, float|int>
     */
    private function aggregateComponents(int $userId, string $createdAt): array
    {
        $pdo = $this->db->pdo();
        $approved = PostRepository::APPROVAL_APPROVED;
        $now = time();
        $cutoff90 = gmdate('c', $now - 90 * 86400);

        $topicStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM topics
             WHERE user_id = :user_id AND deleted_at IS NULL'
        );
        $topicStmt->execute(['user_id' => $userId]);
        $topics = (int) $topicStmt->fetchColumn();

        $replyStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM posts p
             INNER JOIN topics t ON t.id = p.topic_id AND t.deleted_at IS NULL
             WHERE p.user_id = :user_id
               AND p.deleted_at IS NULL
               AND p.quarantined_at IS NULL
               AND p.approval_status = :approved
               AND p.id != (
                   SELECT MIN(p2.id) FROM posts p2
                   WHERE p2.topic_id = p.topic_id AND p2.deleted_at IS NULL
               )"
        );
        $replyStmt->execute(['user_id' => $userId, 'approved' => $approved]);
        $replies = (int) $replyStmt->fetchColumn();

        $reactionStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN p.like_count > 0 THEN p.like_count ELSE 0 END), 0),
                    COALESCE(SUM(CASE WHEN p.dislike_count > 0 THEN p.dislike_count ELSE 0 END), 0)
             FROM posts p
             WHERE p.user_id = :user_id
               AND p.deleted_at IS NULL
               AND p.quarantined_at IS NULL
               AND p.approval_status = :approved"
        );
        $reactionStmt->execute(['user_id' => $userId, 'approved' => $approved]);
        /** @var array{0: string, 1: string} $reactionRow */
        $reactionRow = $reactionStmt->fetch(\PDO::FETCH_NUM) ?: ['0', '0'];
        $likesTotal = (int) $reactionRow[0];
        $dislikesTotal = (int) $reactionRow[1];

        $readsStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM topic_reads
             WHERE user_id = :user_id AND last_read_at >= :cutoff'
        );
        $readsStmt->execute(['user_id' => $userId, 'cutoff' => $cutoff90]);
        $reads90d = (int) $readsStmt->fetchColumn();

        $watchStmt = $pdo->prepare('SELECT COUNT(*) FROM topic_watches WHERE user_id = :user_id');
        $watchStmt->execute(['user_id' => $userId]);
        $watches = (int) $watchStmt->fetchColumn();

        $activeStmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT substr(last_seen_at, 1, 10)) FROM user_sessions
             WHERE user_id = :user_id AND last_seen_at >= :cutoff'
        );
        $activeStmt->execute(['user_id' => $userId, 'cutoff' => $cutoff90]);
        $activeDays90d = (int) $activeStmt->fetchColumn();

        $warnStmt = $pdo->prepare('SELECT COUNT(*) FROM user_warnings WHERE user_id = :user_id');
        $warnStmt->execute(['user_id' => $userId]);
        $warningsTotal = (int) $warnStmt->fetchColumn();

        $warn90Stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM user_warnings WHERE user_id = :user_id AND created_at >= :cutoff'
        );
        $warn90Stmt->execute(['user_id' => $userId, 'cutoff' => $cutoff90]);
        $warnings90d = (int) $warn90Stmt->fetchColumn();

        $createdTs = strtotime($createdAt) ?: $now;
        $accountAgeDays = (int) floor(($now - $createdTs) / 86400);

        $likeCap = (int) ($this->weights()['like_cap'] ?? 10);
        $cappedLikes = min($likesTotal, $likeCap * max(1, $topics + $replies));
        $cappedDislikes = min($dislikesTotal, $likeCap * max(1, $topics + $replies));

        return [
            'topics' => $topics,
            'replies' => $replies,
            'likes_received' => $likesTotal,
            'dislikes_received' => $dislikesTotal,
            'capped_likes' => $cappedLikes,
            'capped_dislikes' => $cappedDislikes,
            'topics_read_90d' => $reads90d,
            'watched_topics' => $watches,
            'active_days_90d' => $activeDays90d,
            'warnings_total' => $warningsTotal,
            'warnings_90d' => $warnings90d,
            'account_age_days' => $accountAgeDays,
        ];
    }

    /**
     * @param array<string, float|int> $components
     * @param array<string, float|int> $weights
     */
    private function scoreFromComponents(array $components, array $weights): float
    {
        $participation = ($weights['topic'] * sqrt((float) $components['topics']))
            + ($weights['reply'] * sqrt((float) $components['replies']));

        $engagement = ($weights['read'] * sqrt((float) $components['topics_read_90d']))
            + ($weights['watch'] * sqrt((float) $components['watched_topics']))
            + ($weights['active_day'] * sqrt((float) $components['active_days_90d']));

        $quality = (float) $components['capped_likes']
            - ($weights['dislike'] * (float) $components['capped_dislikes']);

        $tenure = min((int) $components['account_age_days'], 365) / 30.0;

        $penalties = $weights['warning_penalty'] * (int) $components['warnings_total'];

        return max(0.0, max($participation, $engagement) + $quality + $tenure - $penalties);
    }

    /**
     * @param array<int, array{min_score: float, min_age_days: int}> $thresholds
     */
    private function resolveRank(float $score, int $ageDays, int $warnings90d, array $thresholds): int
    {
        if ($ageDays < 7) {
            return 1;
        }

        $rank = 1;
        for ($r = 2; $r <= 5; $r++) {
            $t = $thresholds[$r] ?? self::DEFAULT_THRESHOLDS[$r];
            if ($score >= $t['min_score'] && $ageDays >= $t['min_age_days']) {
                if ($r === 5 && $warnings90d > 0) {
                    continue;
                }
                $rank = $r;
            }
        }

        return $rank;
    }

    /**
     * @return array{rank: int, label: string, points_needed: float}|null
     */
    private function nextRankHint(?int $currentRank, float $score): ?array
    {
        if ($currentRank === null || $currentRank >= 5) {
            return null;
        }

        $next = $currentRank + 1;
        $thresholds = $this->thresholds();
        $t = $thresholds[$next] ?? self::DEFAULT_THRESHOLDS[$next];
        $needed = max(0.0, $t['min_score'] - $score);

        return [
            'rank' => $next,
            'label' => self::rankLabel($next),
            'points_needed' => round($needed, 1),
        ];
    }

    /**
     * @return array<string, float|int>
     */
    private function loadLatestComponents(int $userId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT components_json FROM reputation_snapshots
             WHERE user_id = :user_id ORDER BY computed_at DESC LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $json = $stmt->fetchColumn();
        if (!is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, float|int> $components
     */
    private function recordSnapshot(
        int $userId,
        float $score,
        int $rank,
        array $components,
        string $computedAt,
    ): void {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO reputation_snapshots (user_id, score, rank, components_json, computed_at)
             VALUES (:user_id, :score, :rank, :components_json, :computed_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'score' => $score,
            'rank' => $rank,
            'components_json' => json_encode($components, JSON_THROW_ON_ERROR),
            'computed_at' => $computedAt,
        ]);
    }

    /**
     * @return array<string, float|int>
     */
    private function weights(): array
    {
        return $this->loadJsonSetting('reputation_weights', self::DEFAULT_WEIGHTS);
    }

    /**
     * @return array<int, array{min_score: float, min_age_days: int}>
     */
    private function thresholds(): array
    {
        $raw = $this->loadJsonSetting('reputation_thresholds', self::DEFAULT_THRESHOLDS);
        $out = [];
        foreach (self::DEFAULT_THRESHOLDS as $rank => $defaults) {
            $row = $raw[$rank] ?? $raw[(string) $rank] ?? [];
            if (!is_array($row)) {
                $row = [];
            }
            $out[$rank] = [
                'min_score' => (float) ($row['min_score'] ?? $defaults['min_score']),
                'min_age_days' => (int) ($row['min_age_days'] ?? $defaults['min_age_days']),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|array<int, mixed> $default
     * @return array<string, mixed>|array<int, mixed>
     */
    private function loadJsonSetting(string $key, array $default): array
    {
        $raw = $this->settings->get($key);
        if ($raw === null || trim($raw) === '') {
            return $default;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_replace_recursive($default, $decoded) : $default;
    }
}