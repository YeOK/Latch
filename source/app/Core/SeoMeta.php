<?php

declare(strict_types=1);

namespace Latch\Core;

/**
 * Builds page-level SEO metadata for HTML head tags.
 */
final class SeoMeta
{
    public const DESCRIPTION_MAX = 160;
    public const OG_IMAGE_PATH = '/assets/img/og-image.png';
    public const OG_IMAGE_WIDTH = 1200;
    public const OG_IMAGE_HEIGHT = 630;
    public const OG_IMAGE_TYPE = 'image/png';

    /** @var list<string> */
    private const NOINDEX_PREFIXES = [
        '/login',
        '/register',
        '/forgot-password',
        '/reset-password',
        '/verify-email',
        '/confirm-email-change',
        '/login/2fa',
        '/admin',
        '/profile',
        '/notifications',
        '/watched',
        '/preview',
        '/search',
        '/mod/',
    ];

    public function __construct(
        private readonly string $title,
        private readonly string $description,
        private readonly string $canonical,
        private readonly string $type = 'website',
        private readonly ?string $image = null,
        private readonly bool $noindex = false,
        private readonly ?string $publishedTime = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'canonical' => $this->canonical,
            'type' => $this->type,
            'image' => $this->image,
            'image_width' => $this->image !== null ? self::OG_IMAGE_WIDTH : null,
            'image_height' => $this->image !== null ? self::OG_IMAGE_HEIGHT : null,
            'image_type' => $this->image !== null ? self::OG_IMAGE_TYPE : null,
            'noindex' => $this->noindex,
            'published_time' => $this->publishedTime,
        ];
    }

    public static function absoluteUrl(string $siteUrl, string $path): string
    {
        $siteUrl = rtrim($siteUrl, '/');
        if ($path === '' || $path === '/') {
            return $siteUrl . '/';
        }

        return $siteUrl . (str_starts_with($path, '/') ? $path : '/' . $path);
    }

    public static function defaultImage(string $siteUrl): string
    {
        return self::absoluteUrl($siteUrl, self::OG_IMAGE_PATH);
    }

    public static function forHome(string $siteUrl, string $siteName, string $tagline, bool $membersOnly = false): self
    {
        $description = $tagline !== '' ? $tagline : 'Community discussions on ' . $siteName;

        return new self(
            $siteName . ' — Boards',
            self::trimDescription($description),
            self::absoluteUrl($siteUrl, '/'),
            image: self::defaultImage($siteUrl),
            noindex: $membersOnly,
        );
    }

    /**
     * @param array<string, mixed> $board
     */
    public static function forBoard(
        string $siteUrl,
        string $siteName,
        array $board,
        string $canonicalPath,
        bool $membersOnly = false,
    ): self {
        $description = trim((string) ($board['description'] ?? ''));
        if ($description === '') {
            $description = 'Topics in ' . $board['name'] . ' on ' . $siteName;
        }

        return new self(
            (string) $board['name'] . ' — ' . $siteName,
            self::trimDescription($description),
            self::absoluteUrl($siteUrl, $canonicalPath),
            image: self::defaultImage($siteUrl),
            noindex: $membersOnly,
        );
    }

    public static function forTopic(
        string $siteUrl,
        string $siteName,
        string $topicTitle,
        int $topicId,
        string $description,
        ?string $publishedTime = null,
        bool $membersOnly = false,
    ): self {
        return new self(
            $topicTitle . ' — ' . $siteName,
            self::trimDescription($description),
            self::absoluteUrl($siteUrl, '/topic/' . $topicId),
            'article',
            self::defaultImage($siteUrl),
            $membersOnly,
            $publishedTime,
        );
    }

    public static function forTag(
        string $siteUrl,
        string $siteName,
        string $tagName,
        string $canonicalPath,
        bool $membersOnly = false,
    ): self {
        return new self(
            '#' . $tagName . ' — ' . $siteName,
            self::trimDescription('Topics tagged #' . $tagName . ' on ' . $siteName),
            self::absoluteUrl($siteUrl, $canonicalPath),
            image: self::defaultImage($siteUrl),
            noindex: $membersOnly,
        );
    }

    public static function forUser(
        string $siteUrl,
        string $siteName,
        string $username,
        string $bio,
        bool $membersOnly = false,
    ): self {
        $description = trim($bio);
        if ($description === '') {
            $description = 'Profile and recent posts by ' . $username . ' on ' . $siteName;
        }

        return new self(
            $username . ' — ' . $siteName,
            self::trimDescription($description),
            self::absoluteUrl($siteUrl, '/user/' . rawurlencode($username)),
            image: self::defaultImage($siteUrl),
            noindex: $membersOnly,
        );
    }

    public static function forPage(
        string $siteUrl,
        string $siteName,
        string $pageTitle,
        string $path,
        string $description = '',
    ): self {
        if ($description === '') {
            $description = $pageTitle . ' — ' . $siteName;
        }

        return new self(
            $pageTitle . ' — ' . $siteName,
            self::trimDescription($description),
            self::absoluteUrl($siteUrl, $path),
            image: self::defaultImage($siteUrl),
        );
    }

    public static function forPath(
        string $siteUrl,
        string $siteName,
        string $tagline,
        string $path,
        bool $membersOnly = false,
    ): self {
        $noindex = $membersOnly || self::pathRequiresNoindex($path);

        if ($path === '/' || $path === '') {
            return self::forHome($siteUrl, $siteName, $tagline, $membersOnly);
        }

        return new self(
            $siteName,
            self::trimDescription($tagline !== '' ? $tagline : $siteName),
            self::absoluteUrl($siteUrl, $path),
            image: self::defaultImage($siteUrl),
            noindex: $noindex,
        );
    }

    public static function pathRequiresNoindex(string $path): bool
    {
        foreach (self::NOINDEX_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        if (preg_match('#^/board/[^/]+/new$#', $path) === 1) {
            return true;
        }

        return false;
    }

    private static function trimDescription(string $text): string
    {
        return RssFeed::excerpt(trim($text), self::DESCRIPTION_MAX);
    }
}