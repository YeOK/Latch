/**
 * Copyright (c) 2026 Latch contributors
 * SPDX-License-Identifier: MIT
 */

(function () {
    'use strict';

    var overlay = document.getElementById('messages-overlay');
    var body = document.getElementById('messages-overlay-body');
    var fullPageLink = overlay ? overlay.querySelector('.messages-overlay-full-page') : null;

    var isOpen = false;
    var pushedState = false;
    var loading = false;
    var activeController = null;

    if (!overlay || !body) {
        return;
    }

    function isMessagesPath(pathname) {
        return pathname === '/messages' || pathname.indexOf('/messages/') === 0;
    }

    function isMessagesUrl(href) {
        try {
            var url = new URL(href, window.location.href);
            if (url.origin !== window.location.origin) {
                return false;
            }
            return isMessagesPath(url.pathname);
        } catch (error) {
            return false;
        }
    }

    function shouldBypassLink(link) {
        if (!link || link.target === '_blank' || link.hasAttribute('download')) {
            return true;
        }
        if (link.hasAttribute('data-messages-bypass')) {
            return true;
        }
        if (link.getAttribute('href') === '' || link.getAttribute('href') === '#') {
            return true;
        }
        return false;
    }

    function conversationIdFromPath(pathname) {
        var match = pathname.match(/^\/messages\/(\d+)$/);
        return match ? parseInt(match[1], 10) : 0;
    }

    function syncFullPageLink(url) {
        if (fullPageLink) {
            fullPageLink.href = url;
        }
    }

    function fetchPage(url) {
        return fetch(url, {
            credentials: 'same-origin',
            headers: {
                Accept: 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
            },
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.text();
        });
    }

    function extractMessagesApp(html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        return doc.getElementById('messages-app');
    }

    function closeUserMenu() {
        var panel = document.getElementById('user-menu-panel');
        var btn = document.getElementById('user-menu-toggle');
        if (panel) {
            panel.setAttribute('hidden', '');
        }
        if (btn) {
            btn.setAttribute('aria-expanded', 'false');
        }
    }

    function closeNotificationPanel() {
        var panel = document.getElementById('notification-panel');
        var btn = document.getElementById('notification-bell-btn');
        if (panel) {
            panel.hidden = true;
        }
        if (btn) {
            btn.setAttribute('aria-expanded', 'false');
        }
    }

    function mountApp(appHtml, url) {
        body.innerHTML = appHtml;
        var app = body.querySelector('#messages-app');
        if (!app || !window.LatchMessages) {
            throw new Error('Missing messages app');
        }

        if (activeController && activeController.stopPolling) {
            activeController.stopPolling();
        }

        activeController = window.LatchMessages.init(app, { overlay: true });
        syncFullPageLink(url);
    }

    function loadPanel(url, pushHistory) {
        if (document.body.classList.contains('page-messages')) {
            window.location.href = url;
            return;
        }

        var parsed = new URL(url, window.location.href);
        if (!isMessagesPath(parsed.pathname)) {
            window.location.href = url;
            return;
        }

        loading = true;
        body.innerHTML = '<p class="muted messages-overlay-loading">Loading…</p>';

        fetchPage(parsed.pathname + parsed.search)
            .then(function (html) {
                var app = extractMessagesApp(html);
                if (!app) {
                    throw new Error('Could not parse messages page');
                }
                mountApp(app.outerHTML, parsed.pathname);
                if (pushHistory) {
                    history.pushState({ messagesPanel: parsed.pathname }, '', parsed.pathname);
                    pushedState = true;
                }
            })
            .catch(function () {
                body.innerHTML =
                    '<p class="muted messages-overlay-loading">Could not load messages. <a href="' +
                    url +
                    '">Open full page</a>.</p>';
            })
            .finally(function () {
                loading = false;
            });
    }

    function openPanel(url) {
        isOpen = true;
        overlay.hidden = false;
        document.body.classList.add('messages-overlay-open');
        closeUserMenu();
        closeNotificationPanel();
        loadPanel(url || '/messages', true);

        var navLink = document.getElementById('messages-nav-link');
        if (navLink) {
            navLink.classList.add('is-active');
        }
    }

    function closePanel(useHistory) {
        if (!isOpen) {
            return;
        }
        isOpen = false;
        overlay.hidden = true;
        document.body.classList.remove('messages-overlay-open');
        body.innerHTML = '<p class="muted messages-overlay-loading">Loading…</p>';

        if (activeController && activeController.stopPolling) {
            activeController.stopPolling();
        }
        activeController = null;

        var navLink = document.getElementById('messages-nav-link');
        if (navLink && !document.body.classList.contains('page-messages')) {
            navLink.classList.remove('is-active');
        }

        if (useHistory && pushedState) {
            pushedState = false;
            history.back();
        }
    }

    function handleLinkClick(event, link) {
        if (event.defaultPrevented || event.button !== 0) {
            return;
        }
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        if (shouldBypassLink(link)) {
            return;
        }
        if (!isMessagesUrl(link.href)) {
            return;
        }
        if (document.body.classList.contains('page-messages')) {
            return;
        }

        if (isOpen && link.id === 'messages-nav-link') {
            event.preventDefault();
            closePanel(true);
            return;
        }

        event.preventDefault();
        if (!isOpen) {
            openPanel(link.href);
        } else {
            loadPanel(link.href, true);
        }
    }

    function startWithUsername(username) {
        openPanel('/messages');
        var attempts = 0;

        function tryStart() {
            if (activeController && activeController.startWithUsername) {
                activeController.startWithUsername(username);
                return;
            }
            attempts += 1;
            if (attempts < 40) {
                window.setTimeout(tryStart, 100);
            }
        }

        tryStart();
    }

    function openConversation(conversationId) {
        if (!conversationId) {
            openPanel('/messages');
            return;
        }
        openPanel('/messages/' + conversationId);
    }

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-messages-close]')) {
            event.preventDefault();
            closePanel(true);
            return;
        }

        var link = event.target.closest('a[href]');
        if (link) {
            handleLinkClick(event, link);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && isOpen) {
            closePanel(true);
        }
    });

    window.addEventListener('popstate', function (event) {
        if (event.state && event.state.messagesPanel && isOpen) {
            loadPanel(event.state.messagesPanel, false);
            return;
        }

        if (isOpen) {
            closePanel(false);
        }
    });

    document.querySelectorAll('[data-messages-panel]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            handleLinkClick(event, link);
        });
    });

    document.addEventListener('click', function (event) {
        var profileBtn = event.target.closest('.profile-message-btn');
        if (!profileBtn || loading) {
            return;
        }
        event.preventDefault();
        var username = profileBtn.dataset.username || '';
        if (!username) {
            return;
        }

        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        var csrf = csrfMeta ? csrfMeta.content : '';
        var bodyParams = new URLSearchParams();
        bodyParams.set('_csrf', csrf);
        bodyParams.set('username', username);

        fetch('/messages/start', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: bodyParams.toString(),
        })
            .then(function (res) {
                return res.json().then(function (payload) {
                    return { ok: res.ok, payload: payload };
                });
            })
            .then(function (result) {
                if (result.ok && result.payload.ok && result.payload.conversation_id) {
                    openConversation(result.payload.conversation_id);
                    return;
                }
                window.alert((result.payload && result.payload.message) || 'Could not open conversation.');
            })
            .catch(function () {
                window.alert('Could not open conversation.');
            });
    });

    window.LatchMessagesPanel = {
        open: openPanel,
        close: closePanel,
        openConversation: openConversation,
        startWithUsername: startWithUsername,
        syncFullPageLink: syncFullPageLink,
    };
})();