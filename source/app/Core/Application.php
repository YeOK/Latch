<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Core;

use Closure;
use Latch\Core\BoardIcons\BoardIconRegistry;
use Latch\Core\Plugins\HookName;
use Latch\Core\Plugins\HookRegistry;
use Latch\Core\Plugins\PluginCacheCoordinator;
use Latch\Core\Plugins\PluginCollectContext;
use Latch\Core\Plugins\PluginDatabaseManager;
use Latch\Core\Plugins\PluginLoader;
use Latch\Core\Plugins\PluginRegistry;
use Latch\Core\Plugins\PostSaveContext;
use Latch\Core\Plugins\ProfileSaveContext;
use Latch\Core\SeoMeta;
use Latch\Controllers\AdminController;
use Latch\Controllers\ApiMessagesController;
use Latch\Controllers\ApiV1Controller;
use Latch\Controllers\AuthController;
use Latch\Controllers\BoardController;
use Latch\Controllers\HealthController;
use Latch\Controllers\HomeController;
use Latch\Controllers\LocaleController;
use Latch\Controllers\MessageController;
use Latch\Controllers\ModController;
use Latch\Controllers\NotificationController;
use Latch\Controllers\OAuthController;
use Latch\Controllers\OidcController;
use Latch\Controllers\PageController;
use Latch\Controllers\PostVoteController;
use Latch\Controllers\PreviewController;
use Latch\Controllers\ProfileController;
use Latch\Controllers\UserController;
use Latch\Controllers\ReportController;
use Latch\Controllers\RssController;
use Latch\Controllers\SeoController;
use Latch\Controllers\SearchController;
use Latch\Controllers\TagController;
use Latch\Controllers\TopicController;
use Latch\Controllers\TopicWatchController;
use Latch\Controllers\TwoFactorController;
use Latch\Models\ApiAuditLogRepository;
use Latch\Models\AuditLogRepository;
use Latch\Models\BoardRepository;
use Latch\Models\DirectMessageRepository;
use Latch\Core\Oidc\OidcConfig;
use Latch\Core\Oidc\OidcHttpClient;
use Latch\Core\Oidc\OidcService;
use Latch\Models\OAuthClientRepository;
use Latch\Models\OAuthTokenRepository;
use Latch\Models\OidcIdentityRepository;
use Latch\Models\EmailChangeRepository;
use Latch\Models\EmailVerificationRepository;
use Latch\Models\MailQueueRepository;
use Latch\Models\NotificationRepository;
use Latch\Models\PasswordResetRepository;
use Latch\Models\PostReactionRepository;
use Latch\Models\PostRepository;
use Latch\Models\PostRevisionRepository;
use Latch\Models\ReportRepository;
use Latch\Models\RssRepository;
use Latch\Models\SettingRepository;
use Latch\Models\SitemapRepository;
use Latch\Models\TopicRepository;
use Latch\Models\TopicWatchRepository;
use Latch\Models\UserBlockRepository;
use Latch\Models\UserRepository;
use Latch\Models\UserSessionRepository;
use Latch\Models\UserWarningRepository;
use Latch\Models\WebhookRepository;
use Latch\Support\VersionInfo;
use Latch\Support\WebhookDispatcher;
use Latch\Models\RecoveryCodeRepository;
use Latch\Models\SearchRepository;
use Latch\Models\TagRepository;

final class Application implements PluginCollectContext
{
    private Config $config;
    private Session $session;
    private Database $db;
    private Request $request;
    private Router $router;
    private View $view;
    private Auth $auth;
    private Csrf $csrf;
    private RateLimiter $rateLimiter;
    private Cache $cache;
    private SecurityLog $securityLog;
    private Mail $mail;
    private MailQueueService $mailQueue;
    private UserRepository $users;
    private BoardRepository $boards;
    private TopicRepository $topics;
    private PostRepository $posts;
    private SettingRepository $settings;
    private PasswordResetRepository $passwordResets;
    private EmailVerificationRepository $emailVerifications;
    private EmailChangeRepository $emailChanges;
    private UserSessionRepository $userSessions;
    private AuditLogRepository $auditLog;
    private ReportRepository $reports;
    private UserWarningRepository $userWarnings;
    private ReportReasons $reportReasons;
    private ReportQuarantine $reportQuarantine;
    private ThemeMode $themeMode;
    private Locale $locale;
    private ?Translator $translatorInstance = null;
    private AvatarUrl $avatarUrl;
    private TwoFactor $twoFactor;
    private TagRepository $tags;
    private TopicTags $topicTags;
    private SearchRepository $search;
    private PostFormatter $postFormatter;
    private BoardIconRegistry $boardIcons;
    private RssRepository $rss;
    private SitemapRepository $sitemap;
    private SpamGuard $spamGuard;
    private RegistrationGuard $registrationGuard;
    private Turnstile $turnstile;
    private InputValidator $inputValidator;
    private NotificationRepository $notifications;
    private NotificationService $notificationService;
    private NotificationMessageFormatter $notificationMessageFormatter;
    private DirectMessageRepository $directMessages;
    private UserBlockRepository $userBlocks;
    private MessageService $messages;
    private PostReactionRepository $postReactions;
    private PostRevisionRepository $postRevisions;
    private TopicWatchRepository $topicWatches;
    private OAuthClientRepository $oauthClients;
    private OAuthTokenRepository $oauthTokens;
    private ApiAuditLogRepository $apiAuditLog;
    private ApiAuth $apiAuth;
    private OidcConfig $oidcConfig;
    private OidcService $oidc;
    private ReputationService $reputation;
    private ModerationTrashService $moderationTrash;
    private HookRegistry $hookRegistry;
    private PluginRegistry $pluginRegistry;
    private PluginLoader $pluginLoader;
    private PluginDatabaseManager $pluginDatabaseManager;
    private PluginCacheCoordinator $pluginCacheCoordinator;
    private WebhookRepository $webhooks;
    private WebhookDispatcher $webhookDispatcher;
    private string $cspNonce;

    public function __construct()
    {
        $this->config = new Config(LATCH_ROOT . '/config');
        $this->session = new Session();
        $this->request = new Request($this->config);
        $this->router = new Router();
        $this->themeMode = new ThemeMode();
        $this->locale = new Locale();
        $this->avatarUrl = new AvatarUrl();
        $this->cspNonce = bin2hex(random_bytes(16));

        if (!$this->config->isInstalled()) {
            Response::html(
                '<h1>Latch not installed</h1><p>Run <code>php bin/latch install</code> from the source directory.</p>',
                503
            );
            exit;
        }

        $this->session->start($this->config, $this->request);

        $this->db = Database::fromConfig($this->config);
        $this->csrf = new Csrf($this->session);
        $this->view = new View($this->config, $this->csrf);
        $this->rateLimiter = new RateLimiter($this->db);

        $storagePath = (string) $this->config->get('paths.storage');
        $this->cache = new Cache($storagePath);
        $this->securityLog = new SecurityLog($storagePath . '/logs/security.log');

        $this->inputValidator = new InputValidator($this->config);
        $this->users = new UserRepository($this->db, $this->inputValidator);
        $this->boards = new BoardRepository($this->db, $this->inputValidator);
        $this->posts = new PostRepository($this->db, $this->inputValidator);
        $this->topics = new TopicRepository($this->db, $this->posts, $this->inputValidator);
        $this->settings = new SettingRepository($this->db);
        $this->webhooks = new WebhookRepository($this->db);
        $this->webhookDispatcher = new WebhookDispatcher($this->webhooks);
        $this->passwordResets = new PasswordResetRepository($this->db);
        $this->emailVerifications = new EmailVerificationRepository($this->db);
        $this->emailChanges = new EmailChangeRepository($this->db);
        $this->userSessions = new UserSessionRepository($this->db);
        $this->auditLog = new AuditLogRepository($this->db);
        $this->reports = new ReportRepository($this->db);
        $this->userWarnings = new UserWarningRepository($this->db);
        $this->reportReasons = new ReportReasons($this->settings);
        $this->reportQuarantine = new ReportQuarantine(
            $this->reports,
            $this->posts,
            $this->reportReasons,
            $this->securityLog,
        );
        $this->mail = new Mail($this->config, $this->settings);
        $this->mailQueue = new MailQueueService(
            $this->mail,
            $this->settings,
            new MailQueueRepository($this->db),
        );
        $recoveryCodes = new RecoveryCodeRepository($this->db);
        $this->twoFactor = new TwoFactor(
            $this->config,
            $this->users,
            $recoveryCodes,
            new SecretCipher($this->config),
            new Totp(),
        );
        $this->topicTags = new TopicTags();
        $this->tags = new TagRepository($this->db, $this->topicTags);
        $this->postFormatter = new PostFormatter();
        $this->search = new SearchRepository($this->db, $this->postFormatter, $this->tags);
        $this->boardIcons = new BoardIconRegistry($this->config);
        $this->rss = new RssRepository($this->db, $this->postFormatter);
        $this->sitemap = new SitemapRepository($this->db);
        $this->spamGuard = new SpamGuard(
            $this->settings,
            $this->posts,
            $this->securityLog,
            $this->request,
        );
        $this->turnstile = new Turnstile(
            (string) $this->config->get('security.turnstile_site_key', ''),
            (string) $this->config->get('security.turnstile_secret_key', ''),
        );
        $this->registrationGuard = new RegistrationGuard(
            $this->settings,
            $this->rateLimiter,
            $this->turnstile,
            $this->securityLog,
            $this->request,
        );
        $this->notifications = new NotificationRepository($this->db);
        $emailNotifications = new EmailNotificationService(
            $this->mail,
            $this->settings,
            $this->users,
            $this->mailQueue,
        );
        $this->notificationMessageFormatter = new NotificationMessageFormatter();
        $this->notificationService = new NotificationService(
            $this->notifications,
            $this->users,
            $emailNotifications,
        );
        $this->directMessages = new DirectMessageRepository($this->db);
        $this->userBlocks = new UserBlockRepository($this->db);
        $this->messages = new MessageService(
            $this->directMessages,
            $this->userBlocks,
            $this->users,
            $this->notificationService,
            $this->spamGuard,
        );
        $this->postReactions = new PostReactionRepository($this->db);
        $this->postRevisions = new PostRevisionRepository($this->db);
        $this->topicWatches = new TopicWatchRepository($this->db);
        $this->oauthClients = new OAuthClientRepository($this->db);
        $this->oauthTokens = new OAuthTokenRepository($this->db);
        $this->apiAuditLog = new ApiAuditLogRepository($this->db);
        $this->apiAuth = new ApiAuth(
            $this->request,
            $this->oauthTokens,
            $this->oauthClients,
            $this->users,
            $this->rateLimiter,
        );
        $oidcIdentities = new OidcIdentityRepository($this->db);
        $this->oidcConfig = new OidcConfig($this->config, $this->settings);
        $this->oidc = new OidcService(
            $this->oidcConfig,
            new OidcHttpClient(),
            $oidcIdentities,
            $this->users,
            $this->settings,
            $this->inputValidator,
            $this->config,
            $this->registrationGuard,
        );

        $this->auth = new Auth($this->session, $this->users, $this->userSessions, $this->request, $this->csrf);
        $this->reputation = new ReputationService($this->db, $this->users, $this->settings);
        $this->moderationTrash = new ModerationTrashService(
            $this->db,
            $this->boards,
            $this->topics,
            $this->posts,
            $this->settings,
            $this->search,
        );

        $this->hookRegistry = new HookRegistry();
        $this->pluginRegistry = new PluginRegistry(
            (string) $this->config->get('paths.plugins'),
            $this->settings,
        );
        $this->pluginDatabaseManager = new PluginDatabaseManager(
            $storagePath,
            Database::sqliteOptionsFromConfig($this->config),
        );
        $this->pluginLoader = new PluginLoader(
            $this->pluginRegistry,
            $this->hookRegistry,
            $this->latchVersion(),
            $this->pluginDatabaseManager,
        );

        $this->registerRoutes();
        $this->pluginLoader->boot($this);
        $this->pluginCacheCoordinator = new PluginCacheCoordinator(
            $this->pluginLoader->loaded(),
            $this->hookRegistry,
        );
        $this->hookRegistry->dispatch(HookName::ROUTE_REGISTER, $this->router, $this);
        $this->hookRegistry->dispatch(HookName::BOARD_ICONS, $this->boardIcons);
        $this->hookRegistry->dispatch(HookName::BOOTSTRAP, $this);

        $this->postFormatter->setImageHostChecker(
            fn (string $host): bool => $this->isImageHostAllowed($host),
        );
        $this->postFormatter->setLinkFormatter(
            fn (string $html, string $url, string $label, bool $standalone): string => (string) $this->hookRegistry->filter(
                HookName::POST_FORMAT_LINK,
                $html,
                $url,
                $label,
                $standalone,
            ),
        );
        $this->postFormatter->setFormatAfterFilter(
            fn (string $html, string $raw): string => (string) $this->hookRegistry->filter(
                HookName::POST_FORMAT_AFTER,
                $html,
                $raw,
            ),
        );
        $this->view->bindPostFormatter($this->postFormatter);

        SecurityHeaders::apply(
            SecurityHeaders::detectHttps($this->config, $this->request),
            $this->cspNonce,
            $this->hookRegistry->collect(HookName::CSP_IMG_SRC),
            $this->hookRegistry->collect(HookName::CSP_CONNECT_SRC),
            $this->hookRegistry->collect(HookName::CSP_FRAME_SRC),
            $this->hookRegistry->collect(HookName::CSP_SCRIPT_SRC),
        );
    }

    public function run(): void
    {
        $path = $this->request->path();

        if (str_starts_with($path, '/assets/')) {
            $this->serveThemeAsset(substr($path, 8));
            return;
        }

        $match = $this->router->match($this->request);
        if ($match === null) {
            Response::notFound();
        }

        $handler = $match['handler'];
        $handler($match['params']);
    }

    private function registerRoutes(): void
    {
        $home = new HomeController($this);
        $auth = new AuthController($this);
        $board = new BoardController($this);
        $topic = new TopicController($this);
        $admin = new AdminController($this);
        $mod = new ModController($this);
        $health = new HealthController($this);
        $profile = new ProfileController($this);
        $report = new ReportController($this);
        $preview = new PreviewController($this);
        $twoFactor = new TwoFactorController($this);
        $tag = new TagController($this);
        $search = new SearchController($this);
        $page = new PageController($this);
        $rss = new RssController($this);
        $seo = new SeoController($this);
        $user = new UserController($this);
        $notifications = new NotificationController($this);
        $messages = new MessageController($this);
        $localeCtrl = new LocaleController($this);
        $postVote = new PostVoteController($this);
        $topicWatch = new TopicWatchController($this);
        $api = new ApiV1Controller($this);
        $apiMessages = new ApiMessagesController($this);
        $oauth = new OAuthController($this);
        $oidc = new OidcController($this);

        $this->router->get('/', $this->bind($home, 'index'));
        $this->router->get('/health', $this->bind($health, 'ping'));
        $this->router->post('/locale', $this->bind($localeCtrl, 'switch'));

        $this->router->get('/api/v1', $this->bind($api, 'meta'));
        $this->router->get('/api/v1/boards', $this->bind($api, 'boards'));
        $this->router->get('/api/v1/boards/:slug', $this->bind($api, 'board'));
        $this->router->get('/api/v1/boards/:slug/topics', $this->bind($api, 'boardTopics'));
        $this->router->get('/api/v1/topics/:id', $this->bind($api, 'topic'));
        $this->router->get('/api/v1/topics/:id/posts', $this->bind($api, 'topicPosts'));
        $this->router->get('/api/v1/users/:username', $this->bind($api, 'user'));

        $this->router->get('/api/v1/messages', $this->bind($apiMessages, 'index'));
        $this->router->post('/api/v1/messages', $this->bind($apiMessages, 'start'));
        $this->router->get('/api/v1/messages/:id', $this->bind($apiMessages, 'show'));
        $this->router->post('/api/v1/messages/:id/send', $this->bind($apiMessages, 'send'));
        $this->router->post('/api/v1/messages/:id/read', $this->bind($apiMessages, 'markRead'));

        $this->router->post('/oauth/token', $this->bind($oauth, 'token'));
        $this->router->get('/oauth/authorize', $this->bind($oauth, 'showAuthorize'));
        $this->router->post('/oauth/authorize', $this->bind($oauth, 'approveAuthorize'));
        $this->router->get('/oauth/cli-callback', $this->bind($oauth, 'cliCallback'));
        $this->router->get('/feed.xml', $this->bind($rss, 'siteFeed'));
        $this->router->get('/sitemap.xml', $this->bind($seo, 'sitemap'));
        $this->router->get('/robots.txt', $this->bind($seo, 'robots'));

        $this->router->get('/auth/oidc/:provider', $this->bind($oidc, 'start'));
        $this->router->get('/auth/oidc/:provider/callback', $this->bind($oidc, 'callback'));

        $this->router->get('/login', $this->bind($auth, 'showLogin'));
        $this->router->post('/login', $this->bind($auth, 'login'));
        $this->router->get('/register', $this->bind($auth, 'showRegister'));
        $this->router->post('/register', $this->bind($auth, 'register'));
        $this->router->post('/logout', $this->bind($auth, 'logout'));
        $this->router->get('/forgot-password', $this->bind($auth, 'showForgotPassword'));
        $this->router->post('/forgot-password', $this->bind($auth, 'forgotPassword'));
        $this->router->get('/reset-password', $this->bind($auth, 'showResetPassword'));
        $this->router->post('/reset-password', $this->bind($auth, 'resetPassword'));
        $this->router->get('/verify-email', $this->bind($auth, 'verifyEmail'));
        $this->router->get('/confirm-email-change', $this->bind($auth, 'confirmEmailChange'));
        $this->router->get('/login/2fa/cancel', $this->bind($twoFactor, 'cancelPendingLogin'));
        $this->router->get('/login/2fa', $this->bind($twoFactor, 'showChallenge'));
        $this->router->post('/login/2fa', $this->bind($twoFactor, 'verifyChallenge'));
        $this->router->get('/login/2fa/setup', $this->bind($twoFactor, 'showLoginSetup'));
        $this->router->post('/login/2fa/setup', $this->bind($twoFactor, 'confirmLoginSetup'));

        $this->router->get('/user/:username', $this->bind($user, 'show'));
        $this->router->get('/notifications', $this->bind($notifications, 'index'));
        $this->router->get('/notifications/feed', $this->bind($notifications, 'feed'));
        $this->router->get('/notifications/:id/go', $this->bind($notifications, 'go'));
        $this->router->post('/notifications/:id/read', $this->bind($notifications, 'markRead'));
        $this->router->post('/notifications/read-all', $this->bind($notifications, 'markAllRead'));

        $this->router->get('/messages/feed', $this->bind($messages, 'feed'));
        $this->router->get('/messages/:id/feed', $this->bind($messages, 'threadFeed'));
        $this->router->post('/messages/start', $this->bind($messages, 'start'));
        $this->router->post('/messages/:id/send', $this->bind($messages, 'send'));
        $this->router->post('/messages/:id/delete', $this->bind($messages, 'deleteMessage'));
        $this->router->post('/messages/:id/delete-conversation', $this->bind($messages, 'deleteConversation'));
        $this->router->post('/messages/:id/read', $this->bind($messages, 'markRead'));
        $this->router->get('/messages/:id', $this->bind($messages, 'index'));
        $this->router->get('/messages', $this->bind($messages, 'index'));

        $this->router->get('/profile', $this->bind($profile, 'show'));
        $this->router->post('/profile/password', $this->bind($profile, 'changePassword'));
        $this->router->post('/profile/sessions/:id/revoke', $this->bind($profile, 'revokeSession'));
        $this->router->post('/profile/sessions/revoke-all', $this->bind($profile, 'revokeAllSessions'));
        $this->router->post('/profile/oauth-apps/:client_id/revoke', $this->bind($profile, 'revokeOAuthApp'));
        $this->router->get('/profile/export', $this->bind($profile, 'exportData'));
        $this->router->post('/profile/delete', $this->bind($profile, 'deleteAccount'));
        $this->router->post('/profile/theme', $this->bind($profile, 'saveTheme'));
        $this->router->post('/profile/locale', $this->bind($profile, 'saveLocale'));
        $this->router->post('/profile/notify-email', $this->bind($profile, 'saveNotifyEmail'));
        $this->router->post('/profile/accept-messages', $this->bind($profile, 'saveAcceptMessages'));
        $this->router->post('/profile/email-change', $this->bind($profile, 'requestEmailChange'));
        $this->router->post('/profile/save', $this->bind($profile, 'saveProfile'));
        $this->router->get('/profile/2fa', $this->bind($twoFactor, 'showProfile'));
        $this->router->post('/profile/2fa/enable', $this->bind($twoFactor, 'beginEnable'));
        $this->router->get('/profile/2fa/enable', $this->bind($twoFactor, 'showEnable'));
        $this->router->post('/profile/2fa/confirm', $this->bind($twoFactor, 'confirmEnable'));
        $this->router->post('/profile/2fa/disable', $this->bind($twoFactor, 'disable'));
        $this->router->post('/profile/2fa/recovery', $this->bind($twoFactor, 'regenerateRecovery'));
        $this->router->post('/preview', $this->bind($preview, 'post'));

        $this->router->get('/search', $this->bind($search, 'index'));
        $this->router->get('/privacy', $this->bind($page, 'privacy'));
        $this->router->get('/cookies', $this->bind($page, 'cookies'));
        $this->router->get('/tag/:slug', $this->bind($tag, 'show'));
        $this->router->get('/tags/suggest', $this->bind($tag, 'suggest'));

        $this->router->get('/board/:slug/feed.xml', $this->bind($rss, 'boardFeed'));
        $this->router->get('/board/:slug', $this->bind($board, 'show'));
        $this->router->get('/board/:slug/new', $this->bind($board, 'showNewTopic'));
        $this->router->post('/board/:slug/new', $this->bind($board, 'createTopic'));

        $this->router->get('/topic/:id/feed.xml', $this->bind($rss, 'topicFeed'));
        $this->router->get('/watched', $this->bind($topicWatch, 'index'));
        $this->router->get('/topic/:id/posts', $this->bind($topic, 'postsPartial'));
        $this->router->get('/topic/:id', $this->bind($topic, 'show'));
        $this->router->post('/topic/:id/reply', $this->bind($topic, 'reply'));
        $this->router->post('/topic/:id/watch', $this->bind($topicWatch, 'toggle'));
        $this->router->post('/post/:id/edit', $this->bind($topic, 'editPost'));
        $this->router->post('/post/:id/vote', $this->bind($postVote, 'vote'));
        $this->router->post('/report/post/:id', $this->bind($report, 'reportPost'));
        $this->router->post('/report/user/:id', $this->bind($report, 'reportUser'));

        $this->router->get('/admin', $this->bind($admin, 'index'));
        $this->router->post('/admin/backup', $this->bind($admin, 'createBackup'));
        $this->router->post('/admin/site-lock', $this->bind($admin, 'enableSiteLock'));
        $this->router->get('/admin/site-lock/enabled', $this->bind($admin, 'showSiteLockEnabled'));
        $this->router->post('/admin/cache-clear', $this->bind($admin, 'clearCache'));
        $this->router->post('/admin/search-reindex', $this->bind($admin, 'reindexSearch'));
        $this->router->get('/admin/users', $this->bind($admin, 'users'));
        $this->router->get('/admin/users/:id', $this->bind($admin, 'showUser'));
        $this->router->post('/admin/users/:id/role', $this->bind($admin, 'setRole'));
        $this->router->post('/admin/users/:id/reputation', $this->bind($admin, 'updateUserReputation'));
        $this->router->post('/admin/users/:id/ban', $this->bind($admin, 'banUser'));
        $this->router->post('/admin/users/:id/unban', $this->bind($admin, 'unbanUser'));
        $this->router->post('/admin/users/bulk-ban', $this->bind($admin, 'bulkBanUsers'));
        $this->router->post('/admin/users/bulk-unban', $this->bind($admin, 'bulkUnbanUsers'));
        $this->router->get('/admin/boards', $this->bind($admin, 'boards'));
        $this->router->post('/admin/boards', $this->bind($admin, 'createBoard'));
        $this->router->post('/admin/boards/:id/update', $this->bind($admin, 'updateBoard'));
        $this->router->post('/admin/boards/:id/delete', $this->bind($admin, 'deleteBoard'));
        $this->router->post('/admin/boards/:id/move', $this->bind($admin, 'moveBoard'));
        $this->router->get('/admin/settings', $this->bind($admin, 'settings'));
        $this->router->post('/admin/settings', $this->bind($admin, 'saveSettings'));
        $this->router->get('/admin/plugins', $this->bind($admin, 'plugins'));
        $this->router->get('/admin/webhooks', $this->bind($admin, 'webhooks'));
        $this->router->post('/admin/webhooks', $this->bind($admin, 'createWebhook'));
        $this->router->post('/admin/webhooks/:id/delete', $this->bind($admin, 'deleteWebhook'));
        $this->router->post('/admin/webhooks/:id/toggle', $this->bind($admin, 'toggleWebhook'));
        $this->router->get('/admin/plugins/:slug/settings', $this->bind($admin, 'pluginSettings'));
        $this->router->post('/admin/plugins/:slug/settings', $this->bind($admin, 'savePluginSettings'));
        $this->router->post('/admin/plugins/catalog/install', $this->bind($admin, 'installCatalogPlugin'));
        $this->router->post('/admin/plugins/:slug/enable', $this->bind($admin, 'enablePlugin'));
        $this->router->post('/admin/plugins/:slug/disable', $this->bind($admin, 'disablePlugin'));
        $this->router->post('/admin/plugins/:slug/remove', $this->bind($admin, 'removePlugin'));
        $this->router->get('/admin/reports/feed', $this->bind($admin, 'reportQueueFeed'));
        $this->router->get('/admin/reports', $this->bind($admin, 'reports'));
        $this->router->post('/admin/reports/:id/triage', $this->bind($admin, 'triageReport'));
        $this->router->get('/admin/approval', $this->bind($admin, 'approval'));
        $this->router->post('/admin/approval/:id/approve', $this->bind($admin, 'approvePost'));
        $this->router->post('/admin/approval/:id/reject', $this->bind($admin, 'rejectPost'));
        $this->router->get('/admin/audit', $this->bind($admin, 'auditLog'));
        $this->router->get('/admin/maintenance', $this->bind($admin, 'maintenance'));
        $this->router->post('/admin/mod-trash/purge-all', $this->bind($admin, 'purgeAllModTrash'));
        $this->router->get('/admin/trash', $this->bind($admin, 'trashQueue'));
        $this->router->post('/admin/trash/:id/restore', $this->bind($admin, 'restoreTrashedPost'));
        $this->router->post('/admin/trash/:id/purge', $this->bind($admin, 'purgeTrashedPost'));
        $this->router->get('/admin/quarantine', $this->bind($admin, 'quarantineQueue'));
        $this->router->post('/admin/quarantine/:id/lift', $this->bind($admin, 'liftQuarantinedPost'));

        $this->router->post('/mod/topic/:id/lock', $this->bind($mod, 'toggleLock'));
        $this->router->post('/mod/topic/:id/pin', $this->bind($mod, 'togglePin'));
        $this->router->post('/mod/topic/:id/title', $this->bind($mod, 'editTopicTitle'));
        $this->router->post('/mod/topic/:id/tags', $this->bind($mod, 'editTopicTags'));
        $this->router->post('/mod/topic/:id/details', $this->bind($mod, 'editTopicDetails'));
        $this->router->post('/mod/topic/:id/delete', $this->bind($mod, 'deleteTopic'));
        $this->router->post('/mod/topics/bulk', $this->bind($mod, 'bulkTopics'));
        $this->router->post('/mod/topic/:id/purge-trash', $this->bind($mod, 'purgeTrashTopic'));
        $this->router->post('/mod/topic/:id/move', $this->bind($mod, 'moveTopic'));
        $this->router->post('/mod/topic/:id/merge', $this->bind($mod, 'mergeTopic'));
        $this->router->post('/mod/topic/:id/split', $this->bind($mod, 'splitTopic'));
        $this->router->post('/mod/post/:id/delete', $this->bind($mod, 'deletePost'));
        $this->router->post('/mod/posts/trash', $this->bind($mod, 'trashPosts'));
        $this->router->post('/mod/trash/:id/restore', $this->bind($mod, 'restoreTrashedPost'));
        $this->router->post('/mod/trash/:id/purge', $this->bind($mod, 'purgeTrashedPost'));
        $this->router->post('/mod/posts/quarantine', $this->bind($mod, 'quarantinePosts'));
        $this->router->post('/mod/posts/lift-quarantine', $this->bind($mod, 'liftQuarantinePosts'));
        $this->router->get('/mod/post/:id/revisions', $this->bind($mod, 'postRevisions'));
    }

    private function bind(object $controller, string $method): Closure
    {
        return function (array $params = []) use ($controller, $method): void {
            $controller->$method($params);
        };
    }

    private function serveThemeAsset(string $relativePath): void
    {
        $themesPath = (string) $this->config->get('paths.themes');
        $active = (string) $this->config->get('theme.active', 'default');
        $defaultFile = $this->resolveThemeAssetFile($themesPath, 'default', $relativePath);
        $activeFile = $active !== 'default'
            ? $this->resolveThemeAssetFile($themesPath, $active, $relativePath)
            : null;

        // Child theme packs override tokens/components; base layout CSS stays in default.
        if ($relativePath === 'css/theme.css' && $defaultFile !== null && $activeFile !== null && $activeFile !== $defaultFile) {
            $this->emitThemeAsset(
                'text/css; charset=utf-8',
                file_get_contents($defaultFile) . "\n" . file_get_contents($activeFile),
                $defaultFile . '|' . filemtime($defaultFile) . '|' . $activeFile . '|' . filemtime($activeFile),
            );
            return;
        }

        $file = $activeFile ?? $defaultFile;

        if ($file === null) {
            Response::notFound('Asset not found');
        }

        $mime = match (pathinfo($file, PATHINFO_EXTENSION)) {
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'woff2' => 'font/woff2',
            default => 'application/octet-stream',
        };

        $this->emitThemeAsset($mime, (string) file_get_contents($file), $file . '|' . filemtime($file));
    }

    private function emitThemeAsset(string $mime, string $body, string $etagSeed): void
    {
        $etag = '"' . hash('sha256', $etagSeed) . '"';

        http_response_code(200);
        header('Content-Type: ' . $mime);
        header('Cache-Control: public, max-age=86400, must-revalidate');
        header('ETag: ' . $etag);

        $ifNoneMatch = $this->request->header('If-None-Match');
        if ($ifNoneMatch === $etag) {
            http_response_code(304);
            exit;
        }

        echo $body;
        exit;
    }

    private function resolveThemeAssetFile(string $themesPath, string $theme, string $relativePath): ?string
    {
        $base = realpath($themesPath . '/' . $theme . '/assets');
        if ($base === false) {
            return null;
        }

        $file = realpath($base . '/' . $relativePath);
        if ($file === false || !str_starts_with($file, $base) || !is_file($file)) {
            return null;
        }

        return $file;
    }

    private function themeAssetStamp(): int
    {
        static $stamp = null;
        if ($stamp !== null) {
            return $stamp;
        }

        $themesPath = (string) $this->config->get('paths.themes');
        $active = (string) $this->config->get('theme.active', 'default');
        $paths = [
            $themesPath . '/' . $active . '/assets/css/theme.css',
            $themesPath . '/default/assets/css/theme.css',
            $themesPath . '/default/assets/js/staff-actions.js',
            $themesPath . '/default/assets/js/theme.js',
            $themesPath . '/default/assets/js/mod-tools.js',
            $themesPath . '/default/assets/js/board-mod-tools.js',
        ];

        $max = 0;
        foreach ($paths as $path) {
            if (is_file($path)) {
                $max = max($max, (int) filemtime($path));
            }
        }

        $stamp = $max > 0 ? $max : time();

        return $stamp;
    }

    /**
     * @param array{route: string, params?: array<string, scalar>, tags?: list<string>}|null $cacheOptions
     */
    public function render(string $template, array $data = [], ?array $cacheOptions = null): void
    {
        $cacheKey = null;
        $tags = [];
        $canCache = $cacheOptions !== null && $this->canUsePageCache($cacheOptions);

        $this->view->bindTranslator($this->translator());

        if ($canCache) {
            $params = $cacheOptions['params'] ?? [];
            if (is_array($params)) {
                $params['_locale'] = $this->resolvedLocale();
            }
            $cacheKey = Cache::makeKey($cacheOptions['route'], is_array($params) ? $params : []);
            $tags = $cacheOptions['tags'] ?? [];

            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                Response::html(SecurityHeaders::rewriteHtmlNonces($cached, $this->cspNonce), 200, true);
                return;
            }
        }

        $html = $this->view->render($template, array_merge($this->sharedViewData(), $data));

        if ($canCache && $cacheKey !== null) {
            $this->cache->set($cacheKey, $html, $this->cacheTtlSeconds(), $tags);
            Response::html($html, 200, true);
            return;
        }

        Response::html($html);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function renderPartial(string $template, array $data = []): string
    {
        $this->view->bindTranslator($this->translator());

        return $this->view->render($template, array_merge($this->sharedViewData(), $data));
    }

    /**
     * Render a Twig partial with guest-only fragment cache (second layer under page cache).
     *
     * @param array<string, mixed> $data
     * @param list<string>         $tags
     */
    public function renderFragment(string $template, array $data, string $fragmentId, array $tags = []): string
    {
        $this->view->bindTranslator($this->translator());

        if (!$this->canUseFragmentCache()) {
            return $this->view->render($template, array_merge($this->sharedViewData(), $data));
        }

        $key = Cache::makeFragmentKey($fragmentId, ['_locale' => $this->resolvedLocale()]);
        $cached = $this->cache->getFragment($key);
        if ($cached !== null) {
            return SecurityHeaders::rewriteHtmlNonces($cached, $this->cspNonce);
        }

        $html = $this->view->render($template, array_merge($this->sharedViewData(), $data));
        $this->cache->setFragment($key, $html, $this->cacheTtlSeconds(), $tags);

        return $html;
    }

    public function postsPerPage(): int
    {
        return max(5, (int) $this->config->get('forum.posts_per_page', 20));
    }

    public function topicPaginationThreshold(): int
    {
        return max(10, (int) $this->config->get('forum.topic_pagination_threshold', 50));
    }

    /**
     * Guest-only HTML cache — never cache personalized or members-only responses.
     */
    private function canUsePageCache(array $cacheOptions): bool
    {
        if (!$this->cacheEnabled()) {
            return false;
        }

        if ($this->auth->check()) {
            return false;
        }

        if ($this->membersOnly()) {
            return false;
        }

        if ($this->pluginCacheCoordinator->disablesGuestPageCache()) {
            return false;
        }

        return true;
    }

    public function guestFragmentCacheEnabled(): bool
    {
        if ($this->pluginCacheCoordinator->disablesGuestPageCache()) {
            return false;
        }

        return $this->canUseFragmentCache();
    }

    private function canUseFragmentCache(): bool
    {
        if (!$this->cacheEnabled()) {
            return false;
        }

        if ($this->auth->check()) {
            return false;
        }

        if ($this->membersOnly()) {
            return false;
        }

        return true;
    }

    public function invalidateCacheTags(array $tags): void
    {
        $tags = array_merge($tags, $this->pluginCacheCoordinator->invalidationTagsForContentChange());
        $this->cache->invalidateTags(array_values(array_unique($tags)));
    }

    public function bustSiteCache(): void
    {
        $this->cache->invalidateTag(Cache::tagSite());
    }

    public function invalidatePluginCache(string $slug): void
    {
        if (!$this->cacheEnabled()) {
            return;
        }

        $this->cache->invalidateTag(Cache::tagPlugin($slug));
    }

    /** Drop all guest HTML cache entries (e.g. after site-wide access change). */
    public function bustGuestPageCache(): void
    {
        if ($this->cacheEnabled()) {
            $this->cache->purgeAll();
        }
    }

    /**
     * Invalidate cached guest pages for a board and its topics / author profiles.
     */
    public function bustBoardGuestCache(int $boardId): void
    {
        if (!$this->cacheEnabled()) {
            return;
        }

        $tags = [Cache::tagSite(), Cache::tagBoard($boardId)];
        foreach ($this->topics->activeIdsByBoard($boardId) as $topicId) {
            $tags[] = Cache::tagTopic($topicId);
        }
        foreach ($this->posts->distinctAuthorIdsForBoard($boardId) as $userId) {
            $tags[] = Cache::tagUser($userId);
        }

        $this->cache->invalidateTags(array_values(array_unique($tags)));
    }

    public function cacheEnabled(): bool
    {
        $fromDb = $this->settings->get('cache_enabled');
        if ($fromDb !== null) {
            return $fromDb === '1';
        }

        return (bool) $this->config->get('cache.enabled', true);
    }

    public function cacheTtlSeconds(): int
    {
        $fromDb = $this->settings->get('cache_ttl_seconds');
        if ($fromDb !== null && ctype_digit($fromDb)) {
            return max(30, (int) $fromDb);
        }

        return max(30, (int) $this->config->get('cache.ttl_seconds', 120));
    }

    public function config(): Config
    {
        return $this->config;
    }

    /** Cache-busting query param for theme CSS/JS (?v=). Appends file mtimes so deploys bust CDN caches. */
    public function assetVersion(): string
    {
        $configured = trim((string) $this->config->get('theme.asset_version', ''));
        $stamp = $this->themeAssetStamp();

        return $configured !== '' ? $configured . '.' . $stamp : (string) $stamp;
    }

    public function session(): Session
    {
        return $this->session;
    }

    public function request(): Request
    {
        return $this->request;
    }

    public function auth(): Auth
    {
        return $this->auth;
    }

    public function csrf(): Csrf
    {
        return $this->csrf;
    }

    public function rateLimiter(): RateLimiter
    {
        return $this->rateLimiter;
    }

    public function cache(): Cache
    {
        return $this->cache;
    }

    public function securityLog(): SecurityLog
    {
        return $this->securityLog;
    }

    public function mail(): Mail
    {
        return $this->mail;
    }

    public function mailQueue(): MailQueueService
    {
        return $this->mailQueue;
    }

    public function twoFactor(): TwoFactor
    {
        return $this->twoFactor;
    }

    public function tags(): TagRepository
    {
        return $this->tags;
    }

    public function topicTags(): TopicTags
    {
        return $this->topicTags;
    }

    public function search(): SearchRepository
    {
        return $this->search;
    }

    public function indexSearchTopic(int $topicId): void
    {
        $this->search->indexTopic($topicId);
    }

    public function maxTagsPerTopic(): int
    {
        return max(1, min(20, (int) $this->settings->get('max_tags_per_topic', '5')));
    }

    public function users(): UserRepository
    {
        return $this->users;
    }

    public function boards(): BoardRepository
    {
        return $this->boards;
    }

    public function boardIcons(): BoardIconRegistry
    {
        return $this->boardIcons;
    }

    public function plugins(): PluginRegistry
    {
        return $this->pluginRegistry;
    }

    public function pluginDatabaseManager(): PluginDatabaseManager
    {
        return $this->pluginDatabaseManager;
    }

    public function latchVersion(): string
    {
        return VersionInfo::resolveInstalledVersion($this->config, LATCH_ROOT);
    }

    public function rss(): RssRepository
    {
        return $this->rss;
    }

    public function sitemap(): SitemapRepository
    {
        return $this->sitemap;
    }

    public function siteUrl(): string
    {
        return rtrim((string) $this->config->get('site.url', 'http://localhost'), '/');
    }

    public function siteName(): string
    {
        $site = $this->config->get('site', []);

        return $this->settings->get('site_name', (string) ($site['name'] ?? 'Latch'));
    }

    public function siteTagline(): string
    {
        $site = $this->config->get('site', []);

        return $this->settings->get('site_tagline', (string) ($site['tagline'] ?? ''));
    }

    /**
     * @param array<string, mixed> $board
     * @return array<string, mixed>
     */
    public function enrichBoardWithIcon(array $board): array
    {
        $board['icon_key'] = $this->boardIcons->resolveKey($board);
        $board['icon_svg'] = $this->boardIcons->svgForBoard($board);

        return $board;
    }

    /**
     * @param list<array<string, mixed>> $boards
     * @return list<array<string, mixed>>
     */
    public function enrichBoardsForHome(array $boards, bool $isMod, ?int $viewerId): array
    {
        if ($boards === []) {
            return [];
        }

        $boardIds = array_map(static fn (array $board): int => (int) $board['id'], $boards);
        $summaries = $this->topics->activitySummariesForBoards($boardIds, $isMod);
        $recentByBoard = $this->topics->recentTopicsForBoards($boardIds, 4, $isMod);

        foreach ($boards as $i => $board) {
            $boardId = (int) $board['id'];
            $summary = $summaries[$boardId] ?? [
                'topic_count' => 0,
                'post_count' => 0,
                'last_activity_at' => null,
            ];
            $boards[$i]['topic_count'] = $summary['topic_count'];
            $boards[$i]['post_count'] = $summary['post_count'];
            $boards[$i]['last_activity_at'] = $summary['last_activity_at'];

            $recent = $recentByBoard[$boardId] ?? [];
            $recent = $this->enrichTopicsWithAvatars($recent);
            $boards[$i]['recent_topics'] = $this->enrichTopicsWithUnread($recent, $viewerId);
        }

        return $boards;
    }

    public function topics(): TopicRepository
    {
        return $this->topics;
    }

    public function posts(): PostRepository
    {
        return $this->posts;
    }

    public function spamGuard(): SpamGuard
    {
        return $this->spamGuard;
    }

    public function registrationGuard(): RegistrationGuard
    {
        return $this->registrationGuard;
    }

    public function inputValidator(): InputValidator
    {
        return $this->inputValidator;
    }

    public function notifications(): NotificationRepository
    {
        return $this->notifications;
    }

    public function notificationService(): NotificationService
    {
        return $this->notificationService;
    }

    public function notificationMessageFormatter(): NotificationMessageFormatter
    {
        return $this->notificationMessageFormatter;
    }

    public function userLocale(): string
    {
        return $this->resolvedLocale();
    }

    public function translatorFor(string $locale): Translator
    {
        return new Translator(
            LATCH_ROOT . '/lang',
            Locale::normalize($locale),
            $this->hookRegistry,
        );
    }

    public function transForLocale(string $locale, string $key, array $replace = []): string
    {
        return $this->translatorFor($locale)->get($key, $replace);
    }

    /**
     * @param array<string, string|int|float> $replace
     */
    public function flashTrans(string $type, string $key, array $replace = []): void
    {
        $this->session()->flash($type, $this->trans($key, $replace));
    }

    public function directMessages(): DirectMessageRepository
    {
        return $this->directMessages;
    }

    public function oidcConfig(): OidcConfig
    {
        return $this->oidcConfig;
    }

    public function oidc(): OidcService
    {
        return $this->oidc;
    }

    public function messages(): MessageService
    {
        return $this->messages;
    }

    public function postFormatter(): PostFormatter
    {
        return $this->postFormatter;
    }

    public function postReactions(): PostReactionRepository
    {
        return $this->postReactions;
    }

    public function postRevisions(): PostRevisionRepository
    {
        return $this->postRevisions;
    }

    public function postEditWindowMinutes(): int
    {
        return max(0, (int) $this->settings->get('post_edit_window_minutes', '60'));
    }

    /**
     * Whether the current viewer may read the board containing this post.
     *
     * @param array<string, mixed> $post
     */
    public function canUserAccessPost(array $post): bool
    {
        $topic = $this->topics->findById((int) $post['topic_id']);
        if ($topic === null || !empty($topic['deleted_at'])) {
            return false;
        }

        $board = $this->boards->findById((int) $topic['board_id']);
        if ($board === null) {
            return false;
        }

        return $this->boards->canRead(
            $board,
            $this->auth->check(),
            $this->membersOnly(),
            $this->viewerRole(),
            $this->viewerReputationRank(),
        );
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $topic
     * @param array<string, mixed>|null $user
     */
    public function canUserEditPost(array $post, array $topic, ?array $user, bool $isMod): bool
    {
        if ($user === null || $post['deleted_at'] !== null) {
            return false;
        }

        if ($isMod) {
            if (!$this->auth->isAdmin()) {
                $author = $this->users->findById((int) $post['user_id']);
                if ($author !== null && !PostEditGuard::modMayEditAuthor(
                    (int) $author['id'],
                    (string) $author['role'],
                )) {
                    return false;
                }
            }

            return true;
        }

        if ((int) $user['id'] !== (int) $post['user_id']) {
            return false;
        }

        if (!empty($topic['is_locked'])) {
            return false;
        }

        if ($post['quarantined_at'] !== null) {
            return false;
        }

        $status = (string) ($post['approval_status'] ?? 'approved');
        if ($status !== 'approved' && $status !== 'pending') {
            return false;
        }

        $window = $this->postEditWindowMinutes();
        if ($window === 0) {
            return true;
        }

        $createdAt = strtotime((string) $post['created_at']);
        if ($createdAt === false) {
            return false;
        }

        return $createdAt + ($window * 60) >= time();
    }

    public function topicWatches(): TopicWatchRepository
    {
        return $this->topicWatches;
    }

    public function oauthClients(): OAuthClientRepository
    {
        return $this->oauthClients;
    }

    public function oauthTokens(): OAuthTokenRepository
    {
        return $this->oauthTokens;
    }

    public function apiAuditLog(): ApiAuditLogRepository
    {
        return $this->apiAuditLog;
    }

    public function apiAuth(): ApiAuth
    {
        return $this->apiAuth;
    }

    /**
     * @param list<array<string, mixed>> $topics
     * @return list<array<string, mixed>>
     */
    public function enrichTopicsWithUnread(array $topics, ?int $userId): array
    {
        if ($userId === null || $topics === []) {
            foreach ($topics as $i => $topic) {
                $topics[$i]['is_unread'] = false;
            }

            return $topics;
        }

        $topicIds = array_map(static fn (array $t): int => (int) $t['id'], $topics);
        $flags = $this->topicWatches->unreadFlagsForTopics($userId, $topicIds);

        foreach ($topics as $i => $topic) {
            $topics[$i]['is_unread'] = $flags[(int) $topic['id']] ?? false;
        }

        return $topics;
    }

    /**
     * @param list<array<string, mixed>> $boards
     * @return list<array<string, mixed>>
     */
    public function enrichBoardsWithUnread(array $boards, ?int $userId): array
    {
        if ($userId === null || $boards === []) {
            foreach ($boards as $i => $board) {
                $boards[$i]['unread_count'] = 0;
            }

            return $boards;
        }

        $boardIds = array_map(static fn (array $b): int => (int) $b['id'], $boards);
        $counts = $this->topicWatches->unreadCountsForBoards($userId, $boardIds);

        foreach ($boards as $i => $board) {
            $boards[$i]['unread_count'] = $counts[(int) $board['id']] ?? 0;
        }

        return $boards;
    }

    public function markTopicReadForUser(int $userId, int $topicId, array $posts): void
    {
        if ($posts === []) {
            return;
        }

        $lastPost = $posts[array_key_last($posts)];
        $this->topicWatches->markRead(
            $userId,
            $topicId,
            (int) $lastPost['id'],
            (string) $lastPost['created_at'],
        );
    }

    public function participateInTopic(int $userId, int $topicId, array $posts): void
    {
        $this->topicWatches->watch($userId, $topicId);
        $this->markTopicReadForUser($userId, $topicId, $posts);
    }

    public function settings(): SettingRepository
    {
        return $this->settings;
    }

    public function passwordResets(): PasswordResetRepository
    {
        return $this->passwordResets;
    }

    public function emailVerifications(): EmailVerificationRepository
    {
        return $this->emailVerifications;
    }

    public function emailChanges(): EmailChangeRepository
    {
        return $this->emailChanges;
    }

    public function anonymisePostsOnDelete(): bool
    {
        return $this->settings->getBool('anonymise_posts_on_delete', true);
    }

    public function userSessions(): UserSessionRepository
    {
        return $this->userSessions;
    }

    public function auditLog(): AuditLogRepository
    {
        return $this->auditLog;
    }

    public function reports(): ReportRepository
    {
        return $this->reports;
    }

    public function userWarnings(): UserWarningRepository
    {
        return $this->userWarnings;
    }

    public function avatarUrl(): AvatarUrl
    {
        return $this->avatarUrl;
    }

    public function thirdPartyAvatarsAllowed(): bool
    {
        return CookieConsentGate::allowsThirdPartyContent(
            $this->gdprEnabled(),
            $this->request()->cookie(CookieConsentGate::CONSENT_COOKIE),
        );
    }

    public function resolveAvatar(string $email, int $size = 48): string
    {
        $url = $this->rawAvatarUrl($email, $size);

        return $url !== '' && $this->thirdPartyAvatarsAllowed() ? $url : '';
    }

    /**
     * Deferred avatar URL for GDPR mode before consent (rendered as data attribute, not loaded).
     */
    public function resolveAvatarPending(string $email, int $size = 48): string
    {
        if ($this->thirdPartyAvatarsAllowed()) {
            return '';
        }

        return $this->rawAvatarUrl($email, $size);
    }

    private function rawAvatarUrl(string $email, int $size): string
    {
        $default = $this->settings->getBool('use_gravatar', true)
            ? $this->avatarUrl->gravatarUrl($email, $size)
            : '';

        return (string) $this->hookRegistry->filter(
            HookName::AVATAR_RESOLVE,
            $default,
            $email,
            $size,
        );
    }

    public function isImageHostAllowed(string $host): bool
    {
        return $this->hookRegistry->filter(HookName::POST_FORMAT_IMAGE_HOST, false, $host) === true;
    }

    public function applyPostBeforeSave(PostSaveContext $context): ?string
    {
        $this->hookRegistry->dispatch(HookName::POST_BEFORE_SAVE, $context);

        return $context->rejectReason;
    }

    public function firePostAfterSave(PostSaveContext $context): void
    {
        $this->hookRegistry->dispatch(HookName::POST_AFTER_SAVE, $context);
        $this->webhookDispatcher->postCreated($context);
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $topic
     */
    public function firePostDelete(array $post, array $topic): void
    {
        $this->hookRegistry->dispatch(HookName::POST_DELETE, $post, $topic, $this);
    }

    /**
     * @param array<string, mixed> $topic
     * @param array<string, mixed> $board
     */
    public function fireTopicDelete(array $topic, array $board): void
    {
        $this->hookRegistry->dispatch(HookName::TOPIC_DELETE, $topic, $board, $this);
    }

    public function firePostVote(int $postId, int $userId, ?string $vote): void
    {
        $this->hookRegistry->dispatch(HookName::POST_VOTE, $postId, $userId, $vote, $this);
    }

    /**
     * @param array<string, mixed> $topic
     * @param array<string, mixed> $board
     * @return list<mixed>
     */
    public function collectTopicActions(array $topic, array $board): array
    {
        return $this->hookRegistry->collect(HookName::TOPIC_ACTIONS, $this, $topic, $board);
    }

    /**
     * @param array<string, mixed> $user
     * @return list<mixed>
     */
    public function collectProfileForm(array $user): array
    {
        return $this->hookRegistry->collect(HookName::PROFILE_FORM, $this, $user);
    }

    public function applyProfileBeforeSave(ProfileSaveContext $context): ?string
    {
        $this->hookRegistry->dispatch(HookName::PROFILE_BEFORE_SAVE, $context);

        return $context->rejectReason;
    }

    /**
     * @param array<string, mixed> $user
     */
    public function fireUserRegister(array $user): void
    {
        $this->hookRegistry->dispatch(HookName::USER_REGISTER, $user, $this);
        $this->webhookDispatcher->userRegistered($user);
        $this->bustSiteCache();
    }

    public function webhookRepository(): WebhookRepository
    {
        return $this->webhooks;
    }

    /**
     * @param list<array<string, mixed>> $posts
     * @return list<array<string, mixed>>
     */
    public function enrichPostsWithAvatars(array $posts, int $size = 48): array
    {
        foreach ($posts as $i => $post) {
            $username = (string) ($post['author_name'] ?? '');
            $email = (string) ($post['author_email'] ?? '');
            $posts[$i]['avatar_src'] = $this->resolveAvatar($email, $size);
            $posts[$i]['avatar_pending_src'] = $this->resolveAvatarPending($email, $size);
            $posts[$i]['avatar_hue'] = $this->avatarHue($username);
            unset($posts[$i]['author_email']);
        }

        return $posts;
    }

    /**
     * @param list<array<string, mixed>> $topics
     * @return list<array<string, mixed>>
     */
    public function enrichTopicsWithAvatars(array $topics, int $size = 32): array
    {
        foreach ($topics as $i => $topic) {
            $username = (string) ($topic['author_name'] ?? '');
            $email = (string) ($topic['author_email'] ?? '');
            $topics[$i]['avatar_src'] = $this->resolveAvatar($email, $size);
            $topics[$i]['avatar_pending_src'] = $this->resolveAvatarPending($email, $size);
            $topics[$i]['avatar_hue'] = $this->avatarHue($username);
            unset($topics[$i]['author_email']);
        }

        return $topics;
    }

    /**
     * @param list<array<string, mixed>> $users
     * @return list<array<string, mixed>>
     */
    public function enrichUsersWithAvatars(array $users, int $size = 32): array
    {
        foreach ($users as $i => $user) {
            $username = (string) ($user['username'] ?? '');
            $email = (string) ($user['email'] ?? '');
            $users[$i]['avatar_src'] = $this->resolveAvatar($email, $size);
            $users[$i]['avatar_pending_src'] = $this->resolveAvatarPending($email, $size);
            $users[$i]['avatar_hue'] = $this->avatarHue($username);
        }

        return $users;
    }

    public function avatarHue(string $username): int
    {
        $hash = 0;
        foreach (str_split(strtolower($username)) as $char) {
            $hash = ($hash * 31 + ord($char)) % 360;
        }

        return $hash;
    }

    public function reportReasons(): ReportReasons
    {
        return $this->reportReasons;
    }

    public function reportQuarantine(): ReportQuarantine
    {
        return $this->reportQuarantine;
    }

    public function gdprEnabled(): bool
    {
        return $this->settings->getBool('gdpr_enabled');
    }

    public function privacyOperatorName(): string
    {
        $name = trim((string) $this->settings->get('privacy_operator_name', ''));
        if ($name !== '') {
            return $name;
        }

        return (string) $this->settings->get('site_name', (string) $this->config->get('site.name', 'Latch'));
    }

    public function privacyContactEmail(): string
    {
        return trim((string) $this->settings->get('privacy_contact_email', ''));
    }

    public function defaultThemeMode(): string
    {
        $fromDb = $this->settings->get('default_theme_mode');
        if ($fromDb !== null && $fromDb !== '') {
            return ThemeMode::normalizePreference((string) $fromDb);
        }

        return ThemeMode::SYSTEM;
    }

    public function defaultLocale(): string
    {
        $fromDb = $this->settings->get('default_locale');
        if ($fromDb !== null && $fromDb !== '') {
            return Locale::normalize((string) $fromDb);
        }

        return (string) $this->config->get('i18n.default_locale', Locale::DEFAULT);
    }

    public function trans(string $key, array $replace = []): string
    {
        return $this->translator()->get($key, $replace);
    }

    public function resolvedLocale(): string
    {
        $user = $this->auth->user();

        return $this->locale->preference(
            $user,
            $this->request->cookie(Locale::COOKIE),
            $this->defaultLocale(),
        );
    }

    public function cspNonce(): string
    {
        return $this->cspNonce;
    }

    private function translator(): Translator
    {
        if ($this->translatorInstance === null) {
            $this->translatorInstance = new Translator(
                LATCH_ROOT . '/lang',
                $this->resolvedLocale(),
                $this->hookRegistry,
            );
        }

        return $this->translatorInstance;
    }

    public function membersOnly(): bool
    {
        $fromDb = $this->settings->getBool('members_only');
        if ($this->settings->get('members_only') !== null) {
            return $fromDb;
        }

        return (bool) $this->config->get('forum.members_only', false);
    }

    public function viewerRole(): ?string
    {
        $user = $this->auth->user();

        return $user !== null ? (string) ($user['role'] ?? Auth::ROLE_MEMBER) : null;
    }

    public function reputation(): ReputationService
    {
        return $this->reputation;
    }

    /** Queue a member for hourly reputation recompute (no-op for staff). */
    public function enqueueReputationUpdate(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $user = $this->users()->findById($userId);
        if ($user === null || (string) ($user['role'] ?? Auth::ROLE_MEMBER) !== Auth::ROLE_MEMBER) {
            return;
        }

        $this->reputation()->enqueueUser($userId);
    }

    public function moderationTrash(): ModerationTrashService
    {
        return $this->moderationTrash;
    }

    public function viewerReputationRank(): ?int
    {
        $user = $this->auth->user();
        if ($user === null) {
            return null;
        }

        $role = (string) ($user['role'] ?? Auth::ROLE_MEMBER);
        if (in_array($role, [Auth::ROLE_ADMIN, Auth::ROLE_MOD], true)) {
            return null;
        }

        if (!isset($user['reputation_rank']) || $user['reputation_rank'] === null || $user['reputation_rank'] === '') {
            return 1;
        }

        return (int) $user['reputation_rank'];
    }

    public function allowRegistration(): bool
    {
        $fromDb = $this->settings->getBool('allow_registration');
        if ($this->settings->get('allow_registration') !== null) {
            return $fromDb;
        }

        return (bool) $this->config->get('forum.allow_registration', true);
    }

    public function requireEmailVerification(): bool
    {
        return $this->settings->getBool('require_email_verification');
    }

    /**
     * @return array<string, mixed>
     */
    private function sharedViewData(): array
    {
        $site = $this->config->get('site', []);
        $user = $this->auth->user();
        if ($user !== null) {
            $email = (string) ($user['email'] ?? '');
            $user['avatar_src'] = $this->resolveAvatar($email, 28);
            $user['avatar_pending_src'] = $this->resolveAvatarPending($email, 28);
            $user['avatar_hue'] = $this->avatarHue((string) $user['username']);
        }
        $themeCookie = $this->request->cookie(ThemeMode::COOKIE);
        $themePreference = $this->themeMode->preference($user, $themeCookie, $this->defaultThemeMode());
        $localeCode = $this->resolvedLocale();

        $siteName = $this->settings->get('site_name', (string) ($site['name'] ?? 'Latch'));
        $siteTagline = $this->settings->get('site_tagline', (string) ($site['tagline'] ?? ''));
        $footerAbout = (string) $this->settings->get('footer_about', '');
        $siteUrl = (string) ($site['url'] ?? '');
        $membersOnly = $this->membersOnly();

        return [
            'site' => [
                'name' => $siteName,
                'tagline' => $siteTagline,
                'url' => $siteUrl,
                'use_gravatar' => $this->settings->getBool('use_gravatar', true),
            ],
            'footer_about' => $footerAbout,
            'seo' => SeoMeta::forPath(
                $siteUrl !== '' ? $siteUrl : $this->siteUrl(),
                $siteName,
                $siteTagline,
                $this->request->path(),
                $membersOnly,
            )->toArray(),
            'user' => $user,
            'is_admin' => $this->auth->isAdmin(),
            'is_mod' => $this->auth->isMod(),
            'flash_error' => $this->session->flash('error'),
            'flash_success' => $this->session->flash('success'),
            'report_queue' => null,
            'theme_mode' => $themePreference,
            'theme_effective' => $this->themeMode->effective($themePreference),
            'locale' => $localeCode,
            'locale_dir' => $this->locale->direction($localeCode),
            'locale_catalog' => Locale::catalog(),
            'csp_nonce' => $this->cspNonce,
            'csrf_token' => $this->csrf->token(),
            'asset_version' => $this->assetVersion(),
            'members_only' => $membersOnly,
            'allow_registration' => $this->allowRegistration(),
            'gdpr_enabled' => $this->gdprEnabled(),
            'privacy_operator_name' => $this->privacyOperatorName(),
            'privacy_contact_email' => $this->privacyContactEmail(),
            'current_path' => $this->request->path(),
            'input_limits' => $this->inputValidator->limits(),
            'notification_unread' => $user !== null ? $this->notifications->countUnread((int) $user['id']) : 0,
            'direct_messages_enabled' => $this->directMessages->isAvailable(),
            'messages_unread' => $user !== null ? $this->directMessages->countUnreadForUser((int) $user['id']) : 0,
            'watched_unread' => $user !== null ? $this->topicWatches->countUnreadWatched((int) $user['id']) : 0,
            'oidc_providers' => $this->buildOidcProviderList(),
            'plugin_theme_assets' => $this->pluginCacheCoordinator->collect($this, HookName::THEME_ASSETS),
            'plugin_theme_scripts' => $this->pluginCacheCoordinator->collect($this, HookName::THEME_SCRIPTS),
            'plugin_head_html' => $this->pluginCacheCoordinator->collect($this, HookName::LAYOUT_HEAD),
            'plugin_footer_html' => $this->pluginCacheCoordinator->collect($this, HookName::LAYOUT_FOOTER),
            'plugin_home_after_boards_html' => $this->pluginCacheCoordinator->collect($this, HookName::HOME_AFTER_BOARDS),
            'plugin_admin_menu_items' => $this->pluginCacheCoordinator->collect($this, HookName::ADMIN_MENU),
            'plugin_composer_toolbar' => $this->pluginCacheCoordinator->collect($this, HookName::EDITOR_COMPOSE),
            'plugin_client_loader' => $this->pluginCacheCoordinator->hasClientModePlugins(),
        ];
    }

    /**
     * @return list<array{key: string, name: string, url: string}>
     */
    private function buildOidcProviderList(): array
    {
        $providers = [];
        foreach ($this->oidcConfig->enabledProviders() as $key) {
            $providers[] = [
                'key' => $key,
                'name' => $this->oidcConfig->displayName($key),
                'url' => '/auth/oidc/' . $key,
            ];
        }

        return $providers;
    }

    /**
     * @return array{count: int, top: ?array<string, mixed>}|null
     */
    public function reportQueueSummary(): ?array
    {
        return $this->buildReportQueueSummary();
    }

    /**
     * @return array{count: int, top: ?array<string, mixed>}|null
     */
    private function buildReportQueueSummary(): ?array
    {
        if (!$this->auth->isMod()) {
            return null;
        }

        $count = $this->reports->openCount();
        if ($count === 0) {
            return null;
        }

        $top = $this->reports->topOpenReport();
        if ($top === null) {
            return ['count' => $count, 'top' => null];
        }

        $labels = $this->reportReasons->categories();
        $reasonCode = (string) ($top['reason_code'] ?? 'other');
        $url = '/admin/reports';
        if ($top['target_type'] === 'post' && !empty($top['post_topic_id'])) {
            $url = '/topic/' . $top['post_topic_id'] . '#post-' . $top['target_id'];
        } elseif ($top['target_type'] === 'user') {
            $url = '/admin/users';
        }

        return [
            'count' => $count,
            'top' => [
                'id' => (int) $top['id'],
                'severity' => (string) ($top['severity'] ?? 'medium'),
                'reason_label' => $labels[$reasonCode]['label'] ?? $reasonCode,
                'target_type' => (string) $top['target_type'],
                'target_id' => (int) $top['target_id'],
                'url' => $url,
                'queue_url' => '/admin/reports',
            ],
        ];
    }
}