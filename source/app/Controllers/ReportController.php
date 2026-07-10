<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Controllers;

use Latch\Core\Application;
use Latch\Core\Cache;
use Latch\Core\Response;

final class ReportController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function reportPost(array $params): void
    {
        $this->submitReport('post', (int) ($params['id'] ?? 0));
    }

    public function reportUser(array $params): void
    {
        $this->submitReport('user', (int) ($params['id'] ?? 0));
    }

    private function submitReport(string $targetType, int $targetId): void
    {
        $this->app->auth()->requireLogin();

        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            Response::forbidden('Invalid form token.');
        }

        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::redirect('/login');
        }

        if ($this->app->reports()->countRecentByReporter((int) $user['id']) >= 5) {
            $this->app->session()->flash('error', 'You have submitted too many reports. Try again later.');
            Response::redirect($this->app->request()->safeRedirectFromReferer(
                $this->app->request()->header('Referer', '/'),
                $this->app->siteUrl(),
            ));
        }

        $reasonCode = (string) $this->app->request()->input('reason_code', '');
        if (!$this->app->reportReasons()->isValidCode($reasonCode)) {
            $this->app->session()->flash('error', 'Please select a report reason.');
            Response::redirect($this->redirectBack($targetType, $targetId));
        }

        $reasonDetail = trim((string) $this->app->request()->input('reason_detail', ''));
        $detailError = $this->app->inputValidator()->reportDetailError($reasonDetail);
        if ($detailError !== null) {
            $this->app->session()->flash('error', $detailError);
            Response::redirect($this->redirectBack($targetType, $targetId));
        }

        if ($this->app->reports()->hasOpenReportByReporter((int) $user['id'], $targetType, $targetId)) {
            $this->app->session()->flash('error', 'You already have an open report for this content.');
            Response::redirect($this->redirectBack($targetType, $targetId));
        }

        $post = null;
        if ($targetType === 'post') {
            $post = $this->app->posts()->findById($targetId);
            if ($post === null) {
                Response::notFound('Post not found');
            }
            if (!$this->app->canUserAccessPost($post)) {
                Response::forbidden('You cannot report content you cannot view.');
            }
        } elseif ($targetType === 'user') {
            $target = $this->app->users()->findById($targetId);
            if ($target === null || $this->app->users()->isDeleted($target) || $this->app->users()->isBanned($target)) {
                Response::notFound('User not found');
            }
            if ((int) $user['id'] === $targetId) {
                $this->app->session()->flash('error', 'You cannot report yourself.');
                Response::redirect('/profile');
            }
        } else {
            Response::notFound('Invalid report target');
        }

        $severity = $this->app->reportReasons()->severityFor($reasonCode);

        $reportId = $this->app->reports()->create(
            (int) $user['id'],
            $targetType,
            $targetId,
            $reasonCode,
            $severity,
            $reasonDetail,
        );

        $quarantineApplied = false;
        if ($targetType === 'post' && $post !== null) {
            if ($this->app->reportQuarantine()->shouldQuarantine($severity, $targetId)) {
                $this->app->reportQuarantine()->apply($targetId, $reportId, $this->app->request()->ip(), (int) $user['id']);
                $this->app->reports()->markQuarantineApplied($reportId);
                $quarantineApplied = true;
            }

            $topic = $this->app->topics()->findById((int) $post['topic_id']);
            if ($quarantineApplied && $topic !== null) {
                $this->app->notificationService()->onPostQuarantined($post, $topic, (int) $user['id']);
            }
            if ($topic !== null) {
                $this->app->invalidateCacheTags([
                    Cache::tagTopic((int) $topic['id']),
                    Cache::tagBoard((int) $topic['board_id']),
                    Cache::tagSite(),
                ]);
            }
        }

        $this->app->auditLog()->record(
            (int) $user['id'],
            'report.create',
            $targetType,
            $targetId,
            $this->app->request()->ip(),
            ['report_id' => $reportId, 'severity' => $severity, 'reason_code' => $reasonCode],
        );
        $this->app->securityLog()->log('report', [
            'ip' => $this->app->request()->ip(),
            'user_id' => (int) $user['id'],
            'target_type' => $targetType,
            'target_id' => $targetId,
            'meta' => ['severity' => $severity, 'reason_code' => $reasonCode],
        ]);

        $message = $quarantineApplied
            ? 'Report submitted. The post has been hidden pending staff review.'
            : 'Report submitted. Moderators will review it.';
        $this->app->session()->flash('success', $message);
        Response::redirect($this->redirectBack($targetType, $targetId));
    }

    private function redirectBack(string $targetType, int $targetId): string
    {
        if ($targetType === 'post') {
            $post = $this->app->posts()->findById($targetId);

            return $post !== null ? '/topic/' . $post['topic_id'] . '#post-' . $targetId : '/';
        }

        return '/admin/users';
    }
}