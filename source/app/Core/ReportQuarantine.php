<?php

declare(strict_types=1);

namespace Latch\Core;

use Latch\Models\PostRepository;
use Latch\Models\ReportRepository;

/**
 * Applies and lifts post quarantine based on report severity.
 */
final class ReportQuarantine
{
    public function __construct(
        private readonly ReportRepository $reports,
        private readonly PostRepository $posts,
        private readonly ReportReasons $reasons,
        private readonly SecurityLog $securityLog,
    ) {
    }

    public function shouldQuarantine(string $severity, int $postId): bool
    {
        if ($this->reasons->severityMeetsThreshold($severity, $this->reasons->quarantineMinSeverity())) {
            return true;
        }

        $threshold = $this->reasons->quarantineReportCount();
        if ($threshold > 0 && $this->reports->countOpenForPost($postId) >= $threshold) {
            return true;
        }

        return false;
    }

    public function apply(int $postId, int $reportId, string $ip, ?int $actorId = null): void
    {
        $this->posts->quarantine($postId, $reportId);
        $this->securityLog->log('quarantine_apply', [
            'ip' => $ip,
            'user_id' => $actorId,
            'target_type' => 'post',
            'target_id' => $postId,
            'meta' => ['report_id' => $reportId],
        ]);
    }

    public function maybeLiftForPost(int $postId, string $ip, int $actorId): void
    {
        if (!$this->posts->isQuarantined($postId)) {
            return;
        }

        $minSeverity = $this->reasons->quarantineMinSeverity();
        if ($this->reports->countOpenSevereForPost($postId, $minSeverity) > 0) {
            return;
        }

        $threshold = $this->reasons->quarantineReportCount();
        if ($threshold > 0 && $this->reports->countOpenForPost($postId) >= $threshold) {
            return;
        }

        $this->posts->liftQuarantine($postId);
        $this->securityLog->log('quarantine_lift', [
            'ip' => $ip,
            'user_id' => $actorId,
            'target_type' => 'post',
            'target_id' => $postId,
        ]);
    }
}