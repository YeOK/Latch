<?php

declare(strict_types=1);

/**
 * Copyright (c) 2026 Latch contributors
 *
 * SPDX-License-Identifier: MIT
 */


namespace Latch\Plugins\MdImport;

use Latch\Core\Application;

final class ImportPage
{
    public function __construct(
        private readonly Application $app,
    ) {
    }

    public function render(): void
    {
        $this->app->auth()->requireAdmin();

        $boards = $this->app->boards()->all();
        $csrf = $this->app->csrf()->field();
        $maxTags = $this->app->maxTagsPerTopic();

        $boardOptions = '';
        foreach ($boards as $board) {
            $name = htmlspecialchars((string) $board['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $id = (int) $board['id'];
            $boardOptions .= "<option value=\"{$id}\">{$name}</option>";
        }

        $html = <<<HTML
<section class="page-header">
    <h1>Import markdown</h1>
    <p class="muted">Upload a <code>.md</code> file to publish it as a forum topic with GitHub-style formatting.</p>
</section>

<section class="form-section md-import-admin">
    <form method="post" action="/admin/md-import" enctype="multipart/form-data" class="form form-wide" data-account-bypass>
        {$csrf}
        <label>
            Board
            <select name="board_id" required>
                <option value="">Choose a board…</option>
                {$boardOptions}
            </select>
        </label>
        <label>
            Markdown file
            <input type="file" name="markdown_file" accept=".md,text/markdown,text/plain">
        </label>
        <label>
            Or paste markdown
            <textarea name="markdown_paste" rows="8" placeholder="# Optional paste instead of upload"></textarea>
        </label>
        <label>
            Title override (optional)
            <input type="text" name="title" maxlength="255" placeholder="Defaults to first # heading or filename">
        </label>
        <label>
            Tags (optional)
            <input type="text" name="tags" placeholder="docs, release" maxlength="120">
        </label>
        <p class="muted">Up to {$maxTags} tags. YAML front matter <code>tags:</code> is also supported.</p>
        <label class="checkbox">
            <input type="checkbox" name="strip_h1" value="1" checked>
            Remove leading <code># title</code> from the post body when it matches the topic title
        </label>
        <button type="submit" class="btn btn-primary">Import as topic</button>
    </form>
</section>
HTML;

        $this->app->render('admin/plugin_page.html.twig', [
            'page_title' => 'Import markdown',
            'content_html' => $html,
        ]);
    }
}