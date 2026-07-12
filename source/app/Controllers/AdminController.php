<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Controllers;

use Latch\Core\Application;
use Latch\Core\Auth;
use Latch\Core\Cache;
use Latch\Core\Plugins\PluginAuditFinding;
use Latch\Core\Plugins\PluginAuditReport;
use Latch\Core\Plugins\PluginAuditService;
use Latch\Core\Plugins\PluginAuditor;
use Latch\Core\Plugins\PluginAuditCache;
use Latch\Core\Plugins\PluginCatalog;
use Latch\Core\Plugins\PluginCatalogInstaller;
use Latch\Core\Plugins\PluginCatalogEntry;
use Latch\Core\Plugins\PluginInstaller;
use Latch\Core\Plugins\PluginManifest;
use Latch\Core\Plugins\PluginReleaseDownloader;

use Latch\Core\Plugins\PluginSettingsForm;
use Latch\Core\Plugins\PluginSettingsStore;
use Latch\Core\Plugins\PluginSettingsValidator;
use Latch\Core\ReportReasons;
use Latch\Core\ReputationService;
use Latch\Core\Response;
use Latch\Core\SiteBranding;
use Latch\Core\Webhooks\WebhookEvent;
use Latch\Support\ModerationTrashResponder;
use Latch\Support\OutboundUrlGuard;
use Latch\Support\SiteLock;
use Latch\Support\SiteMaintenance;
use Latch\Support\StaffActionResponder;
use Latch\Support\Str;
use Latch\Support\SystemInfo;
use Latch\Support\VersionInfo;

final class AdminController
{
    use ModerationTrashResponder;
    use StaffActionResponder;

    private const FOUNDER_ID = 1;

    public function __construct(private readonly Application $app)
    {
    }

    protected function staffApp(): Application
    {
        return $this->app;
    }

    public function index(array $params = []): void
    {
        $this->app->auth()->requireAdmin();

        $mailStatus = $this->app->mail()->status();

        $this->app->render('admin/index.html.twig', [
            'post_count' => $this->app->posts()->countAll(),
            'topic_count' => $this->app->topics()->countAll(),
            'user_count' => $this->app->users()->countAll(),
            'board_count' => $this->app->boards()->count(),
            'audit_log_count' => $this->app->auditLog()->countAll(),
            'pending_approval' => $this->app->posts()->countPending(),
            'open_reports' => $this->app->reports()->openCount(),
            'quarantine_count' => $this->app->posts()->countQuarantined(),
            'system_info' => SystemInfo::snapshot(
                $this->app->config(),
                $this->app->cacheEnabled(),
                $mailStatus,
                $this->app->settings()->get('last_cron_daily_at'),
            ),
            'version_info' => VersionInfo::snapshot($this->app->config(), LATCH_ROOT),
        ]);
    }

    public function maintenance(array $params = []): void
    {
        $this->app->auth()->requireAdmin();

        $storagePath = (string) $this->app->config()->get('paths.storage');

        $trashBoard = $this->app->moderationTrash()->trashBoard();

        $this->app->render('admin/maintenance.html.twig', [
            'site_lock' => SiteLock::read($storagePath),
            'mod_trash_count' => $this->app->posts()->countTrashed(),
            'mod_trash_board_path' => $trashBoard !== null
                ? '/board/' . (string) $trashBoard['slug']
                : $this->app->moderationTrash()->trashBoardPath(),
        ]);
    }

    public function quarantineQueue(array $params = []): void
    {
        $this->app->auth()->requireMod();

        $this->app->render('admin/quarantine.html.twig', [
            'entries' => $this->app->posts()->listQuarantined(200),
            'report_categories' => $this->app->reportReasons()->categories(),
        ]);
    }

    public function liftQuarantinedPost(array $params): void
    {
        $this->app->auth()->requireMod();
        $this->validateStaffCsrf();

        $id = (int) ($params['id'] ?? 0);
        $post = $this->app->posts()->findById($id);
        if ($post === null || !$this->app->posts()->isQuarantined($id)) {
            $this->finishStaffAction(false, 'Quarantined post not found.', '/admin/quarantine');
        }

        if (!$this->app->posts()->staffLiftQuarantine($id)) {
            $this->finishStaffAction(false, 'Could not lift quarantine.', '/admin/quarantine');
        }

        $topicId = (int) $post['topic_id'];
        $topic = $this->app->topics()->findById($topicId);
        if ($topic !== null) {
            $this->app->indexSearchTopic($topicId);
            $this->app->invalidateCacheTags([
                Cache::tagTopic($topicId),
                Cache::tagBoard((int) $topic['board_id']),
                Cache::tagSite(),
            ]);
        }

        $actor = $this->app->auth()->user();
        $staffId = (int) ($actor['id'] ?? 0);
        $this->app->auditLog()->record(
            $staffId,
            'post.quarantine_lift',
            'post',
            $id,
            $this->app->request()->ip(),
            ['manual' => true, 'source' => 'admin_queue'],
        );
        $this->app->securityLog()->log('quarantine_lift', [
            'ip' => $this->app->request()->ip(),
            'user_id' => $staffId,
            'target_type' => 'post',
            'target_id' => $id,
            'meta' => ['manual' => true, 'source' => 'admin_queue'],
        ]);

        $this->finishStaffAction(true, 'Quarantine lifted — post is visible again.', '/admin/quarantine');
    }

    /** Backward-compat redirect — trash UI lives on `/board/mod-trash`. */
    public function trashQueue(array $params = []): void
    {
        $this->app->auth()->requireAdmin();

        $board = $this->app->moderationTrash()->trashBoard()
            ?? $this->app->moderationTrash()->ensureTrashBoard();
        Response::redirect('/board/' . (string) $board['slug']);
    }

    public function restoreTrashedPost(array $params): void
    {
        $this->app->auth()->requireAdmin();
        $this->validateStaffCsrf();
        $this->finishTrashRestore((int) ($params['id'] ?? 0));
    }

    public function purgeTrashedPost(array $params): void
    {
        $this->app->auth()->requireAdmin();
        $this->validateStaffCsrf();
        $this->finishTrashPurge((int) ($params['id'] ?? 0));
    }

    public function enableSiteLock(array $params = []): void
    {
        $this->app->auth()->requireAdmin();
        $this->validateStaffCsrf();

        $storagePath = (string) $this->app->config()->get('paths.storage');
        if (SiteLock::isLocked($storagePath)) {
            $this->finishStaffAction(false, 'Site is already in maintenance mode.', '/admin/maintenance');
        }

        $message = trim((string) $this->app->request()->input('message', ''));
        $actor = $this->app->auth()->user();
        $username = is_array($actor) ? (string) ($actor['username'] ?? '') : '';

        try {
            $result = SiteLock::enable($storagePath, $message, $username !== '' ? $username : null);
        } catch (\Throwable $e) {
            $this->finishStaffAction(false, 'Could not enable maintenance mode: ' . $e->getMessage(), '/admin/maintenance');
        }

        $this->recordMaintenanceAction('admin.site_lock_enable', true, [
            'message' => $message,
        ]);

        $this->app->session()->flash('site_lock_unlock_token', $result['unlock_token']);
        $this->app->session()->flash('site_lock_public_message', $message);
        $enabledUrl = '/admin/site-lock/enabled';

        if ($this->wantsJson()) {
            Response::json([
                'ok' => true,
                'site_lock' => true,
                'message' => 'Maintenance mode enabled. Copy your unlock token now — admin is unavailable until unlock.',
                'unlock_token' => $result['unlock_token'],
                'unlock_path' => $result['unlock_path'],
                'cli_hint' => SiteLock::cliUnlockHint(),
                'redirect' => $enabledUrl,
            ]);
        }

        Response::redirect($enabledUrl);
    }

    public function showSiteLockEnabled(array $params = []): void
    {
        $this->app->auth()->requireAdmin();

        $token = $this->app->session()->flash('site_lock_unlock_token');
        $message = $this->app->session()->flash('site_lock_public_message') ?? '';

        $this->app->render('admin/site_lock_enabled.html.twig', [
            'unlock_token' => $token,
            'message' => $message,
            'token_expired' => $token === null || $token === '',
            'site_lock_cli_hint' => SiteLock::cliUnlockHint(),
        ]);
    }

    public function createBackup(array $params = []): void
    {
        $this->app->auth()->requireAdmin();
        $this->validateStaffCsrf();

        $storagePath = (string) $this->app->config()->get('paths.storage');
        $result = SiteMaintenance::createBackup(
            $storagePath,
            (string) $this->app->config()->get('database.path'),
            dirname($storagePath) . '/config/local.php',
        );

        $this->recordMaintenanceAction('admin.backup', $result['ok'], [
            'path' => $result['path'],
        ]);

        $this->finishStaffAction($result['ok'], $result['message'], '/admin/maintenance');
    }

    public function clearCache(array $params = []): void
    {
        $this->app->auth()->requireAdmin();
        $this->validateStaffCsrf();

        $storagePath = (string) $this->app->config()->get('paths.storage');
        $cleared = SiteMaintenance::clearCaches($this->app->cache(), $storagePath);

        $message = sprintf(
            'Cleared %d page cache entr%s and %d Twig compile file%s.',
            $cleared['page_cache'],
            $cleared['page_cache'] === 1 ? 'y' : 'ies',
            $cleared['twig_files'],
            $cleared['twig_files'] === 1 ? '' : 's',
        );

        $this->recordMaintenanceAction('admin.cache_clear', true, $cleared);
        $this->finishStaffAction(true, $message, '/admin/maintenance');
    }

    public function reindexSearch(array $params = []): void
    {
        $this->app->auth()->requireAdmin();
        $this->validateStaffCsrf();

        $result = SiteMaintenance::reindexSearch($this->app->search());

        $this->recordMaintenanceAction('admin.search_reindex', $result['ok'], [
            'topics' => $result['topics'],
        ]);

        $this->finishStaffAction($result['ok'], $result['message'], '/admin/maintenance');
    }

    public function purgeAllModTrash(array $params = []): void
    {
        $this->app->auth()->requireAdmin();
        $this->validateStaffCsrf();

        $result = $this->app->moderationTrash()->purgeAllTrash();
        $purgedPosts = (int) $result['purged_posts'];

        $restoreTopicIds = [];
        $authorUserIds = [];
        foreach ($result['purged'] as $purgeResult) {
            $restoreTopicId = (int) $purgeResult['restore_topic_id'];
            if ($restoreTopicId > 0) {
                $restoreTopicIds[$restoreTopicId] = true;
            }

            $authorUserId = (int) $purgeResult['author_user_id'];
            if ($authorUserId > 0) {
                $authorUserIds[$authorUserId] = true;
            }
        }

        $cache = new \Latch\Core\BulkCacheCollector();
        foreach (array_keys($restoreTopicIds) as $restoreTopicId) {
            $topic = $this->app->topics()->findById($restoreTopicId);
            if ($topic === null) {
                continue;
            }

            $this->app->topics()->recalculateLastPostAt($restoreTopicId);
            $cache->addTopic($topic);
        }

        foreach (array_keys($restoreTopicIds) as $restoreTopicId) {
            $this->app->indexSearchTopic($restoreTopicId);
        }

        foreach (array_keys($authorUserIds) as $authorUserId) {
            $cache->addUser($authorUserId);
        }

        $trashBoard = $this->app->moderationTrash()->trashBoard();
        $trashBoardId = $trashBoard !== null ? (int) $trashBoard['id'] : 0;
        if ($trashBoardId > 0) {
            $cache->addBoard($trashBoardId);
        }

        $cache->flush($this->app);

        $actor = $this->app->auth()->user();
        $actorId = (int) ($actor['id'] ?? 0);
        $this->app->auditLog()->record(
            $actorId,
            'admin.mod_trash_purge_all',
            'board',
            $trashBoardId,
            $this->app->request()->ip(),
            [
                'purged_posts' => $purgedPosts,
                'purged_topics' => (int) $result['purged_topics'],
            ],
        );

        $this->recordMaintenanceAction('admin.mod_trash_purge_all', $purgedPosts > 0, [
            'purged_posts' => $purgedPosts,
            'purged_topics' => (int) $result['purged_topics'],
        ]);

        $message = $purgedPosts > 0
            ? "Permanently deleted {$purgedPosts} archived post(s) from moderation trash."
            : 'Moderation trash is already empty.';

        $this->finishStaffAction($purgedPosts > 0, $message, '/admin/maintenance');
    }

    public function users(array $params = []): void
    {
        $this->app->auth()->requireAdmin();

        $filter = (string) $this->app->request()->input('filter', 'members');
        if (!in_array($filter, ['members', 'staff', 'banned', 'deleted', 'all'], true)) {
            $filter = 'members';
        }

        $search = trim((string) $this->app->request()->input('q', ''));
        $searchError = $this->app->inputValidator()->searchQueryError($search);
        if ($searchError !== null) {
            $this->app->session()->flash('error', $searchError);
            Response::redirect('/admin/users');
        }

        $page = max(1, (int) $this->app->request()->input('page', 1));
        $result = $this->app->users()->listAdmin($filter, $search, $page);

        $users = $this->app->enrichUsersWithAvatars($result['users']);
        $staff = $filter === 'staff' ? [] : $this->app->enrichUsersWithAvatars($this->app->users()->listStaff());

        $this->app->render('admin/users.html.twig', [
            'users' => $users,
            'staff' => $staff,
            'filter' => $filter,
            'search' => $search,
            'page' => $result['page'],
            'per_page' => $result['per_page'],
            'total' => $result['total'],
            'total_pages' => (int) max(1, ceil($result['total'] / $result['per_page'])),
            'banned_count' => $this->app->users()->countBanned(),
            'deleted_count' => $this->app->users()->countDeleted(),
            'deleted_retain_days' => max(1, (int) $this->app->settings()->get('cron_deleted_user_retain_days', '30')),
            'report_categories' => $this->app->reportReasons()->categories(),
        ]);
    }

    public function showUser(array $params): void
    {
        $this->app->auth()->requireAdmin();

        $id = (int) ($params['id'] ?? 0);
        $target = $this->app->users()->findById($id);
        if ($target === null) {
            Response::notFound('User not found');
        }

        $this->app->render('admin/user_show.html.twig', [
            'target' => $target,
            'warnings' => $this->app->userWarnings()->listForUser($id),
            'warning_count' => $this->app->userWarnings()->countForUser($id),
            'is_banned' => $this->app->users()->isBanned($target),
            'is_deleted' => $this->app->users()->isDeleted($target),
            'report_categories' => $this->app->reportReasons()->categories(),
            'reputation' => $this->app->reputation()->profileViewForUser($target),
            'reputation_rank_labels' => ReputationService::RANK_LABELS,
        ]);
    }

    public function updateUserReputation(array $params): void
    {
        $this->app->auth()->requireAdmin();
        $this->validateStaffCsrf();

        $id = (int) ($params['id'] ?? 0);
        $redirect = '/admin/users/' . $id;
        $user = $this->app->users()->findById($id);
        if ($user === null) {
            Response::notFound('User not found');
        }

        if ((string) ($user['role'] ?? Auth::ROLE_MEMBER) !== Auth::ROLE_MEMBER) {
            $this->finishStaffAction(false, 'Reputation applies to members only.', $redirect);
        }

        $overrideRaw = $this->app->request()->input('rank_override', '');
        $override = null;
        if ($overrideRaw !== null && $overrideRaw !== '') {
            $override = max(1, min(5, (int) $overrideRaw));
        }

        $this->app->users()->setRankOverride($id, $override);
        $computed = $this->app->reputation()->computeForUser($id);
        $this->app->invalidateCacheTags([Cache::tagUser($id), Cache::tagSite()]);

        $actor = $this->app->auth()->user();
        $this->app->auditLog()->record(
            (int) ($actor['id'] ?? 0),
            'user.reputation_override',
            'user',
            $id,
            $this->app->request()->ip(),
            ['rank_override' => $override, 'rank' => $computed['rank']],
        );

        $message = $override === null
            ? 'Reputation override cleared — rank recalculated from activity.'
            : 'Rank locked to ' . $override . ' (' . ReputationService::rankLabel($override) . ').';
        if (!$this->wantsJson()) {
            $this->app->session()->flash('success', $message);
        }
        $this->finishStaffAction(true, $message, $redirect, [
            'reputation_rank' => $computed['rank'],
            'reputation_score' => $computed['score'],
        ]);
    }

    public function setRole(array $params): void
    {
        $this->app->auth()->requireAdmin();
        $this->validateStaffCsrf();

        $id = (int) ($params['id'] ?? 0);
        $role = (string) $this->app->request()->input('role', 'member');
        $redirect = '/admin/users/' . $id;

        if (!in_array($role, [Auth::ROLE_ADMIN, Auth::ROLE_MOD, Auth::ROLE_MEMBER], true)) {
            $this->finishStaffAction(false, 'Invalid role.', '/admin/users');
        }

        if ($id === self::FOUNDER_ID && $role !== Auth::ROLE_ADMIN) {
            $this->blockFounderAction('role_change', $id, ['attempted_role' => $role]);
            $this->finishStaffAction(false, 'The founder account cannot be demoted.', $redirect);
        }

        $user = $this->app->users()->findById($id);
        if ($user === null) {
            Response::notFound('User not found');
        }

        $current = $this->app->auth()->user();
        if ($current !== null && (int) $current['id'] === $id && $role !== Auth::ROLE_ADMIN) {
            $this->finishStaffAction(false, 'You cannot demote your own admin account.', $redirect);
        }

        $this->app->users()->updateRole($id, $role);
        if ($role === Auth::ROLE_MEMBER) {
            $this->app->reputation()->computeForUser($id);
        } else {
            $this->app->users()->clearReputation($id);
        }
        $this->app->invalidateCacheTags([Cache::tagUser($id), Cache::tagSite()]);
        $this->app->auditLog()->record(
            (int) ($current['id'] ?? 0),
            'user.role_change',
            'user',
            $id,
            $this->app->request()->ip(),
            ['role' => $role],
        );
        $this->finishStaffAction(true, 'User role updated.', '/admin/users', ['role' => $role]);
    }

    public function banUser(array $params): void
    {
        $this->app->auth()->requireAdmin();
        $this->validateStaffCsrf();

        $id = (int) ($params['id'] ?? 0);
        $current = $this->app->auth()->user();
        $redirect = '/admin/users/' . $id;

        if ($current !== null && (int) $current['id'] === $id) {
            $this->finishStaffAction(false, 'You cannot ban yourself.', $redirect);
        }

        if ($id === self::FOUNDER_ID) {
            $this->blockFounderAction('ban', $id);
            $this->finishStaffAction(false, 'The founder account cannot be banned.', $redirect);
        }

        $user = $this->app->users()->findById($id);
        if ($user === null) {
            Response::notFound('User not found');
        }

        if ($this->app->users()->isDeleted($user)) {
            $this->finishStaffAction(false, 'Deleted accounts cannot be banned.', $redirect);
        }

        [$until, $reason] = $this->parseBanInput();
        $this->app->users()->ban($id, $until, $reason);
        $this->app->userSessions()->revokeAllForUser($id);
        $this->app->auditLog()->record(
            (int) ($current['id'] ?? 0),
            'user.ban',
            'user',
            $id,
            $this->app->request()->ip(),
        );
        $this->app->securityLog()->log('ban', [
            'ip' => $this->app->request()->ip(),
            'user_id' => (int) ($current['id'] ?? 0),
            'target_type' => 'user',
            'target_id' => $id,
        ]);

        $fresh = $this->app->users()->findById($id) ?? $user;
        $this->finishStaffAction(true, 'User banned.', '/admin/users?filter=banned', array_merge(
            $this->userBanJsonFields($fresh),
            ['user_id' => $id],
        ));
    }

    public function unbanUser(array $params): void
    {
        $this->app->auth()->requireAdmin();
        $this->validateStaffCsrf();

        $id = (int) ($params['id'] ?? 0);
        $user = $this->app->users()->findById($id);
        if ($user === null) {
            Response::notFound('User not found');
        }

        $this->app->users()->unban($id);
        $current = $this->app->auth()->user();
        $this->app->auditLog()->record(
            (int) ($current['id'] ?? 0),
            'user.unban',
            'user',
            $id,
            $this->app->request()->ip(),
        );
        $this->app->securityLog()->log('unban', [
            'ip' => $this->app->request()->ip(),
            'user_id' => (int) ($current['id'] ?? 0),
            'target_type' => 'user',
            'target_id' => $id,
        ]);

        $fresh = $this->app->users()->findById($id) ?? $user;
        $this->finishStaffAction(true, 'User unbanned.', '/admin/users/' . $id, array_merge(
            $this->userBanJsonFields($fresh),
            ['user_id' => $id],
        ));
    }

    public function bulkBanUsers(array $params = []): void
    {
        $this->app->auth()->requireAdmin();
        $this->validateStaffCsrf();

        $ids = $this->parseBulkUserIds();
        if ($ids === []) {
            $this->finishStaffAction(false, 'Select at least one user.', '/admin/users');
        }

        $current = $this->app->auth()->user();
        $currentId = (int) ($current['id'] ?? 0);
        [$until, $reason] = $this->parseBanInput();
        $banned = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            if ($id === self::FOUNDER_ID || $id === $currentId) {
                $skipped++;
                continue;
            }

            $user = $this->app->users()->findById($id);
            if ($user === null || $this->app->users()->isDeleted($user) || $this->app->users()->isBanned($user)) {
                $skipped++;
                continue;
            }

            $this->app->users()->ban($id, $until, $reason);
            $this->app->userSessions()->revokeAllForUser($id);
            $this->app->auditLog()->record($currentId, 'user.ban', 'user', $id, $this->app->request()->ip());
            $this->app->securityLog()->log('ban', [
                'ip' => $this->app->request()->ip(),
                'user_id' => $currentId,
                'target_type' => 'user',
                'target_id' => $id,
                'bulk' => true,
            ]);
            $banned++;
        }

        $message = $banned > 0
            ? "Banned {$banned} user(s)." . ($skipped > 0 ? " Skipped {$skipped}." : '')
            : 'No users were banned.';

        $this->finishStaffAction($banned > 0, $message, '/admin/users?filter=banned');
    }

    public function bulkUnbanUsers(array $params = []): void
    {
        $this->app->auth()->requireAdmin();
        $this->validateStaffCsrf();

        $ids = $this->parseBulkUserIds();
        if ($ids === []) {
            $this->finishStaffAction(false, 'Select at least one user.', '/admin/users');
        }

        $current = $this->app->auth()->user();
        $currentId = (int) ($current['id'] ?? 0);
        $unbanned = 0;

        foreach ($ids as $id) {
            $user = $this->app->users()->findById($id);
            if ($user === null || !$this->app->users()->isBanned($user)) {
                continue;
            }

            $this->app->users()->unban($id);
            $this->app->auditLog()->record($currentId, 'user.unban', 'user', $id, $this->app->request()->ip());
            $this->app->securityLog()->log('unban', [
                'ip' => $this->app->request()->ip(),
                'user_id' => $currentId,
                'target_type' => 'user',
                'target_id' => $id,
                'bulk' => true,
            ]);
            $unbanned++;
        }

        $message = $unbanned > 0 ? "Unbanned {$unbanned} user(s)." : 'No banned users were selected.';
        $this->finishStaffAction($unbanned > 0, $message, '/admin/users');
    }

    public function boards(array $params = []): void
    {
        $this->app->auth()->requireAdmin();

        $boards = $this->app->boards()->all();
        foreach ($boards as &$board) {
            $board['topic_count'] = $this->app->boards()->countTopics((int) $board['id']);
        }
        unset($board);

        $iconKeys = $this->app->boardIcons()->keys();
        sort($iconKeys);
        $iconSvgs = [];
        foreach ($iconKeys as $key) {
            $iconSvgs[$key] = $this->app->boardIcons()->svg($key);
        }

        $this->app->render('admin/boards.html.twig', [
            'boards' => $boards,
            'board_icon_keys' => $iconKeys,
            'board_icon_svgs' => $iconSvgs,
            'board_acl_labels' => \Latch\Core\BoardAcl::ROLE_LABELS,
            'board_acl_read_options' => \Latch\Core\BoardAcl::optionsFor(\Latch\Core\BoardAcl::ACTION_READ),
            'board_acl_topic_options' => \Latch\Core\BoardAcl::optionsFor(\Latch\Core\BoardAcl::ACTION_TOPIC),
            'board_acl_reply_options' => \Latch\Core\BoardAcl::optionsFor(\Latch\Core\BoardAcl::ACTION_REPLY),
            'reputation_rank_labels' => ReputationService::RANK_LABELS,
        ]);
    }

    public function createBoard(array $params = []): void
    {
        $this->app->auth()->requireAdmin();

        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            Response::forbidden('Invalid form token.');
        }

        $name = trim((string) $this->app->request()->input('name', ''));
        $description = trim((string) $this->app->request()->input('description', ''));
        $aclRead = (string) $this->app->request()->input('acl_read', 'guest');
        $aclTopic = (string) $this->app->request()->input('acl_topic', 'member');
        $aclReply = (string) $this->app->request()->input('acl_reply', 'member');

        $boardError = $this->boardInputError($name, $description);
        if ($boardError !== null) {
            $this->app->session()->flash('error', $boardError);
            Response::redirect('/admin/boards');
        }

        $board = $this->app->boards()->create($name, $description, $aclRead, $aclTopic, $aclReply);
        $iconInput = trim((string) $this->app->request()->input('icon_key', ''));
        $iconKey = $iconInput !== '' && $this->app->boardIcons()->has($iconInput)
            ? $iconInput
            : $this->app->boardIcons()->suggestKey($name, (string) ($board['slug'] ?? ''));
        $this->app->boards()->setIconKey((int) $board['id'], $iconKey);
        $this->app->boards()->setMinRanks(
            (int) $board['id'],
            $this->parseMinRank($this->app->request()->input('min_rank_read')),
            $this->parseMinRank($this->app->request()->input('min_rank_topic')),
            $this->parseMinRank($this->app->request()->input('min_rank_reply')),
        );
        $board['icon_key'] = $iconKey;
        $this->app->bustBoardGuestCache((int) $board['id']);
        $this->app->auditLog()->record(
            (int) ($this->app->auth()->user()['id'] ?? 0),
            'board.create',
            'board',
            (int) $board['id'],
            $this->app->request()->ip(),
        );
        $this->app->session()->flash('success', 'Board "' . $board['name'] . '" created.');
        Response::redirect('/admin/boards');
    }

    public function updateBoard(array $params): void
    {
        $this->app->auth()->requireAdmin();

        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            Response::forbidden('Invalid form token.');
        }

        $id = (int) ($params['id'] ?? 0);
        $board = $this->app->boards()->findById($id);
        if ($board === null) {
            Response::notFound('Board not found');
        }

        $name = trim((string) $this->app->request()->input('name', ''));
        $slugInput = trim((string) $this->app->request()->input('slug', (string) $board['slug']));
        $description = trim((string) $this->app->request()->input('description', ''));
        $aclRead = (string) $this->app->request()->input('acl_read', (string) ($board['acl_read'] ?? 'guest'));
        $aclTopic = (string) $this->app->request()->input('acl_topic', (string) ($board['acl_topic'] ?? 'member'));
        $aclReply = (string) $this->app->request()->input('acl_reply', (string) ($board['acl_reply'] ?? 'member'));

        $boardError = $this->boardInputError($name, $description);
        if ($boardError !== null) {
            $this->app->session()->flash('error', $boardError);
            Response::redirect('/admin/boards');
        }

        $slug = Str::slug($slugInput);
        if ($slug === '') {
            $this->app->session()->flash('error', 'URL slug is required.');
            Response::redirect('/admin/boards');
        }

        if ($this->app->boards()->isSlugTaken($slug, $id)) {
            $this->app->session()->flash('error', 'That URL slug is already used by another board.');
            Response::redirect('/admin/boards');
        }

        $oldSlug = (string) $board['slug'];
        $this->app->boards()->update($id, $name, $slug, $description, $aclRead, $aclTopic, $aclReply);
        $this->app->boards()->setMinRanks(
            $id,
            $this->parseMinRank($this->app->request()->input('min_rank_read')),
            $this->parseMinRank($this->app->request()->input('min_rank_topic')),
            $this->parseMinRank($this->app->request()->input('min_rank_reply')),
        );

        $iconInput = trim((string) $this->app->request()->input('icon_key', ''));
        if ($iconInput === '') {
            $this->app->boards()->setIconKey($id, '');
        } elseif ($this->app->boardIcons()->has($iconInput)) {
            $this->app->boards()->setIconKey($id, $iconInput);
        }

        $this->app->bustBoardGuestCache($id);
        $this->app->auditLog()->record(
            (int) ($this->app->auth()->user()['id'] ?? 0),
            'board.update',
            'board',
            $id,
            $this->app->request()->ip(),
            ['name' => $name, 'slug' => $slug, 'old_slug' => $oldSlug],
        );

        $message = 'Board "' . $name . '" updated.';
        if ($slug !== $oldSlug) {
            $message .= ' Old links to /board/' . $oldSlug . ' will no longer work.';
        }
        $this->app->session()->flash('success', $message);
        Response::redirect('/admin/boards');
    }

    public function deleteBoard(array $params): void
    {
        $this->app->auth()->requireAdmin();

        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            Response::forbidden('Invalid form token.');
        }

        $id = (int) ($params['id'] ?? 0);
        $board = $this->app->boards()->findById($id);
        if ($board === null) {
            Response::notFound('Board not found');
        }

        if ($this->app->boards()->count() <= 1) {
            $this->app->session()->flash('error', 'You cannot delete the last board.');
            Response::redirect('/admin/boards');
        }

        $topicCount = $this->app->boards()->countTopics($id);
        $this->app->bustBoardGuestCache($id);
        $this->app->search()->removeBoard($id);
        $this->app->boards()->delete($id);
        $this->app->bustGuestPageCache();
        $this->app->auditLog()->record(
            (int) ($this->app->auth()->user()['id'] ?? 0),
            'board.delete',
            'board',
            $id,
            $this->app->request()->ip(),
            ['name' => $board['name'], 'topic_count' => $topicCount],
        );
        $this->app->session()->flash('success', 'Board "' . $board['name'] . '" removed.');
        Response::redirect('/admin/boards');
    }

    public function moveBoard(array $params): void
    {
        $this->app->auth()->requireAdmin();

        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            Response::forbidden('Invalid form token.');
        }

        $id = (int) ($params['id'] ?? 0);
        $board = $this->app->boards()->findById($id);
        if ($board === null) {
            Response::notFound('Board not found');
        }

        $direction = (string) $this->app->request()->input('direction', '');
        if (!$this->app->boards()->move($id, $direction)) {
            $this->app->session()->flash('error', 'Could not reorder board.');
            Response::redirect('/admin/boards');
        }

        $this->app->bustSiteCache();
        $this->app->auditLog()->record(
            (int) ($this->app->auth()->user()['id'] ?? 0),
            'board.reorder',
            'board',
            $id,
            $this->app->request()->ip(),
            ['direction' => $direction],
        );
        $this->app->session()->flash('success', 'Board order updated.');
        Response::redirect('/admin/boards');
    }

    public function settings(array $params = []): void
    {
        $this->app->auth()->requireAdmin();

        $this->app->render('admin/settings.html.twig', [
            'site_name' => $this->app->settings()->get('site_name', (string) $this->app->config()->get('site.name')),
            'site_tagline' => $this->app->settings()->get('site_tagline', (string) $this->app->config()->get('site.tagline')),
            'footer_about' => (string) $this->app->settings()->get('footer_about', ''),
            'members_only' => $this->app->membersOnly(),
            'allow_registration' => $this->app->allowRegistration(),
            'require_email_verification' => $this->app->requireEmailVerification(),
            'cache_enabled' => $this->app->cacheEnabled(),
            'cache_ttl_seconds' => $this->app->cacheTtlSeconds(),
            'report_quarantine_min_severity' => $this->app->settings()->get(
                'report_quarantine_min_severity',
                ReportReasons::SEVERITY_HIGH
            ),
            'report_quarantine_report_count' => (int) $this->app->settings()->get('report_quarantine_report_count', '0'),
            'use_gravatar' => $this->app->settings()->getBool('use_gravatar', true),
            'max_tags_per_topic' => $this->app->maxTagsPerTopic(),
            'mail' => $this->app->mail()->status(),
            'mail_msmtp_config_input' => (string) $this->app->settings()->get('mail_msmtp_config', ''),
            'gdpr_enabled' => $this->app->gdprEnabled(),
            'privacy_operator_name' => $this->app->settings()->get('privacy_operator_name', ''),
            'privacy_contact_email' => $this->app->privacyContactEmail(),
            'spam_honeypot_enabled' => $this->app->settings()->getBool('spam_honeypot_enabled', true),
            'spam_link_limit_new_users' => (int) $this->app->settings()->get('spam_link_limit_new_users', '2'),
            'spam_new_user_max_posts' => (int) $this->app->settings()->get('spam_new_user_max_posts', '5'),
            'spam_approval_queue_enabled' => $this->app->settings()->getBool('spam_approval_queue_enabled', true),
            'registration_honeypot_enabled' => $this->app->settings()->getBool('registration_honeypot_enabled', true),
            'registration_turnstile_enabled' => $this->app->settings()->getBool('registration_turnstile_enabled', true),
            'turnstile_configured' => (string) $this->app->config()->get('security.turnstile_site_key', '') !== ''
                && (string) $this->app->config()->get('security.turnstile_secret_key', '') !== '',
            'post_edit_window_minutes' => $this->app->postEditWindowMinutes(),
            'default_theme_mode' => $this->app->defaultThemeMode(),
            'default_locale' => $this->app->defaultLocale(),
            'locale_catalog' => \Latch\Core\Locale::catalog(),
            'email_notify_replies' => $this->app->settings()->getBool('email_notify_replies', true),
            'email_notify_mentions' => $this->app->settings()->getBool('email_notify_mentions', true),
            'email_notify_likes' => $this->app->settings()->getBool('email_notify_likes'),
            'email_notify_warnings' => $this->app->settings()->getBool('email_notify_warnings', true),
            'email_notify_staff' => $this->app->settings()->getBool('email_notify_staff', true),
            'mail_queue_enabled' => $this->app->settings()->getBool('mail_queue_enabled'),
            'mail_queue_pending' => $this->app->mailQueue()->pendingCount(),
            'anonymise_posts_on_delete' => $this->app->anonymisePostsOnDelete(),
            'oidc_google_enabled' => $this->app->settings()->getBool('oidc_google_enabled'),
            'oidc_github_enabled' => $this->app->settings()->getBool('oidc_github_enabled'),
            'oidc_google_configured' => $this->app->oidcConfig()->isConfigured('google'),
            'oidc_github_configured' => $this->app->oidcConfig()->isConfigured('github'),
            'oidc_google_redirect' => $this->app->oidcConfig()->redirectUri('google'),
            'oidc_github_redirect' => $this->app->oidcConfig()->redirectUri('github'),
            'reputation_weights' => $this->app->reputation()->configuredWeights(),
            'reputation_thresholds' => $this->app->reputation()->configuredThresholds(),
            'reputation_rank_labels' => ReputationService::RANK_LABELS,
            'brand_mode' => $this->app->siteBranding()->mode(),
            'brand_has_logo' => $this->app->siteBranding()->hasUploadedLogo(),
            'brand_logo_url' => $this->app->siteBranding()->logoUrl(),
            'brand_has_logo_dark' => $this->app->siteBranding()->hasUploadedLogoDark(),
            'brand_logo_dark_url' => $this->app->siteBranding()->logoDarkUrl(),
            'brand_has_favicon' => $this->app->siteBranding()->hasFavicon(),
            'brand_favicon_url' => $this->app->siteBranding()->faviconUrl(),
            'brand_has_og' => $this->app->siteBranding()->hasOgImage(),
            'brand_og_url' => $this->app->siteBranding()->ogUrl(),
        ]);
    }

    public function saveSettings(array $params = []): void
    {
        $this->app->auth()->requireAdmin();

        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            Response::forbidden('Invalid form token.');
        }

        $siteName = trim((string) $this->app->request()->input('site_name', ''));
        $siteTagline = trim((string) $this->app->request()->input('site_tagline', ''));
        $footerAbout = (string) $this->app->request()->input('footer_about', '');
        $validator = $this->app->inputValidator();

        if ($siteName !== '') {
            $siteNameError = $validator->siteNameError($siteName);
            if ($siteNameError !== null) {
                $this->app->session()->flash('error', $siteNameError);
                Response::redirect('/admin/settings');
            }
            $this->app->settings()->set('site_name', $siteName);
        }

        $taglineError = $validator->siteTaglineError($siteTagline);
        if ($taglineError !== null) {
            $this->app->session()->flash('error', $taglineError);
            Response::redirect('/admin/settings');
        }

        $this->app->settings()->set('site_tagline', $siteTagline);

        $footerAboutError = $validator->footerAboutError($footerAbout);
        if ($footerAboutError !== null) {
            $this->app->session()->flash('error', $footerAboutError);
            Response::redirect('/admin/settings');
        }

        $this->app->settings()->set('footer_about', $this->normalizeFooterAbout($footerAbout));

        $brandModeError = $this->app->siteBranding()->setMode(
            (string) $this->app->request()->input('brand_mode', SiteBranding::MODE_CUSTOM),
        );
        if ($brandModeError !== null) {
            $this->app->session()->flash('error', $brandModeError);
            Response::redirect('/admin/settings');
        }

        $brandError = $this->saveBrandingUploads();
        if ($brandError !== null) {
            $this->app->session()->flash('error', $brandError);
            Response::redirect('/admin/settings');
        }

        $wasMembersOnly = $this->app->membersOnly();
        $this->app->settings()->setBool('members_only', $this->app->request()->input('members_only') === '1');
        $this->app->settings()->setBool('allow_registration', $this->app->request()->input('allow_registration') === '1');
        $this->app->settings()->setBool('require_email_verification', $this->app->request()->input('require_email_verification') === '1');
        $this->app->settings()->setBool('cache_enabled', $this->app->request()->input('cache_enabled') === '1');

        $ttl = (int) $this->app->request()->input('cache_ttl_seconds', 120);
        $this->app->settings()->set('cache_ttl_seconds', (string) max(30, $ttl));

        $minSeverity = ReportReasons::normalizeSeverity(
            (string) $this->app->request()->input('report_quarantine_min_severity', ReportReasons::SEVERITY_HIGH)
        );
        $this->app->settings()->set('report_quarantine_min_severity', $minSeverity);
        $this->app->settings()->set(
            'report_quarantine_report_count',
            (string) max(0, (int) $this->app->request()->input('report_quarantine_report_count', 0))
        );
        $this->app->settings()->setBool('use_gravatar', $this->app->request()->input('use_gravatar') === '1');
        $this->app->settings()->set(
            'max_tags_per_topic',
            (string) max(1, min(20, (int) $this->app->request()->input('max_tags_per_topic', 5))),
        );
        $this->app->settings()->set(
            'post_edit_window_minutes',
            (string) max(0, min(10080, (int) $this->app->request()->input('post_edit_window_minutes', 60))),
        );

        $this->app->settings()->setBool('mail_enabled', $this->app->request()->input('mail_enabled') === '1');
        $transport = (string) $this->app->request()->input('mail_transport', 'msmtp');
        if (!in_array($transport, ['msmtp', 'mail'], true)) {
            $transport = 'msmtp';
        }
        $this->app->settings()->set('mail_transport', $transport);

        $fromEmail = trim((string) $this->app->request()->input('mail_from_email', ''));
        if ($fromEmail !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $this->app->settings()->set('mail_from_email', $fromEmail);
        }

        $fromName = trim((string) $this->app->request()->input('mail_from_name', ''));
        if ($fromName !== '') {
            $this->app->settings()->set('mail_from_name', $fromName);
        }

        $msmtpConfig = trim((string) $this->app->request()->input('mail_msmtp_config', ''));
        $this->app->settings()->set('mail_msmtp_config', $msmtpConfig);

        $this->app->settings()->setBool('spam_honeypot_enabled', $this->app->request()->input('spam_honeypot_enabled') === '1');
        $this->app->settings()->set(
            'spam_link_limit_new_users',
            (string) max(0, min(20, (int) $this->app->request()->input('spam_link_limit_new_users', 2))),
        );
        $this->app->settings()->set(
            'spam_new_user_max_posts',
            (string) max(0, min(100, (int) $this->app->request()->input('spam_new_user_max_posts', 5))),
        );
        $this->app->settings()->setBool(
            'spam_approval_queue_enabled',
            $this->app->request()->input('spam_approval_queue_enabled') === '1',
        );
        $this->app->settings()->setBool(
            'registration_honeypot_enabled',
            $this->app->request()->input('registration_honeypot_enabled') === '1',
        );
        if ((string) $this->app->config()->get('security.turnstile_site_key', '') !== '') {
            $this->app->settings()->setBool(
                'registration_turnstile_enabled',
                $this->app->request()->input('registration_turnstile_enabled') === '1',
            );
        }

        $this->app->settings()->setBool('gdpr_enabled', $this->app->request()->input('gdpr_enabled') === '1');
        $this->app->settings()->set(
            'privacy_operator_name',
            trim((string) $this->app->request()->input('privacy_operator_name', '')),
        );
        $privacyEmail = trim((string) $this->app->request()->input('privacy_contact_email', ''));
        if ($privacyEmail === '' || filter_var($privacyEmail, FILTER_VALIDATE_EMAIL)) {
            $this->app->settings()->set('privacy_contact_email', $privacyEmail);
        }

        $defaultTheme = \Latch\Core\ThemeMode::normalizePreference(
            (string) $this->app->request()->input('default_theme_mode', \Latch\Core\ThemeMode::SYSTEM)
        );
        $this->app->settings()->set('default_theme_mode', $defaultTheme);

        $defaultLocale = \Latch\Core\Locale::normalize(
            (string) $this->app->request()->input('default_locale', \Latch\Core\Locale::DEFAULT)
        );
        $this->app->settings()->set('default_locale', $defaultLocale);
        $this->app->bustGuestPageCache();

        $this->app->settings()->setBool('email_notify_replies', $this->app->request()->input('email_notify_replies') === '1');
        $this->app->settings()->setBool('email_notify_mentions', $this->app->request()->input('email_notify_mentions') === '1');
        $this->app->settings()->setBool('email_notify_likes', $this->app->request()->input('email_notify_likes') === '1');
        $this->app->settings()->setBool('email_notify_warnings', $this->app->request()->input('email_notify_warnings') === '1');
        $this->app->settings()->setBool('email_notify_staff', $this->app->request()->input('email_notify_staff') === '1');
        $this->app->settings()->setBool('mail_queue_enabled', $this->app->request()->input('mail_queue_enabled') === '1');
        $this->app->settings()->setBool(
            'anonymise_posts_on_delete',
            $this->app->request()->input('anonymise_posts_on_delete') === '1',
        );

        if ($this->app->oidcConfig()->isConfigured('google')) {
            $this->app->settings()->setBool(
                'oidc_google_enabled',
                $this->app->request()->input('oidc_google_enabled') === '1',
            );
        }
        if ($this->app->oidcConfig()->isConfigured('github')) {
            $this->app->settings()->setBool(
                'oidc_github_enabled',
                $this->app->request()->input('oidc_github_enabled') === '1',
            );
        }

        $weightsInput = [];
        foreach (['topic', 'reply', 'dislike', 'read', 'watch', 'active_day', 'like_cap', 'warning_penalty'] as $key) {
            $weightsInput[$key] = $this->app->request()->input('reputation_weight_' . $key);
        }
        $thresholdsInput = [];
        for ($rank = 2; $rank <= 5; $rank++) {
            $thresholdsInput[$rank] = [
                'min_score' => $this->app->request()->input('reputation_threshold_' . $rank . '_score'),
                'min_age_days' => $this->app->request()->input('reputation_threshold_' . $rank . '_age'),
            ];
        }
        $this->app->reputation()->saveAdminSettings($weightsInput, $thresholdsInput);

        if ($wasMembersOnly !== $this->app->membersOnly()) {
            $this->app->bustGuestPageCache();
        } else {
            $this->app->bustSiteCache();
        }
        $this->app->auditLog()->record(
            (int) ($this->app->auth()->user()['id'] ?? 0),
            'settings.update',
            'site',
            null,
            $this->app->request()->ip(),
        );

        $this->app->session()->flash('success', 'Settings saved.');
        Response::redirect('/admin/settings');
    }

    public function reports(array $params = []): void
    {
        $this->app->auth()->requireMod();

        $this->app->render('admin/reports.html.twig', [
            'reports' => $this->app->reports()->openReports(),
            'reason_labels' => $this->app->reportReasons()->categories(),
        ]);
    }

    public function reportQueueFeed(array $params = []): void
    {
        $this->app->auth()->requireMod();

        Response::json([
            'ok' => true,
            'queue' => $this->app->reportQueueSummary(),
        ]);
    }

    public function approval(array $params = []): void
    {
        $this->app->auth()->requireMod();

        $this->app->render('admin/approval.html.twig', [
            'pending_posts' => $this->app->posts()->listPending(),
        ]);
    }

    public function approvePost(array $params): void
    {
        $this->app->auth()->requireMod();
        $this->validateStaffCsrf();

        $id = (int) ($params['id'] ?? 0);
        $post = $this->app->posts()->findById($id);
        if ($post === null || ($post['approval_status'] ?? '') !== \Latch\Models\PostRepository::APPROVAL_PENDING) {
            $this->finishStaffAction(false, 'Post not found or not pending.', '/admin/approval');
        }

        if (!$this->app->posts()->approve($id)) {
            $this->finishStaffAction(false, 'Could not approve post.', '/admin/approval');
        }

        $topic = $this->app->topics()->findById((int) $post['topic_id']);
        if ($topic !== null) {
            $this->app->topics()->touchLastPost((int) $topic['id']);
            $this->app->indexSearchTopic((int) $topic['id']);
            $this->app->invalidateCacheTags([
                Cache::tagTopic((int) $topic['id']),
                Cache::tagBoard((int) $topic['board_id']),
                Cache::tagUser((int) $post['user_id']),
                Cache::tagSite(),
            ]);
        }

        $staff = $this->app->auth()->user();
        $this->app->auditLog()->record(
            (int) ($staff['id'] ?? 0),
            'post.approve',
            'post',
            $id,
            $this->app->request()->ip(),
        );
        $this->app->enqueueReputationUpdate((int) $post['user_id']);

        if ($staff !== null && $topic !== null) {
            $this->app->notificationService()->onStaffPostAction(
                'post.approve',
                $post,
                $topic,
                $staff,
                'Your post in "' . $this->topicTitleLabel($topic) . '" was approved by @' . $staff['username'],
            );
            $author = $this->app->users()->findById((int) $post['user_id']);
            if ($author !== null) {
                $approvedPost = $post;
                $approvedPost['approval_status'] = \Latch\Models\PostRepository::APPROVAL_APPROVED;
                $this->app->notificationService()->onReply($topic, $approvedPost, $author);
            }
        }

        $this->finishStaffAction(true, 'Post approved.', '/admin/approval', ['post_id' => $id]);
    }

    public function rejectPost(array $params): void
    {
        $this->app->auth()->requireMod();
        $this->validateStaffCsrf();

        $id = (int) ($params['id'] ?? 0);
        $post = $this->app->posts()->findById($id);
        if ($post === null || ($post['approval_status'] ?? '') !== \Latch\Models\PostRepository::APPROVAL_PENDING) {
            $this->finishStaffAction(false, 'Post not found or not pending.', '/admin/approval');
        }

        if (!$this->app->posts()->reject($id)) {
            $this->finishStaffAction(false, 'Could not reject post.', '/admin/approval');
        }

        $topic = $this->app->topics()->findById((int) $post['topic_id']);
        if ($topic !== null) {
            $this->app->indexSearchTopic((int) $topic['id']);
            $this->app->invalidateCacheTags([
                Cache::tagTopic((int) $topic['id']),
                Cache::tagBoard((int) $topic['board_id']),
                Cache::tagUser((int) $post['user_id']),
                Cache::tagSite(),
            ]);
        }

        $staff = $this->app->auth()->user();
        $this->app->auditLog()->record(
            (int) ($staff['id'] ?? 0),
            'post.reject',
            'post',
            $id,
            $this->app->request()->ip(),
        );

        if ($staff !== null && $topic !== null) {
            $this->app->notificationService()->onStaffPostAction(
                'post.reject',
                $post,
                $topic,
                $staff,
                'Your post in "' . $this->topicTitleLabel($topic) . '" was rejected by @' . $staff['username'],
            );
        }

        $this->finishStaffAction(true, 'Post rejected.', '/admin/approval', ['post_id' => $id]);
    }

    public function triageReport(array $params): void
    {
        $this->app->auth()->requireMod();
        $this->validateStaffCsrf();

        $id = (int) ($params['id'] ?? 0);
        $report = $this->app->reports()->findById($id);
        if ($report === null || $report['status'] !== 'open') {
            $this->finishStaffAction(false, 'Report not found or already closed.', '/admin/reports');
        }

        $action = (string) $this->app->request()->input('action', 'clear');
        $staff = $this->app->auth()->user();
        $staffId = (int) ($staff['id'] ?? 0);
        $ip = $this->app->request()->ip();

        [$success, $message] = match ($action) {
            'clear' => $this->triageClear($report, $staffId, $ip),
            'warn' => $this->triageWarn($report, $staffId, $ip),
            'delete' => $this->triageDeletePost($report, $staffId, $ip),
            'ban' => $this->triageBan($report, $staffId, $ip),
            default => [false, 'Unknown action.'],
        };

        $this->finishStaffAction($success, $message, '/admin/reports', ['report_id' => $id]);
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function triageClear(array $report, int $staffId, string $ip): array
    {
        $this->app->reports()->resolveOpenForTarget(
            (string) $report['target_type'],
            (int) $report['target_id'],
            $staffId,
            'dismissed',
            'clear',
        );

        if ($report['target_type'] === 'post') {
            $this->app->reportQuarantine()->maybeLiftForPost((int) $report['target_id'], $ip, $staffId);
        }

        $this->app->auditLog()->record($staffId, 'report.clear', 'report', (int) $report['id'], $ip);

        return [true, 'Report cleared.'];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function triageWarn(array $report, int $staffId, string $ip): array
    {
        $userId = $this->resolveTargetUserId($report);
        if ($userId === null) {
            return [false, 'Cannot identify user to warn.'];
        }

        $reason = $this->app->reportReasons()->label((string) $report['reason_code']);
        $this->app->userWarnings()->issue($userId, $staffId, $reason, (int) $report['id']);
        $this->resolveReportTarget($report, $staffId, $ip, 'warn');

        $staff = $this->app->auth()->user();
        if ($staff !== null) {
            if ($this->app->directMessages()->isAvailable()) {
                $this->app->messages()->deliverStaffWarning($userId, $staff, $reason, (int) $report['id']);
            } else {
                $this->app->notificationService()->onUserWarned($userId, $staff, $reason, (int) $report['id']);
            }
        }

        $this->app->auditLog()->record($staffId, 'report.warn', 'user', $userId, $ip, ['report_id' => $report['id']]);
        $this->app->enqueueReputationUpdate($userId);

        return [true, 'Warning issued and report resolved.'];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function triageDeletePost(array $report, int $staffId, string $ip): array
    {
        if ($report['target_type'] !== 'post') {
            return [false, 'Delete only applies to post reports.'];
        }

        $postId = (int) $report['target_id'];
        $post = $this->app->posts()->findById($postId);
        if ($post !== null && $post['deleted_at'] === null && ($post['trashed_at'] ?? null) === null) {
            if ($this->app->moderationTrash()->archivePost($postId, $staffId) === null) {
                $this->app->posts()->softDelete($postId);
            }
            $this->app->topics()->recalculateLastPostAt((int) $post['topic_id']);
            $this->app->indexSearchTopic((int) $post['topic_id']);
            $this->bustTopicCacheForPost($post);
        }

        $this->resolveReportTarget($report, $staffId, $ip, 'delete_post');
        $this->app->posts()->liftQuarantine($postId);

        $this->app->auditLog()->record($staffId, 'report.delete_post', 'post', $postId, $ip);

        return [true, 'Post moved to moderation trash and reports resolved.'];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function triageBan(array $report, int $staffId, string $ip): array
    {
        $userId = $this->resolveTargetUserId($report);
        if ($userId === null) {
            return [false, 'Cannot identify user to ban.'];
        }

        if ($userId === self::FOUNDER_ID) {
            $this->blockFounderAction('ban', $userId);

            return [false, 'The founder account cannot be banned.'];
        }

        if ($staffId === $userId) {
            return [false, 'You cannot ban yourself.'];
        }

        [$until, $reason] = $this->parseBanInput();
        $this->app->users()->ban($userId, $until, $reason);
        $this->app->userSessions()->revokeAllForUser($userId);
        $this->resolveReportTarget($report, $staffId, $ip, 'ban');

        if ($report['target_type'] === 'post') {
            $this->app->posts()->liftQuarantine((int) $report['target_id']);
        }

        $this->app->securityLog()->log('ban', [
            'ip' => $ip,
            'user_id' => $staffId,
            'target_type' => 'user',
            'target_id' => $userId,
        ]);
        $this->app->auditLog()->record($staffId, 'report.ban', 'user', $userId, $ip, ['report_id' => $report['id']]);

        return [true, 'User banned and reports resolved.'];
    }

    private function resolveReportTarget(array $report, int $staffId, string $ip, string $action): void
    {
        $this->app->reports()->resolveOpenForTarget(
            (string) $report['target_type'],
            (int) $report['target_id'],
            $staffId,
            'resolved',
            $action,
        );

        if ($report['target_type'] === 'post') {
            $this->app->reportQuarantine()->maybeLiftForPost((int) $report['target_id'], $ip, $staffId);
        }
    }

    private function resolveTargetUserId(array $report): ?int
    {
        if ($report['target_type'] === 'user') {
            return (int) $report['target_id'];
        }

        if ($report['target_type'] === 'post') {
            $post = $this->app->posts()->findById((int) $report['target_id']);

            return $post !== null ? (int) $post['user_id'] : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $post
     */
    private function bustTopicCacheForPost(array $post): void
    {
        $topic = $this->app->topics()->findById((int) $post['topic_id']);
        if ($topic === null) {
            return;
        }

        $this->app->invalidateCacheTags([
            Cache::tagTopic((int) $topic['id']),
            Cache::tagBoard((int) $topic['board_id']),
            Cache::tagSite(),
        ]);
    }

    public function auditLog(array $params = []): void
    {
        $this->app->auth()->requireAdmin();

        $this->app->render('admin/audit.html.twig', [
            'entries' => $this->app->auditLog()->recent(200),
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function recordMaintenanceAction(string $action, bool $success, array $metadata = []): void
    {
        $actor = $this->app->auth()->user();
        $this->app->auditLog()->record(
            (int) ($actor['id'] ?? 0),
            $action,
            'site',
            null,
            $this->app->request()->ip(),
            array_merge($metadata, ['success' => $success]),
        );
    }

    private function validateStaffCsrf(): void
    {
        if ($this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            return;
        }

        if ($this->wantsJson()) {
            Response::json(['ok' => false, 'message' => 'Invalid form token.'], 403);
        }

        Response::forbidden('Invalid form token.');
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function userBanJsonFields(array $user): array
    {
        return [
            'is_banned' => $this->app->users()->isBanned($user),
            'is_deleted' => $this->app->users()->isDeleted($user),
            'banned_at' => $user['banned_at'] ?? null,
            'banned_until' => $user['banned_until'] ?? null,
            'ban_reason' => $user['ban_reason'] ?? null,
            'deleted_at' => $user['deleted_at'] ?? null,
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string} until, reason
     */
    /**
     * @return list<int>
     */
    private function parseBulkUserIds(): array
    {
        $raw = $this->app->request()->input('user_ids', []);
        if (!is_array($raw)) {
            $raw = explode(',', (string) $raw);
        }

        $ids = [];
        foreach ($raw as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function parseBanInput(): array
    {
        $duration = (string) $this->app->request()->input('ban_duration', 'permanent');
        $until = $this->banUntilFromDuration($duration);
        $reason = trim((string) $this->app->request()->input('ban_reason', ''));
        if ($reason === '') {
            $reason = null;
        } else {
            $reason = mb_substr($reason, 0, 500);
        }

        return [$until, $reason];
    }

    private function banUntilFromDuration(string $duration): ?string
    {
        if ($duration === 'custom') {
            $days = max(1, min(365, (int) $this->app->request()->input('ban_custom_days', 1)));

            return gmdate('c', time() + ($days * 86400));
        }

        return match ($duration) {
            '1d' => gmdate('c', time() + 86400),
            '7d' => gmdate('c', time() + 7 * 86400),
            '30d' => gmdate('c', time() + 30 * 86400),
            default => null,
        };
    }

    private function blockFounderAction(string $action, int $targetId, array $meta = []): void
    {
        $actor = $this->app->auth()->user();
        $this->app->auditLog()->record(
            (int) ($actor['id'] ?? 0),
            'founder.blocked_' . $action,
            'user',
            $targetId,
            $this->app->request()->ip(),
            $meta,
        );
        $this->app->securityLog()->log('founder_block', [
            'ip' => $this->app->request()->ip(),
            'user_id' => (int) ($actor['id'] ?? 0),
            'target_type' => 'user',
            'target_id' => $targetId,
            'meta' => $meta,
        ]);
    }

    private function boardInputError(string $name, string $description): ?string
    {
        $validator = $this->app->inputValidator();

        return $validator->boardNameError($name)
            ?? $validator->boardDescriptionError($description);
    }

    private function parseMinRank(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $rank = (int) $value;

        return ($rank >= 1 && $rank <= 5) ? $rank : null;
    }

    /**
     * @param array<string, mixed> $topic
     */
    private function topicTitleLabel(array $topic): string
    {
        $title = (string) ($topic['title'] ?? '');

        return mb_strlen($title) <= 80 ? $title : mb_substr($title, 0, 79) . '…';
    }

    protected function recordTrashRestore(int $postId, int $topicId): void
    {
        $actor = $this->app->auth()->user();
        $this->app->auditLog()->record(
            (int) ($actor['id'] ?? 0),
            'post.trash_restore',
            'post',
            $postId,
            $this->app->request()->ip(),
            ['topic_id' => $topicId],
        );
    }

    protected function recordTrashPurge(int $postId, int $topicId): void
    {
        $actor = $this->app->auth()->user();
        $this->app->auditLog()->record(
            (int) ($actor['id'] ?? 0),
            'post.trash_purge',
            'post',
            $postId,
            $this->app->request()->ip(),
            ['topic_id' => $topicId],
        );
    }

    public function plugins(array $params = []): void
    {
        $this->app->auth()->requireAdmin();

        $registry = $this->app->plugins();
        $auditService = $this->pluginAuditService();
        $latchVersion = $this->app->latchVersion();
        $rows = [];

        foreach ($registry->listWithStatus() as $row) {
            $manifest = $row['manifest'];
            $auditResult = $auditService->getOrScan($manifest, false);

            $rows[] = [
                'manifest' => $manifest,
                'enabled' => $row['enabled'],
                'compatible' => $manifest->isCompatibleWith($latchVersion),
                'has_settings' => $manifest->hasSettingsUi(),
                'audit' => $this->auditRowForTemplate($auditResult['report'], $auditResult['scanned_at'], $auditResult['from_cache']),
            ];
        }

        $installedSlugs = array_map(
            static fn (array $row): string => $row['manifest']->slug,
            $rows,
        );
        $catalog = $this->pluginCatalog();
        $catalogData = $catalog->load();
        $catalogRows = [];
        $catalogError = null;

        if ($catalogData === null) {
            $catalogError = 'Could not load the plugin catalog. Check server outbound HTTPS or install via CLI.';
        } else {
            foreach ($catalog->availableEntries($installedSlugs) as $entry) {
                $catalogRows[] = [
                    'slug' => $entry->slug,
                    'name' => $entry->name,
                    'version' => $entry->version,
                    'min_latch_version' => $entry->minLatchVersion,
                    'summary' => $entry->summary,
                    'hooks' => $entry->hooks,
                    'compatible' => $entry->isCompatibleWith($latchVersion),
                ];
            }
        }

        $activeTab = trim((string) ($this->app->request()->input('tab', 'installed')));
        if (!in_array($activeTab, ['catalog', 'installed'], true)) {
            $activeTab = 'installed';
        }

        $this->app->render('admin/plugins.html.twig', [
            'plugins' => $rows,
            'latch_version' => $latchVersion,
            'catalog_release' => $catalogData['release'] ?? null,
            'catalog_plugins' => $catalogRows,
            'catalog_error' => $catalogError,
            'active_tab' => $activeTab,
            'installed_count' => count($rows),
            'catalog_count' => count($catalogRows),
        ]);
    }

    private function pluginsAdminUrl(string $tab = 'installed'): string
    {
        return '/admin/plugins?tab=' . $tab;
    }

    public function installCatalogPlugin(array $params = []): void
    {
        $this->app->auth()->requireAdmin();
        $this->validateStaffCsrf();

        $slug = trim((string) ($this->app->request()->input('slug') ?? ''));
        if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug)) {
            $this->finishStaffAction(false, 'Invalid plugin slug.', $this->pluginsAdminUrl('catalog'));
        }

        if ($this->findPluginManifest($slug) !== null) {
            $this->finishStaffAction(false, 'Plugin is already installed.', $this->pluginsAdminUrl('catalog'));
        }

        $catalog = $this->pluginCatalog();
        $catalogData = $catalog->load(true);
        if ($catalogData === null) {
            $this->finishStaffAction(false, 'Could not load the plugin catalog.', $this->pluginsAdminUrl('catalog'));
        }

        $entry = $this->findCatalogEntry($catalogData['entries'], $slug);
        if ($entry === null) {
            $this->finishStaffAction(false, 'Plugin not found in catalog.', $this->pluginsAdminUrl('catalog'));
        }

        $latchVersion = $this->app->latchVersion();
        if (!$entry->isCompatibleWith($latchVersion)) {
            $this->finishStaffAction(
                false,
                "Plugin requires Latch >= {$entry->minLatchVersion}.",
                $this->pluginsAdminUrl('catalog'),
            );
        }

        if (version_compare($latchVersion, $catalogData['latch_min_version'], '<')) {
            $this->finishStaffAction(
                false,
                "Catalog requires Latch >= {$catalogData['latch_min_version']}.",
                $this->pluginsAdminUrl('catalog'),
            );
        }

        try {
            $manifest = $this->pluginCatalogInstaller()->install($entry, $catalogData['release']);
        } catch (\Throwable $e) {
            $message = trim($e->getMessage());
            if ($message === '') {
                $message = 'Plugin install failed.';
            }

            $this->finishStaffAction(false, $message, $this->pluginsAdminUrl('catalog'));
        }

        $actor = $this->app->auth()->user();
        $this->app->auditLog()->record(
            (int) ($actor['id'] ?? 0),
            'plugin.install',
            'plugin',
            null,
            $this->app->request()->ip(),
            [
                'slug' => $manifest->slug,
                'version' => $manifest->version,
                'source' => 'catalog',
                'release' => $catalogData['release'],
            ],
        );

        $this->finishStaffAction(
            true,
            "Installed {$manifest->name} v{$manifest->version} (disabled). Enable it when ready.",
            $this->pluginsAdminUrl('installed'),
        );
    }

    public function pluginSettings(array $params): void
    {
        $this->app->auth()->requireAdmin();

        $manifest = $this->requirePluginSettingsManifest($params);
        if ($manifest === null) {
            return;
        }

        $storagePath = (string) $this->app->config()->get('paths.storage');
        $store = PluginSettingsStore::forPlugin($manifest, $storagePath);
        $values = $store->all();
        $form = (new PluginSettingsForm())->build($manifest->settingsSchema, $values, $this->app->config());

        $this->app->render('admin/plugin_settings.html.twig', [
            'manifest' => $manifest,
            'settings_fields' => $form['settings_fields'],
            'secret_fields' => $form['secret_fields'],
        ]);
    }

    public function savePluginSettings(array $params): void
    {
        $this->app->auth()->requireAdmin();
        $this->validateStaffCsrf();

        $manifest = $this->requirePluginSettingsManifest($params);
        if ($manifest === null) {
            return;
        }

        $slug = $manifest->slug;
        $redirect = '/admin/plugins/' . $slug . '/settings';
        $result = (new PluginSettingsValidator())->validate($_POST, $manifest->settingsSchema);
        if ($result['error'] !== null) {
            $this->finishStaffAction(false, $result['error'], $redirect);
        }

        try {
            $store = PluginSettingsStore::forPlugin($manifest, (string) $this->app->config()->get('paths.storage'));
            $store->save($result['values']);
        } catch (\Throwable $e) {
            $this->finishStaffAction(false, 'Could not save plugin settings.', $redirect);
        }

        $actor = $this->app->auth()->user();
        $this->app->auditLog()->record(
            (int) ($actor['id'] ?? 0),
            'plugin.settings',
            'plugin',
            null,
            $this->app->request()->ip(),
            ['slug' => $slug],
        );

        $this->app->session()->flash('success', 'Plugin settings saved.');
        $this->finishStaffAction(true, 'Plugin settings saved.', $redirect);
    }

    public function enablePlugin(array $params): void
    {
        $this->app->auth()->requireAdmin();
        $this->validateStaffCsrf();

        $slug = trim((string) ($params['slug'] ?? ''));
        $manifest = $this->findPluginManifest($slug);
        if ($manifest === null) {
            $this->finishStaffAction(false, 'Plugin not found.', $this->pluginsAdminUrl('installed'));
        }

        if (!$manifest->isCompatibleWith($this->app->latchVersion())) {
            $this->finishStaffAction(
                false,
                "Plugin requires Latch >= {$manifest->minLatchVersion}.",
                $this->pluginsAdminUrl('installed'),
            );
        }

        $auditResult = $this->pluginAuditService()->getOrScan($manifest, true);
        $report = $auditResult['report'];
        if (!$report->enableAllowed()) {
            $this->finishStaffAction(
                false,
                $this->auditFailureMessage($report),
                $this->pluginsAdminUrl('installed'),
            );
        }

        try {
            $this->app->pluginDatabaseManager()->migrate($manifest);
        } catch (\Throwable $e) {
            error_log('Plugin database migration failed for ' . $slug . ': ' . $e->getMessage());
            $this->finishStaffAction(
                false,
                'Plugin database migration failed. Check server logs for details.',
                $this->pluginsAdminUrl('installed'),
            );
        }

        $registry = $this->app->plugins();
        $enabled = $registry->enabledSlugs();
        if (!in_array($slug, $enabled, true)) {
            $enabled[] = $slug;
            $registry->setEnabledSlugs($enabled);
        }

        $actor = $this->app->auth()->user();
        $this->app->auditLog()->record(
            (int) ($actor['id'] ?? 0),
            'plugin.enable',
            'plugin',
            null,
            $this->app->request()->ip(),
            ['slug' => $slug, 'version' => $manifest->version],
        );

        $this->app->invalidatePluginCache($slug);

        $storagePath = (string) $this->app->config()->get('paths.storage');
        SiteMaintenance::clearCaches($this->app->cache(), $storagePath);

        $this->finishStaffAction(true, "Enabled plugin: {$manifest->name}.", $this->pluginsAdminUrl('installed'));
    }

    public function disablePlugin(array $params): void
    {
        $this->app->auth()->requireAdmin();
        $this->validateStaffCsrf();

        $slug = trim((string) ($params['slug'] ?? ''));
        $manifest = $this->findPluginManifest($slug);
        if ($manifest === null) {
            $this->finishStaffAction(false, 'Plugin not found.', $this->pluginsAdminUrl('installed'));
        }

        $registry = $this->app->plugins();
        $enabled = array_values(array_filter(
            $registry->enabledSlugs(),
            static fn (string $s): bool => $s !== $slug,
        ));
        $registry->setEnabledSlugs($enabled);

        $actor = $this->app->auth()->user();
        $this->app->auditLog()->record(
            (int) ($actor['id'] ?? 0),
            'plugin.disable',
            'plugin',
            null,
            $this->app->request()->ip(),
            ['slug' => $slug],
        );

        $this->app->invalidatePluginCache($slug);

        $storagePath = (string) $this->app->config()->get('paths.storage');
        SiteMaintenance::clearCaches($this->app->cache(), $storagePath);

        $this->finishStaffAction(true, "Disabled plugin: {$manifest->name}.", $this->pluginsAdminUrl('installed'));
    }

    public function removePlugin(array $params): void
    {
        $this->app->auth()->requireAdmin();
        $this->validateStaffCsrf();

        $slug = trim((string) ($params['slug'] ?? ''));
        $manifest = $this->findPluginManifest($slug);
        if ($manifest === null) {
            $this->finishStaffAction(false, 'Plugin not found.', $this->pluginsAdminUrl('installed'));
        }

        $config = $this->app->config();
        $installer = new PluginInstaller(
            (string) $config->get('paths.plugins'),
            (string) $config->get('paths.storage'),
        );

        try {
            $purgeStorage = filter_var(
                $this->app->request()->input('purge_storage'),
                FILTER_VALIDATE_BOOL,
            );
            $installer->removeInstalled($slug, $purgeStorage);
        } catch (\Throwable $e) {
            $message = trim($e->getMessage());
            $this->finishStaffAction(
                false,
                $message !== '' ? $message : 'Could not remove plugin.',
                $this->pluginsAdminUrl('installed'),
            );
        }

        $registry = $this->app->plugins();
        $registry->disable($slug);
        $this->pluginAuditService()->forget($slug);

        $actor = $this->app->auth()->user();
        $this->app->auditLog()->record(
            (int) ($actor['id'] ?? 0),
            'plugin.remove',
            'plugin',
            null,
            $this->app->request()->ip(),
            [
                'slug' => $slug,
                'version' => $manifest->version,
                'purge_storage' => filter_var(
                    $this->app->request()->input('purge_storage'),
                    FILTER_VALIDATE_BOOL,
                ),
            ],
        );

        $this->app->invalidatePluginCache($slug);

        $storagePath = (string) $config->get('paths.storage');
        SiteMaintenance::clearCaches($this->app->cache(), $storagePath);

        $message = "Removed plugin: {$manifest->name}.";
        if (filter_var($this->app->request()->input('purge_storage'), FILTER_VALIDATE_BOOL)) {
            $message .= ' Plugin storage purged.';
        }

        $this->finishStaffAction(true, $message, $this->pluginsAdminUrl('catalog'));
    }

    private function pluginAuditor(): PluginAuditor
    {
        $config = $this->app->config();

        return new PluginAuditor(
            LATCH_ROOT,
            (string) $config->get('paths.plugins'),
            (string) $config->get('paths.storage'),
        );
    }

    private function pluginAuditService(): PluginAuditService
    {
        $config = $this->app->config();
        $storagePath = (string) $config->get('paths.storage');

        return new PluginAuditService(
            $this->pluginAuditor(),
            new PluginAuditCache($storagePath . '/cache/plugin-audits'),
        );
    }

    private function pluginCatalog(): PluginCatalog
    {
        $config = $this->app->config();
        $storagePath = (string) $config->get('paths.storage');

        return new PluginCatalog(
            $storagePath . '/cache/plugin-catalog.json',
            (string) ($config->get('plugin_catalog.catalog_url') ?? PluginCatalog::DEFAULT_CATALOG_URL),
            (string) ($config->get('plugin_catalog.release_repo') ?? PluginCatalog::DEFAULT_RELEASE_REPO),
            (int) ($config->get('plugin_catalog.cache_ttl_seconds') ?? 3600),
        );
    }

    private function pluginCatalogInstaller(): PluginCatalogInstaller
    {
        $config = $this->app->config();
        $storagePath = (string) $config->get('paths.storage');
        $pluginsPath = (string) $config->get('paths.plugins');
        $catalog = $this->pluginCatalog();

        return new PluginCatalogInstaller(
            new PluginInstaller($pluginsPath, $storagePath),
            $this->pluginAuditService(),
            $this->app->plugins(),
            new PluginReleaseDownloader($catalog->releaseRepo()),
            $storagePath,
        );
    }

    /**
     * @param list<PluginCatalogEntry> $entries
     */
    private function findCatalogEntry(array $entries, string $slug): ?PluginCatalogEntry
    {
        foreach ($entries as $entry) {
            if ($entry->slug === $slug) {
                return $entry;
            }
        }

        return null;
    }

    private function findPluginManifest(string $slug): ?PluginManifest
    {
        if ($slug === '') {
            return null;
        }

        foreach ($this->app->plugins()->discover() as $manifest) {
            if ($manifest->slug === $slug) {
                return $manifest;
            }
        }

        return null;
    }

    private function requirePluginSettingsManifest(array $params): ?PluginManifest
    {
        $slug = trim((string) ($params['slug'] ?? ''));
        $manifest = $this->findPluginManifest($slug);
        if ($manifest === null) {
            Response::notFound('Plugin not found');

            return null;
        }

        if (!$manifest->hasSettingsUi()) {
            Response::notFound('This plugin has no settings');

            return null;
        }

        return $manifest;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditRowForTemplate(PluginAuditReport $report, ?string $scannedAt = null, bool $fromCache = false): array
    {
        $criticalFindings = [];
        $warnFindings = [];

        foreach ($report->findings as $finding) {
            $row = $finding->toArray();
            if ($finding->severity === PluginAuditFinding::SEVERITY_CRITICAL) {
                $criticalFindings[] = $row;
                continue;
            }

            if ($finding->severity === PluginAuditFinding::SEVERITY_WARN) {
                $warnFindings[] = $row;
            }
        }

        return [
            'passed' => $report->passed(),
            'enable_allowed' => $report->enableAllowed(),
            'critical_count' => $report->criticalCount(),
            'warn_count' => $report->warnCount(),
            'critical_findings' => $criticalFindings,
            'warn_findings' => $warnFindings,
            'scanned_at' => $scannedAt,
            'from_cache' => $fromCache,
            'findings' => array_map(
                static fn (PluginAuditFinding $f): array => $f->toArray(),
                $report->findings,
            ),
        ];
    }

    private function auditFailureMessage(PluginAuditReport $report): string
    {
        $parts = [sprintf(
            'Security audit failed (%d critical, %d warning).',
            $report->criticalCount(),
            $report->warnCount(),
        )];

        if ($report->passed() && !$report->enableAllowed()) {
            $parts = ['Plugin audit passed but enable is blocked due to hook injection warnings.'];
        }

        foreach ($report->findings as $finding) {
            if ($finding->severity !== PluginAuditFinding::SEVERITY_CRITICAL
                && !($finding->severity === PluginAuditFinding::SEVERITY_WARN
                    && (str_starts_with($finding->code, 'markup_') || str_starts_with($finding->code, 'js_')))) {
                continue;
            }

            $location = $finding->file ?? 'plugin';
            if ($finding->line !== null) {
                $location .= ':' . $finding->line;
            }

            $parts[] = "{$finding->code} ({$location})";
            if (count($parts) >= 4) {
                break;
            }
        }

        $parts[] = 'Run php bin/latch plugin-audit ' . ($report->slug ?? '') . ' for the full report.';

        return implode(' ', $parts);
    }

    public function webhooks(array $params = []): void
    {
        $this->app->auth()->requireAdmin();

        $rows = [];
        foreach ($this->app->webhookRepository()->listAll() as $webhook) {
            $events = json_decode((string) ($webhook['events'] ?? '[]'), true);
            $rows[] = [
                'webhook' => $webhook,
                'events' => is_array($events) ? $events : [],
            ];
        }

        $this->app->render('admin/webhooks.html.twig', [
            'webhooks' => $rows,
            'event_catalog' => WebhookEvent::all(),
            'new_secret' => $this->app->session()->flash('webhook_secret'),
        ]);
    }

    public function createWebhook(array $params = []): void
    {
        $this->app->auth()->requireAdmin();
        $this->validateStaffCsrf();

        $url = trim((string) $this->app->request()->input('url', ''));
        $description = trim((string) $this->app->request()->input('description', ''));
        $eventsInput = $this->app->request()->input('events');
        $events = is_array($eventsInput)
            ? array_values(array_filter(array_map('strval', $eventsInput), static fn (string $e): bool => WebhookEvent::isValid($e)))
            : [];

        $urlError = OutboundUrlGuard::publicHttpsUrlError($url);
        if ($urlError !== null) {
            $this->finishStaffAction(false, $urlError, '/admin/webhooks');
        }

        if ($events === []) {
            $this->finishStaffAction(false, 'Select at least one event.', '/admin/webhooks');
        }

        $secret = bin2hex(random_bytes(32));
        $id = $this->app->webhookRepository()->create($url, $secret, $events, $description);

        $actor = $this->app->auth()->user();
        $this->app->auditLog()->record(
            (int) ($actor['id'] ?? 0),
            'webhook.create',
            'webhook',
            $id,
            $this->app->request()->ip(),
            ['url' => $url, 'events' => $events],
        );

        $this->app->session()->flash('success', 'Webhook created. Copy the signing secret now — it will not be shown again.');
        $this->app->session()->flash('webhook_secret', $secret);
        Response::redirect('/admin/webhooks');
    }

    public function deleteWebhook(array $params): void
    {
        $this->app->auth()->requireAdmin();
        $this->validateStaffCsrf();

        $id = (int) ($params['id'] ?? 0);
        $webhook = $this->app->webhookRepository()->findById($id);
        if ($webhook === null) {
            $this->finishStaffAction(false, 'Webhook not found.', '/admin/webhooks');
        }

        $this->app->webhookRepository()->delete($id);

        $actor = $this->app->auth()->user();
        $this->app->auditLog()->record(
            (int) ($actor['id'] ?? 0),
            'webhook.delete',
            'webhook',
            $id,
            $this->app->request()->ip(),
            ['url' => (string) ($webhook['url'] ?? '')],
        );

        $this->finishStaffAction(true, 'Webhook deleted.', '/admin/webhooks');
    }

    public function toggleWebhook(array $params): void
    {
        $this->app->auth()->requireAdmin();
        $this->validateStaffCsrf();

        $id = (int) ($params['id'] ?? 0);
        $webhook = $this->app->webhookRepository()->findById($id);
        if ($webhook === null) {
            $this->finishStaffAction(false, 'Webhook not found.', '/admin/webhooks');
        }

        $enabled = !(bool) ($webhook['enabled'] ?? 0);
        $this->app->webhookRepository()->setEnabled($id, $enabled);

        $actor = $this->app->auth()->user();
        $this->app->auditLog()->record(
            (int) ($actor['id'] ?? 0),
            $enabled ? 'webhook.enable' : 'webhook.disable',
            'webhook',
            $id,
            $this->app->request()->ip(),
        );

        $this->finishStaffAction(
            true,
            $enabled ? 'Webhook enabled.' : 'Webhook disabled.',
            '/admin/webhooks',
        );
    }

    private function saveBrandingUploads(): ?string
    {
        $branding = $this->app->siteBranding();

        if ($this->app->request()->input('brand_logo_remove') === '1') {
            $branding->removeLogo();
        }
        if ($this->app->request()->input('brand_logo_dark_remove') === '1') {
            $branding->removeAsset('logo_dark');
        }
        if ($this->app->request()->input('brand_favicon_remove') === '1') {
            $branding->removeAsset('favicon');
        }
        if ($this->app->request()->input('brand_og_remove') === '1') {
            $branding->removeAsset('og');
        }

        /** @var array<string, string> $fields */
        $fields = [
            'brand_logo' => 'logo',
            'brand_logo_dark' => 'logo_dark',
            'brand_favicon' => 'favicon',
            'brand_og' => 'og',
        ];

        foreach ($fields as $field => $asset) {
            $upload = $_FILES[$field] ?? null;
            if (!is_array($upload)) {
                continue;
            }

            $error = $branding->saveAssetUpload($asset, $upload);
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    private function normalizeFooterAbout(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }
}