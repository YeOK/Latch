(function () {
    'use strict';

    var BUILTIN_ACTIONS = {
        bold: true,
        italic: true,
        link: true,
        quote: true,
        code: true,
        'inline-code': true,
        spoiler: true,
    };

    function highlightPreview(panel) {
        if (typeof window.hljs === 'undefined') {
            return;
        }

        panel.querySelectorAll('pre.code-block code').forEach(function (block) {
            window.hljs.highlightElement(block);
        });
    }

    function initComposer(root) {
        var textarea = root.querySelector('.composer-textarea');
        if (!textarea) {
            return;
        }

        var previewUrl = root.getAttribute('data-preview-url') || '/preview';
        var draftKey = root.getAttribute('data-draft-key') || '';
        var previewPanel = root.querySelector('.composer-preview');
        var csrf = document.querySelector('meta[name="csrf-token"]');
        var previewTimer = null;

        if (draftKey) {
            try {
                var saved = localStorage.getItem('latch_draft_' + draftKey);
                if (saved && textarea.value === '') {
                    textarea.value = saved;
                }
            } catch (e) {
                /* ignore */
            }

            textarea.addEventListener('input', function () {
                try {
                    localStorage.setItem('latch_draft_' + draftKey, textarea.value);
                } catch (e) {
                    /* ignore */
                }
            });
        }

        root.querySelectorAll('.composer-tab').forEach(function (tab) {
            tab.addEventListener('click', function () {
                var name = tab.getAttribute('data-tab');
                root.querySelectorAll('.composer-tab').forEach(function (t) {
                    var active = t === tab;
                    t.classList.toggle('is-active', active);
                    t.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                root.querySelectorAll('.composer-panel').forEach(function (panel) {
                    panel.classList.toggle('is-hidden', panel.getAttribute('data-panel') !== name);
                });
                if (name === 'preview') {
                    refreshPreview();
                }
            });
        });

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
                case 'code':
                    insert = '```\n' + (selected || 'code') + '\n```';
                    break;
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

        function refreshPreview() {
            if (!previewPanel || !csrf) {
                return;
            }

            clearTimeout(previewTimer);
            previewTimer = setTimeout(function () {
                previewPanel.textContent = 'Loading preview…';
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
                            highlightPreview(previewPanel);
                        } else {
                            previewPanel.textContent = data.error || 'Preview failed.';
                        }
                    })
                    .catch(function () {
                        previewPanel.textContent = 'Preview unavailable.';
                    });
            }, 250);
        }

        window.latchInsertQuote = function (author, body) {
            var quote = '[quote="' + author + '"]\n' + body + '\n[/quote]\n\n';
            var pos = textarea.value.length;
            textarea.setRangeText(quote, pos, pos, 'end');
            textarea.focus();
            try {
                if (draftKey) {
                    localStorage.setItem('latch_draft_' + draftKey, textarea.value);
                }
            } catch (e) {
                /* ignore */
            }
        };
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

    function openReplyPanel() {
        if (!replyPanel) {
            return;
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

    document.querySelectorAll('[data-quote-post]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var author = btn.getAttribute('data-quote-author') || 'user';
            var body = btn.getAttribute('data-quote-body') || '';
            if (typeof window.latchOpenReply === 'function') {
                window.latchOpenReply();
            }
            if (typeof window.latchInsertQuote === 'function') {
                window.latchInsertQuote(author, body);
            }
        });
    });
})();