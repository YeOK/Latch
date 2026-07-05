/**
 * Copyright (c) 2026 Latch contributors
 * SPDX-License-Identifier: MIT
 */

(function () {
    'use strict';

    var BUILTIN_ACTIONS = {
        bold: true,
        italic: true,
        link: true,
        quote: true,
        list: true,
        heading: true,
        code: true,
        'inline-code': true,
        spoiler: true,
        mention: true,
    };

    var CODE_FENCE_RE = /```([^\n]*)\n([\s\S]*?)```/g;
    var DEFAULT_CODE_LANG = 'php';

    function highlightCodeBlocks(root) {
        if (typeof window.hljs === 'undefined') {
            return;
        }

        (root || document).querySelectorAll('.post-content pre.code-block code').forEach(function (block) {
            window.hljs.highlightElement(block);
        });
    }

    function isQuoteDraft(text) {
        return /^\[quote(?:="[^"]*"| author="[^"]*")?\]/i.test(String(text).trim());
    }

    function isNewTopicDraftKey(draftKey) {
        return draftKey.indexOf('new-topic-') === 0;
    }

    function clearComposerDraft(composer) {
        if (!composer) {
            return;
        }

        var textarea = composer.querySelector('.composer-textarea');
        var draftKey = composer.getAttribute('data-draft-key') || '';

        if (textarea) {
            textarea.value = '';
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
        }

        if (draftKey) {
            try {
                localStorage.removeItem('latch_draft_' + draftKey);
            } catch (e) {
                /* ignore */
            }
        }
    }

    function restoreSavedDraft(textarea, draftKey) {
        if (!draftKey || !textarea) {
            return;
        }

        var isReplyComposer = Boolean(textarea.closest('#reply-panel'));
        var isEditPost = draftKey.indexOf('edit-post-') === 0;

        if (isReplyComposer || isEditPost) {
            return;
        }

        try {
            var saved = localStorage.getItem('latch_draft_' + draftKey) || '';
            if (!saved) {
                return;
            }

            if (isQuoteDraft(saved)) {
                localStorage.removeItem('latch_draft_' + draftKey);
                return;
            }

            if (isNewTopicDraftKey(draftKey)) {
                textarea.value = '';
            }

            if (textarea.value === '') {
                textarea.value = saved;
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
            }
        } catch (e) {
            /* ignore */
        }
    }

    function initComposer(root) {
        var textarea = root.querySelector('.composer-textarea');
        if (!textarea) {
            return;
        }

        var previewUrl = root.getAttribute('data-preview-url') || '/preview';
        var draftKey = root.getAttribute('data-draft-key') || '';
        var previewPanel = root.querySelector('.composer-preview');
        var codeBar = root.querySelector('.composer-code-bar');
        var codeLangSelect = root.querySelector('.composer-code-lang');
        var csrf = document.querySelector('meta[name="csrf-token"]');
        var previewTimer = null;
        var activeCodeFence = null;
        var syncingCodeLang = false;
        var syncingPreviewScroll = false;

        var isReplyComposer = Boolean(root.closest('#reply-panel'));

        if (isNewTopicDraftKey(draftKey)) {
            textarea.value = '';
        }

        if (draftKey) {
            if (!isReplyComposer) {
                restoreSavedDraft(textarea, draftKey);
            }

            textarea.addEventListener('input', function () {
                try {
                    localStorage.setItem('latch_draft_' + draftKey, textarea.value);
                } catch (e) {
                    /* ignore */
                }
            });
        }

        function syncPreviewToEditor() {
            if (!previewPanel || syncingPreviewScroll) {
                return;
            }

            var previewMax = previewPanel.scrollHeight - previewPanel.clientHeight;
            if (previewMax <= 0) {
                return;
            }

            var scrollMax = textarea.scrollHeight - textarea.clientHeight;
            var ratio;

            if (scrollMax > 0) {
                ratio = textarea.scrollTop / scrollMax;
            } else {
                var lines = textarea.value.split('\n');
                var line = textarea.value.slice(0, textarea.selectionStart).split('\n').length - 1;
                ratio = lines.length > 1 ? line / (lines.length - 1) : 0;
            }

            syncingPreviewScroll = true;
            previewPanel.scrollTop = ratio * previewMax;
            syncingPreviewScroll = false;
        }

        textarea.addEventListener('input', function () {
            updateCodeBar();
            schedulePreview();
            syncPreviewToEditor();
        });

        textarea.addEventListener('click', function () {
            updateCodeBar();
            syncPreviewToEditor();
        });
        textarea.addEventListener('keyup', function () {
            updateCodeBar();
            syncPreviewToEditor();
        });
        textarea.addEventListener('select', function () {
            updateCodeBar();
            syncPreviewToEditor();
        });
        textarea.addEventListener('scroll', syncPreviewToEditor, { passive: true });

        root.querySelectorAll('.composer-btn[data-action]').forEach(function (btn) {
            btn.addEventListener('click', function (event) {
                var action = btn.getAttribute('data-action') || '';
                if (!BUILTIN_ACTIONS[action]) {
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                applyAction(action);
            });
        });

        root.querySelectorAll('.composer-help-picker').forEach(function (picker) {
            var toggle = picker.querySelector('.composer-help-toggle');
            var panel = picker.querySelector('.composer-help-panel');
            if (!toggle || !panel) {
                return;
            }

            function closeHelpPanel() {
                panel.setAttribute('hidden', '');
                toggle.setAttribute('aria-expanded', 'false');
            }

            toggle.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                var open = panel.hasAttribute('hidden');
                root.querySelectorAll('.composer-help-panel').forEach(function (other) {
                    if (other !== panel) {
                        other.setAttribute('hidden', '');
                        var otherToggle = other.closest('.composer-help-picker');
                        if (otherToggle) {
                            var btn = otherToggle.querySelector('.composer-help-toggle');
                            if (btn) {
                                btn.setAttribute('aria-expanded', 'false');
                            }
                        }
                    }
                });
                root.querySelectorAll('.composer-emote-panel').forEach(function (other) {
                    other.setAttribute('hidden', '');
                    var otherToggle = other.closest('.composer-emote-picker');
                    if (otherToggle) {
                        var btn = otherToggle.querySelector('.composer-emote-toggle');
                        if (btn) {
                            btn.setAttribute('aria-expanded', 'false');
                        }
                    }
                });
                if (open) {
                    panel.removeAttribute('hidden');
                    toggle.setAttribute('aria-expanded', 'true');
                } else {
                    closeHelpPanel();
                }
            });

            document.addEventListener('click', function (event) {
                if (!picker.contains(event.target)) {
                    closeHelpPanel();
                }
            });
        });

        root.querySelectorAll('.composer-emote-picker').forEach(function (picker) {
            var toggle = picker.querySelector('.composer-emote-toggle');
            var panel = picker.querySelector('.composer-emote-panel');
            if (!toggle || !panel) {
                return;
            }

            function closeEmotePanel() {
                panel.setAttribute('hidden', '');
                toggle.setAttribute('aria-expanded', 'false');
            }

            toggle.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                var open = panel.hasAttribute('hidden');
                root.querySelectorAll('.composer-emote-panel').forEach(function (other) {
                    if (other !== panel) {
                        other.setAttribute('hidden', '');
                        var otherToggle = other.closest('.composer-emote-picker');
                        if (otherToggle) {
                            var btn = otherToggle.querySelector('.composer-emote-toggle');
                            if (btn) {
                                btn.setAttribute('aria-expanded', 'false');
                            }
                        }
                    }
                });
                root.querySelectorAll('.composer-help-panel').forEach(function (other) {
                    other.setAttribute('hidden', '');
                    var otherToggle = other.closest('.composer-help-picker');
                    if (otherToggle) {
                        var helpBtn = otherToggle.querySelector('.composer-help-toggle');
                        if (helpBtn) {
                            helpBtn.setAttribute('aria-expanded', 'false');
                        }
                    }
                });
                if (open) {
                    panel.removeAttribute('hidden');
                    toggle.setAttribute('aria-expanded', 'true');
                } else {
                    closeEmotePanel();
                }
            });

            panel.querySelectorAll('.composer-emote-item').forEach(function (item) {
                item.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    insertText(item.getAttribute('data-emote') || ':smile:');
                    closeEmotePanel();
                });
            });

            document.addEventListener('click', function (event) {
                if (!picker.contains(event.target)) {
                    closeEmotePanel();
                }
            });
        });

        if (codeLangSelect) {
            codeLangSelect.addEventListener('change', function () {
                if (!activeCodeFence || syncingCodeLang) {
                    return;
                }
                setCodeFenceLang(activeCodeFence, codeLangSelect.value);
            });
        }

        function findCodeFenceAt(text, pos) {
            var match;

            CODE_FENCE_RE.lastIndex = 0;
            while ((match = CODE_FENCE_RE.exec(text)) !== null) {
                var openStart = match.index;
                var openEnd = openStart + match[0].indexOf('\n') + 1;
                var closeEnd = openStart + match[0].length;

                if (pos >= openStart && pos <= closeEnd) {
                    return {
                        openStart: openStart,
                        openEnd: openEnd,
                        closeEnd: closeEnd,
                        lang: (match[1] || '').trim(),
                    };
                }
            }

            return null;
        }

        function setCodeFenceLang(fence, lang) {
            var text = textarea.value;
            var header = lang ? '```' + lang + '\n' : '```\n';
            var selection = textarea.selectionStart;
            var delta = header.length - (fence.openEnd - fence.openStart);

            textarea.setRangeText(header, fence.openStart, fence.openEnd, 'preserve');
            textarea.selectionStart = selection + delta;
            textarea.selectionEnd = selection + delta;
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
        }

        function syncCodeLangSelect(lang) {
            if (!codeLangSelect) {
                return;
            }

            syncingCodeLang = true;
            codeLangSelect.querySelectorAll('option[data-custom-lang]').forEach(function (option) {
                option.remove();
            });

            var normalized = lang || '';
            var hasOption = Array.from(codeLangSelect.options).some(function (option) {
                return option.value === normalized;
            });
            if (!hasOption && normalized !== '') {
                var custom = document.createElement('option');
                custom.value = normalized;
                custom.textContent = normalized;
                custom.dataset.customLang = '1';
                codeLangSelect.appendChild(custom);
            }

            codeLangSelect.value = normalized;
            syncingCodeLang = false;
        }

        function updateCodeBar() {
            if (!codeBar || !codeLangSelect) {
                return;
            }

            activeCodeFence = findCodeFenceAt(textarea.value, textarea.selectionStart);
            if (!activeCodeFence) {
                codeBar.setAttribute('hidden', '');
                return;
            }

            codeBar.removeAttribute('hidden');
            syncCodeLangSelect(activeCodeFence.lang);
        }

        function insertCodeBlock(lang) {
            var start = textarea.selectionStart;
            var end = textarea.selectionEnd;
            var selected = textarea.value.slice(start, end);
            var body = selected || 'code';
            var prefix = lang ? '```' + lang + '\n' : '```\n';
            var insert = prefix + body + '\n```';
            insertText(insert, selected ? 0 : -4);
            updateCodeBar();
        }

        function insertText(insert, cursor) {
            textarea.focus();
            var start = textarea.selectionStart;
            var end = textarea.selectionEnd;
            var selected = textarea.value.slice(start, end);
            cursor = cursor || 0;

            textarea.setRangeText(insert, start, end, 'end');
            if (cursor !== 0 && !selected) {
                textarea.selectionStart = textarea.selectionEnd + cursor;
            }
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
        }

        function applyAction(action) {
            var start = textarea.selectionStart;
            var end = textarea.selectionEnd;
            var selected = textarea.value.slice(start, end);
            var insert = '';
            var cursor = 0;

            switch (action) {
                case 'bold':
                    insert = '**' + (selected || 'bold') + '**';
                    cursor = selected ? 0 : -2;
                    break;
                case 'italic':
                    insert = '*' + (selected || 'italic') + '*';
                    cursor = selected ? 0 : -1;
                    break;
                case 'link':
                    var url = window.prompt('URL (https://…)', 'https://');
                    if (!url) {
                        return;
                    }
                    insert = '[url=' + url + ']' + (selected || 'link text') + '[/url]';
                    break;
                case 'quote':
                    insert = selected
                        ? '[quote="' + (document.body.dataset.username || 'user') + '"]\n' + selected + '\n[/quote]'
                        : '[quote]\nquoted text\n[/quote]';
                    break;
                case 'list':
                    if (selected) {
                        insert = selected
                            .split('\n')
                            .map(function (line) {
                                var trimmed = line.trim();
                                if (trimmed === '') {
                                    return '';
                                }
                                return trimmed.startsWith('- ') ? trimmed : '- ' + trimmed;
                            })
                            .join('\n');
                    } else {
                        insert = '- first item\n- second item';
                        cursor = -22;
                    }
                    break;
                case 'heading':
                    insert = selected ? '## ' + selected : '## Heading';
                    cursor = selected ? 0 : -8;
                    break;
                case 'mention':
                    insert = '@' + (selected || 'username').replace(/^@+/, '');
                    cursor = selected ? 0 : -8;
                    break;
                case 'code':
                    if (findCodeFenceAt(textarea.value, start)) {
                        if (codeLangSelect) {
                            codeLangSelect.focus();
                        }
                        return;
                    }
                    insertCodeBlock(DEFAULT_CODE_LANG);
                    return;
                case 'inline-code':
                    insert = '`' + (selected || 'code') + '`';
                    break;
                case 'spoiler':
                    insert = selected
                        ? '[spoiler]\n' + selected + '\n[/spoiler]'
                        : '[spoiler]\nhidden text\n[/spoiler]';
                    cursor = selected ? 0 : -12;
                    break;
                default:
                    return;
            }

            insertText(insert, cursor);
        }

        function schedulePreview() {
            if (!previewPanel || !csrf) {
                return;
            }

            clearTimeout(previewTimer);
            previewTimer = setTimeout(refreshPreview, 350);
        }

        function refreshPreview() {
            if (!previewPanel || !csrf) {
                return;
            }

            if (textarea.value.trim() === '') {
                previewPanel.innerHTML = 'Start typing to see formatted preview.';
                previewPanel.classList.add('muted');
                return;
            }

            previewPanel.textContent = 'Loading preview…';
            previewPanel.classList.add('muted');
            fetch(previewUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: '_csrf=' + encodeURIComponent(csrf.content) + '&body=' + encodeURIComponent(textarea.value),
                })
                    .then(function (res) {
                        return res.json();
                    })
                    .then(function (data) {
                        if (data.html !== undefined) {
                            previewPanel.innerHTML = data.html || '<p class="muted">Nothing to preview.</p>';
                            previewPanel.classList.remove('muted');
                            highlightCodeBlocks(previewPanel);
                            syncPreviewToEditor();
                        } else {
                            previewPanel.textContent = data.error || 'Preview failed.';
                        }
                    })
                    .catch(function () {
                        previewPanel.textContent = 'Preview unavailable.';
                    });
        }

        updateCodeBar();
        schedulePreview();

    }

    document.querySelectorAll('[data-editor]').forEach(initComposer);

    var replyPanel = document.getElementById('reply-panel');
    var replyToggle = document.getElementById('reply-toggle');
    var replyCancel = document.getElementById('reply-cancel');
    var scrollReply = document.getElementById('scroll-reply');

    function focusReplyComposer() {
        if (!replyPanel) {
            return;
        }

        var textarea = replyPanel.querySelector('.composer-textarea');
        if (textarea) {
            window.setTimeout(function () {
                textarea.focus();
            }, 200);
        }
    }

    function clearReplyComposer() {
        if (!replyPanel) {
            return;
        }

        clearComposerDraft(replyPanel.querySelector('[data-editor]'));
    }

    function prepareReplyComposer(options) {
        if (!replyPanel) {
            return;
        }

        var composer = replyPanel.querySelector('[data-editor]');
        var textarea = composer && composer.querySelector('.composer-textarea');
        if (!textarea) {
            return;
        }

        var draftKey = composer.getAttribute('data-draft-key') || '';
        var saved = '';

        if (draftKey) {
            try {
                saved = localStorage.getItem('latch_draft_' + draftKey) || '';
            } catch (e) {
                saved = '';
            }
        }

        if (options.fromQuote) {
            clearReplyComposer();
            return;
        }

        if (saved !== '' && !isQuoteDraft(saved)) {
            textarea.value = saved;
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
            return;
        }

        clearReplyComposer();
    }

    function insertReplyQuote(author, body) {
        if (!replyPanel) {
            return;
        }

        var textarea = replyPanel.querySelector('.composer-textarea');
        if (!textarea) {
            return;
        }

        var quote = '[quote="' + author + '"]\n' + body + '\n[/quote]\n\n';
        var pos = textarea.value.length;
        textarea.setRangeText(quote, pos, pos, 'end');
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        textarea.focus();
    }

    function openReplyPanel(options) {
        if (!replyPanel) {
            return;
        }

        options = options || {};

        prepareReplyComposer(options);

        if (options.fromQuote) {
            insertReplyQuote(options.author || 'user', options.body || '');
        }

        replyPanel.removeAttribute('hidden');
        if (replyToggle) {
            replyToggle.setAttribute('aria-expanded', 'true');
            replyToggle.setAttribute('hidden', '');
        }
        if (scrollReply) {
            scrollReply.setAttribute('hidden', '');
        }
        replyPanel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        focusReplyComposer();
    }

    function closeReplyPanel() {
        if (!replyPanel) {
            return;
        }

        replyPanel.setAttribute('hidden', '');
        if (replyToggle) {
            replyToggle.removeAttribute('hidden');
            replyToggle.setAttribute('aria-expanded', 'false');
        }
        updateScrollReply();
    }

    function updateScrollReply() {
        if (!scrollReply || !replyToggle) {
            return;
        }

        if (!replyPanel || !replyPanel.hasAttribute('hidden')) {
            scrollReply.setAttribute('hidden', '');
            return;
        }

        var rect = replyToggle.getBoundingClientRect();
        var show = rect.bottom < 0 || rect.top > window.innerHeight;
        if (show) {
            scrollReply.removeAttribute('hidden');
        } else {
            scrollReply.setAttribute('hidden', '');
        }
    }

    if (replyToggle) {
        replyToggle.addEventListener('click', openReplyPanel);
    }

    if (replyCancel) {
        replyCancel.addEventListener('click', closeReplyPanel);
    }

    if (scrollReply) {
        scrollReply.addEventListener('click', openReplyPanel);
    }

    document.querySelectorAll('.form-compose form').forEach(function (form) {
        form.addEventListener('submit', function () {
            var composer = form.querySelector('[data-editor]');
            var draftKey = composer && composer.getAttribute('data-draft-key');
            if (!draftKey) {
                return;
            }

            try {
                localStorage.removeItem('latch_draft_' + draftKey);
            } catch (e) {
                /* ignore */
            }
        });
    });

    if (replyPanel) {
        window.addEventListener('scroll', updateScrollReply, { passive: true });
        window.addEventListener('resize', updateScrollReply);
        updateScrollReply();
    }

    var scrollTopBtn = document.getElementById('scroll-top');
    var pageHeader = document.querySelector('.page-header');

    function updateScrollTop() {
        if (!scrollTopBtn) {
            return;
        }

        var show = pageHeader
            ? pageHeader.getBoundingClientRect().bottom < 0
            : window.scrollY > 320;
        if (show) {
            scrollTopBtn.removeAttribute('hidden');
        } else {
            scrollTopBtn.setAttribute('hidden', '');
        }
    }

    if (scrollTopBtn) {
        scrollTopBtn.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
        window.addEventListener('scroll', updateScrollTop, { passive: true });
        window.addEventListener('resize', updateScrollTop);
        updateScrollTop();
    }

    window.latchOpenReply = openReplyPanel;

    highlightCodeBlocks();
    window.latchHighlightPostCode = highlightCodeBlocks;

    document.querySelectorAll('[data-quote-post]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (typeof window.latchOpenReply === 'function') {
                window.latchOpenReply({
                    fromQuote: true,
                    author: btn.getAttribute('data-quote-author') || 'user',
                    body: btn.getAttribute('data-quote-body') || '',
                });
            }
        });
    });
})();