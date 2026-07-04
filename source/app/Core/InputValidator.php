<?php

declare(strict_types=1);

namespace Latch\Core;

use RuntimeException;

/**
 * Field validation before persistence (defense in depth alongside PDO binding and output escaping).
 */
final class InputValidator
{
    public function __construct(private readonly Config $config)
    {
    }

    public function usernameError(string $username): ?string
    {
        $username = trim($username);
        $min = $this->intLimit('username_min', 3);
        $max = $this->intLimit('username_max', 32);

        if ($username === '') {
            return 'Username is required.';
        }

        if (mb_strlen($username) < $min || mb_strlen($username) > $max) {
            return "Username must be {$min}–{$max} characters.";
        }

        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', $username)) {
            return 'Username may only contain letters, numbers, underscores, and hyphens, and must start with a letter or number.';
        }

        return null;
    }

    public function emailError(string $email): ?string
    {
        $email = strtolower(trim($email));
        $max = $this->intLimit('email_max', 254);

        if ($email === '') {
            return 'Email is required.';
        }

        if (mb_strlen($email) > $max) {
            return "Email must be {$max} characters or fewer.";
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Invalid email address.';
        }

        return null;
    }

    public function passwordError(string $password, ?int $minLength = null): ?string
    {
        $min = $minLength ?? (int) $this->config->get('security.password_min_length', 8);
        $max = $this->intLimit('password_max', 128);

        if ($password === '') {
            return 'Password is required.';
        }

        if (strlen($password) < $min) {
            return "Password must be at least {$min} characters.";
        }

        if (strlen($password) > $max) {
            return "Password must be {$max} characters or fewer.";
        }

        return null;
    }

    public function postBodyError(string $body): ?string
    {
        $body = trim($body);
        $max = $this->intLimit('post_body_max', 65535);

        if ($body === '') {
            return 'Post body is required.';
        }

        if (mb_strlen($body) > $max) {
            return 'Post is too long (maximum ' . number_format($max) . ' characters).';
        }

        return null;
    }

    public function topicTitleError(string $title): ?string
    {
        $title = trim($title);
        $min = $this->intLimit('topic_title_min', 1);
        $max = $this->intLimit('topic_title_max', 255);

        if ($title === '') {
            return 'Title is required.';
        }

        $length = mb_strlen($title);
        if ($length < $min || $length > $max) {
            return "Title must be {$min}–{$max} characters.";
        }

        return null;
    }

    public function bioError(string $bio): ?string
    {
        $max = $this->intLimit('bio_max', 500);
        if (mb_strlen(trim($bio)) > $max) {
            return "Bio must be {$max} characters or fewer.";
        }

        return null;
    }

    public function boardNameError(string $name): ?string
    {
        $name = trim($name);
        $max = $this->intLimit('board_name_max', 80);

        if ($name === '') {
            return 'Board name is required.';
        }

        if (mb_strlen($name) > $max) {
            return "Board name must be {$max} characters or fewer.";
        }

        return null;
    }

    public function boardDescriptionError(string $description): ?string
    {
        $max = $this->intLimit('board_description_max', 500);
        if (mb_strlen(trim($description)) > $max) {
            return "Board description must be {$max} characters or fewer.";
        }

        return null;
    }

    public function searchQueryError(string $query): ?string
    {
        $max = $this->intLimit('search_query_max', 200);
        if (mb_strlen(trim($query)) > $max) {
            return "Search query must be {$max} characters or fewer.";
        }

        return null;
    }

    public function reportDetailError(string $detail): ?string
    {
        $max = $this->intLimit('report_detail_max', 500);
        if (mb_strlen(trim($detail)) > $max) {
            return "Details must be {$max} characters or fewer.";
        }

        return null;
    }

    public function siteNameError(string $name): ?string
    {
        $name = trim($name);
        $max = $this->intLimit('site_name_max', 80);

        if ($name === '') {
            return 'Site name is required.';
        }

        if (mb_strlen($name) > $max) {
            return "Site name must be {$max} characters or fewer.";
        }

        return null;
    }

    public function siteTaglineError(string $tagline): ?string
    {
        $max = $this->intLimit('site_tagline_max', 160);
        if (mb_strlen(trim($tagline)) > $max) {
            return "Site tagline must be {$max} characters or fewer.";
        }

        return null;
    }

    public function footerAboutError(string $text): ?string
    {
        $max = $this->intLimit('footer_about_max', 500);
        if (mb_strlen($text) > $max) {
            return "Footer about text must be {$max} characters or fewer.";
        }

        return null;
    }

    public function assertUsername(string $username): void
    {
        $this->assert($this->usernameError($username));
    }

    public function assertPostBody(string $body): void
    {
        $this->assert($this->postBodyError($body));
    }

    public function assertTopicTitle(string $title): void
    {
        $this->assert($this->topicTitleError($title));
    }

    public function assertBio(string $bio): void
    {
        $this->assert($this->bioError($bio));
    }

    public function assertBoardName(string $name): void
    {
        $this->assert($this->boardNameError($name));
    }

    public function assertBoardDescription(string $description): void
    {
        $this->assert($this->boardDescriptionError($description));
    }

    /**
     * @return array<string, int>
     */
    public function limits(): array
    {
        return [
            'username_min' => $this->intLimit('username_min', 3),
            'username_max' => $this->intLimit('username_max', 32),
            'email_max' => $this->intLimit('email_max', 254),
            'post_body_max' => $this->intLimit('post_body_max', 65535),
            'topic_title_max' => $this->intLimit('topic_title_max', 255),
            'bio_max' => $this->intLimit('bio_max', 500),
            'board_name_max' => $this->intLimit('board_name_max', 80),
            'board_description_max' => $this->intLimit('board_description_max', 500),
            'search_query_max' => $this->intLimit('search_query_max', 200),
            'report_detail_max' => $this->intLimit('report_detail_max', 500),
            'site_name_max' => $this->intLimit('site_name_max', 80),
            'site_tagline_max' => $this->intLimit('site_tagline_max', 160),
            'footer_about_max' => $this->intLimit('footer_about_max', 500),
            'password_max' => $this->intLimit('password_max', 128),
        ];
    }

    private function assert(?string $error): void
    {
        if ($error !== null) {
            throw new RuntimeException($error);
        }
    }

    private function intLimit(string $key, int $default): int
    {
        $value = (int) $this->config->get('input.' . $key, $default);

        return max(1, $value);
    }
}