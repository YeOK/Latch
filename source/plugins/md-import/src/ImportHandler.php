<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Plugins\MdImport;

use Latch\Core\Application;
use Latch\Core\Cache;
use Latch\Core\Plugins\PostSaveContext;
use Latch\Core\Response;
use Latch\Models\PostRepository;
use RuntimeException;

final class ImportHandler
{
    public function __construct(
        private readonly Application $app,
        private readonly MarkdownImport $parser = new MarkdownImport(),
    ) {
    }

    public function handle(): void
    {
        $this->app->auth()->requireAdmin();

        if (!$this->app->csrf()->validate($this->app->request()->input('_csrf'))) {
            $this->app->session()->flash('error', 'Invalid form token.');
            Response::redirect('/admin/md-import');
        }

        $user = $this->app->auth()->user();
        if ($user === null) {
            Response::redirect('/login');
        }

        $markdown = $this->readMarkdownInput();
        if ($markdown === null) {
            Response::redirect('/admin/md-import');
        }

        $boardId = (int) $this->app->request()->input('board_id', 0);
        $board = $this->app->boards()->findById($boardId);
        if ($board === null) {
            $this->app->session()->flash('error', 'Choose a valid board.');
            Response::redirect('/admin/md-import');
        }

        $titleOverride = trim((string) $this->app->request()->input('title', ''));
        $stripLeadingH1 = $this->app->request()->input('strip_h1') === '1';
        $filename = (string) ($_FILES['markdown_file']['name'] ?? 'import.md');

        try {
            $parsed = $this->parser->parse(
                $markdown,
                $filename,
                $titleOverride !== '' ? $titleOverride : null,
                $stripLeadingH1,
            );
        } catch (\InvalidArgumentException $e) {
            $this->app->session()->flash('error', $e->getMessage());
            Response::redirect('/admin/md-import');
        }

        $title = $parsed['title'];
        $validator = $this->app->inputValidator();
        $titleError = $validator->topicTitleError($title);
        if ($titleError !== null) {
            $this->app->session()->flash('error', $titleError);
            Response::redirect('/admin/md-import');
        }

        $maxPostBytes = $validator->limits()['post_body_max'];
        try {
            $postBodies = $this->parser->splitIntoPosts($parsed['body'], $maxPostBytes);
        } catch (\InvalidArgumentException $e) {
            $this->app->session()->flash('error', $e->getMessage());
            Response::redirect('/admin/md-import');
        }

        foreach ($postBodies as $body) {
            $bodyError = $validator->postBodyError($body);
            if ($bodyError !== null) {
                $this->app->session()->flash('error', $bodyError);
                Response::redirect('/admin/md-import');
            }
        }

        $firstBody = array_shift($postBodies);
        if ($firstBody === null) {
            $this->app->session()->flash('error', 'Markdown file has no content.');
            Response::redirect('/admin/md-import');
        }

        $saveContext = new PostSaveContext(
            $firstBody,
            $user,
            $board,
            null,
            'topic',
            null,
            $title,
        );
        $rejectReason = $this->app->applyPostBeforeSave($saveContext);
        if ($rejectReason !== null) {
            $this->app->session()->flash('error', $rejectReason);
            Response::redirect('/admin/md-import');
        }
        $firstBody = $saveContext->body;

        try {
            $topic = $this->app->topics()->create(
                (int) $board['id'],
                (int) $user['id'],
                $title,
                $firstBody,
                PostRepository::APPROVAL_APPROVED,
            );
        } catch (RuntimeException $e) {
            $this->app->session()->flash('error', $e->getMessage());
            Response::redirect('/admin/md-import');
        }

        $topicId = (int) $topic['id'];
        $tagNames = $parsed['tags'];
        $formTags = $this->app->topicTags()->parse(
            (string) $this->app->request()->input('tags', ''),
            $this->app->maxTagsPerTopic(),
        );
        if ($formTags !== []) {
            $tagNames = $formTags;
        }
        $this->app->tags()->syncForTopic($topicId, $tagNames);

        $posts = $this->app->posts()->listByTopic($topicId, false, (int) $user['id'], false);
        $replyCount = 0;

        foreach ($postBodies as $body) {
            $replyContext = new PostSaveContext(
                $body,
                $user,
                $board,
                $topic,
                'reply',
            );
            $rejectReason = $this->app->applyPostBeforeSave($replyContext);
            if ($rejectReason !== null) {
                $this->app->session()->flash(
                    'error',
                    'Topic created but a follow-up chunk was rejected: ' . $rejectReason,
                );
                Response::redirect('/topic/' . $topicId);
            }

            try {
                $this->app->posts()->create(
                    $topicId,
                    (int) $user['id'],
                    $replyContext->body,
                    null,
                    PostRepository::APPROVAL_APPROVED,
                );
                $replyCount++;
            } catch (RuntimeException $e) {
                $this->app->session()->flash(
                    'error',
                    'Topic created but a follow-up chunk failed: ' . $e->getMessage(),
                );
                Response::redirect('/topic/' . $topicId);
            }
        }

        $this->app->indexSearchTopic($topicId);
        if ($posts !== []) {
            $saveContext->post = $posts[0];
            $saveContext->topic = $topic;
            $this->app->firePostAfterSave($saveContext);
        }

        $this->app->invalidateCacheTags([
            Cache::tagBoard((int) $board['id']),
            Cache::tagTopic($topicId),
            Cache::tagUser((int) $user['id']),
            Cache::tagSite(),
        ]);

        $message = 'Markdown imported as a new topic.';
        if ($replyCount > 0) {
            $message .= ' Added ' . $replyCount . ' continuation post' . ($replyCount === 1 ? '' : 's') . '.';
        }
        $this->app->session()->flash('success', $message);
        Response::redirect('/topic/' . $topicId);
    }

    private function readMarkdownInput(): ?string
    {
        $upload = $_FILES['markdown_file'] ?? null;

        if (is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmp = (string) ($upload['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                $this->app->session()->flash('error', 'Upload failed.');
                return null;
            }

            $size = (int) ($upload['size'] ?? 0);
            if ($size <= 0) {
                $this->app->session()->flash('error', 'Uploaded file is empty.');
                return null;
            }

            $name = (string) ($upload['name'] ?? '');
            if ($name !== '' && !preg_match('/\.md$/i', $name)) {
                $this->app->session()->flash('error', 'Only .md files are supported.');
                return null;
            }

            $contents = file_get_contents($tmp);
            if (!is_string($contents)) {
                $this->app->session()->flash('error', 'Could not read uploaded file.');
                return null;
            }

            return $contents;
        }

        $paste = (string) $this->app->request()->input('markdown_paste', '');
        if (trim($paste) !== '') {
            return $paste;
        }

        $this->app->session()->flash('error', 'Upload a .md file or paste markdown.');
        return null;
    }
}