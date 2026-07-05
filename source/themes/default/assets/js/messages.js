(function () {
    'use strict';

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var defaultCsrf = csrfMeta ? csrfMeta.content : '';

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function initMessagesApp(app, options) {
        options = options || {};
        var isOverlay = !!options.overlay;

        if (!app || app.dataset.messagesBound === '1') {
            return null;
        }
        app.dataset.messagesBound = '1';

        var csrf = app.dataset.csrf || defaultCsrf;
        var activeId = parseInt(app.dataset.activeId || '0', 10) || 0;
        var pollTimer = null;
        var lastMessageId = 0;

        var listEl = app.querySelector('#messages-conversation-list');
        var listStatus = app.querySelector('#messages-list-status');
        var threadEmpty = app.querySelector('#messages-thread-empty');
        var threadPanel = app.querySelector('#messages-thread-panel');
        var threadBody = app.querySelector('#messages-thread-body');
        var threadTitle = app.querySelector('#messages-thread-title');
        var threadSubtitle = app.querySelector('#messages-thread-subtitle');
        var threadProfile = app.querySelector('#messages-thread-profile');
        var deleteConversationBtn = app.querySelector('#messages-delete-conversation-btn');
        var sendForm = app.querySelector('#messages-send-form');
        var sendInput = app.querySelector('#messages-send-input');
        var sendError = app.querySelector('#messages-send-error');
        var navBadge = document.getElementById('messages-nav-badge');
        var navLink = document.getElementById('messages-nav-link');

        function setNavBadge(count) {
            if (!navBadge || !navLink) {
                return;
            }

            if (count > 0) {
                navBadge.textContent = count > 99 ? '99+' : String(count);
                navBadge.hidden = false;
                navBadge.classList.remove('is-hidden');
                navLink.classList.add('has-unread');
                navLink.setAttribute('title', 'Messages (' + count + ' unread)');
                navLink.setAttribute('aria-label', 'Messages (' + count + ' unread)');
            } else {
                navBadge.hidden = true;
                navBadge.classList.add('is-hidden');
                navLink.classList.remove('has-unread');
                navLink.setAttribute('title', 'Messages');
                navLink.setAttribute('aria-label', 'Messages');
            }
        }

        function postForm(url, fields) {
            var body = new URLSearchParams();
            body.set('_csrf', csrf);
            Object.keys(fields || {}).forEach(function (key) {
                body.set(key, fields[key]);
            });

            return fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: body.toString(),
            }).then(function (res) {
                return res.json().then(function (payload) {
                    return { ok: res.ok, payload: payload };
                });
            });
        }

        function renderConversationItem(item) {
            var unreadClass = item.unread ? ' is-unread' : '';
            var activeClass = item.id === activeId ? ' is-active' : '';
            var preview = item.last_message ? escapeHtml(item.last_message.preview || '') : 'No messages yet';
            var staffBadge = item.other_user.is_staff
                ? '<span class="badge badge-muted messages-staff-badge">Staff</span>'
                : '';

            return (
                '<button type="button" class="messages-conversation-item' +
                unreadClass +
                activeClass +
                '" data-id="' +
                item.id +
                '" role="listitem">' +
                '<span class="messages-conversation-peer">' +
                escapeHtml(item.other_user.username) +
                staffBadge +
                '</span>' +
                '<span class="messages-conversation-preview muted">' +
                preview +
                '</span>' +
                (item.last_message
                    ? '<time class="messages-conversation-time muted">' +
                      escapeHtml(item.last_message.created_at_label || '') +
                      '</time>'
                    : '') +
                '</button>'
            );
        }

        function loadConversations() {
            if (listStatus) {
                listStatus.textContent = 'Loading conversations…';
                listStatus.hidden = false;
            }

            return fetch('/messages/feed', {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            })
                .then(function (res) {
                    return res.json().then(function (payload) {
                        return { ok: res.ok, payload: payload };
                    });
                })
                .then(function (result) {
                    if (!result.ok || !result.payload.ok) {
                        if (listEl) {
                            listEl.innerHTML =
                                '<p class="muted messages-status">Could not load conversations.</p>';
                        }
                        return;
                    }

                    if (typeof result.payload.unread_count === 'number') {
                        setNavBadge(result.payload.unread_count);
                    }

                    var items = result.payload.conversations || [];
                    if (!listEl) {
                        return;
                    }

                    if (items.length === 0) {
                        listEl.innerHTML =
                            '<p class="muted messages-status">No conversations yet. Start one with the compose button.</p>';
                        return;
                    }

                    listEl.innerHTML = items.map(renderConversationItem).join('');
                })
                .catch(function () {
                    if (listEl) {
                        listEl.innerHTML =
                            '<p class="muted messages-status">Could not load conversations.</p>';
                    }
                });
        }

        function renderMessage(msg) {
            var mineClass = msg.is_mine ? ' is-mine' : '';
            var warningClass = msg.is_warning ? ' is-warning' : '';
            var label = msg.is_mine ? 'You' : escapeHtml((msg.sender && msg.sender.username) || 'User');
            if (msg.is_warning) {
                label = escapeHtml((msg.sender && msg.sender.username) || 'Staff') + ' — Warning';
            }

            var deleteBtn = msg.can_delete
                ? '<button type="button" class="btn btn-small btn-icon messages-delete-btn" data-message-delete="' +
                  msg.id +
                  '" title="Delete message" aria-label="Delete message">' +
                  '<svg class="icon" width="14" height="14" viewBox="0 0 24 24" aria-hidden="true">' +
                  '<path fill="currentColor" d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12ZM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4Z"/>' +
                  '</svg></button>'
                : '';

            return (
                '<article class="messages-bubble' +
                mineClass +
                warningClass +
                '" data-id="' +
                msg.id +
                '">' +
                '<header class="messages-bubble-meta">' +
                '<span class="messages-bubble-author">' +
                label +
                '</span>' +
                '<span class="messages-bubble-actions">' +
                deleteBtn +
                '<time class="muted">' +
                escapeHtml(msg.created_at_label || '') +
                '</time></span>' +
                '</header>' +
                '<div class="messages-bubble-body post-content">' +
                (msg.body_html || escapeHtml(msg.body || '')) +
                '</div>' +
                '</article>'
            );
        }

        function deleteMessage(messageId) {
            if (!activeId || !messageId) {
                return Promise.resolve();
            }
            if (!window.confirm('Delete this message?')) {
                return Promise.resolve();
            }

            return postForm('/messages/' + activeId + '/delete', { message_id: String(messageId) })
                .then(function (result) {
                    if (!result.ok || !result.payload.ok) {
                        if (sendError) {
                            sendError.textContent =
                                (result.payload && result.payload.message) || 'Could not delete message.';
                            sendError.hidden = false;
                        }
                        return;
                    }

                    if (typeof result.payload.unread_count === 'number') {
                        setNavBadge(result.payload.unread_count);
                    }

                    var bubble = threadBody
                        ? threadBody.querySelector('.messages-bubble[data-id="' + messageId + '"]')
                        : null;
                    if (bubble) {
                        bubble.remove();
                    }

                    if (threadBody && !threadBody.querySelector('.messages-bubble')) {
                        threadBody.innerHTML =
                            '<p class="muted messages-status">No messages yet. Say hello.</p>';
                        lastMessageId = 0;
                        setDeleteConversationVisible(true);
                    }

                    loadConversations();
                })
                .catch(function () {
                    if (sendError) {
                        sendError.textContent = 'Could not delete message.';
                        sendError.hidden = false;
                    }
                });
        }

        function scrollThreadToBottom() {
            if (!threadBody) {
                return;
            }
            threadBody.scrollTop = threadBody.scrollHeight;
        }

        function setDeleteConversationVisible(canDelete) {
            if (!deleteConversationBtn) {
                return;
            }
            deleteConversationBtn.hidden = !canDelete;
            deleteConversationBtn.classList.toggle('hidden', !canDelete);
        }

        function showThreadPanel(conversation) {
            if (threadEmpty) {
                threadEmpty.hidden = true;
            }
            if (threadPanel) {
                threadPanel.hidden = false;
                threadPanel.classList.remove('hidden');
            }
            if (conversation && conversation.other_user) {
                if (threadTitle) {
                    threadTitle.textContent = conversation.other_user.username;
                }
                if (threadSubtitle) {
                    threadSubtitle.textContent = conversation.other_user.is_staff
                        ? 'Staff account'
                        : '';
                }
                if (threadProfile) {
                    threadProfile.href = '/user/' + encodeURIComponent(conversation.other_user.username);
                }
                setDeleteConversationVisible(!!conversation.can_delete_conversation);
            } else {
                setDeleteConversationVisible(false);
            }
            app.classList.add('has-active-thread');
        }

        function hideThreadPanel() {
            if (threadEmpty) {
                threadEmpty.hidden = false;
            }
            if (threadPanel) {
                threadPanel.hidden = true;
                threadPanel.classList.add('hidden');
            }
            setDeleteConversationVisible(false);
            app.classList.remove('has-active-thread');
            stopPolling();
        }

        function deleteConversation() {
            if (!activeId) {
                return Promise.resolve();
            }
            if (!window.confirm('Delete this conversation?')) {
                return Promise.resolve();
            }

            return postForm('/messages/' + activeId + '/delete-conversation', {})
                .then(function (result) {
                    if (!result.ok || !result.payload.ok) {
                        if (sendError) {
                            sendError.textContent =
                                (result.payload && result.payload.message) ||
                                'Could not delete conversation.';
                            sendError.hidden = false;
                        }
                        return;
                    }

                    if (typeof result.payload.unread_count === 'number') {
                        setNavBadge(result.payload.unread_count);
                    }

                    resetToList(true);
                    return loadConversations();
                })
                .catch(function () {
                    if (sendError) {
                        sendError.textContent = 'Could not delete conversation.';
                        sendError.hidden = false;
                    }
                });
        }

        function loadThread(id, afterId) {
            if (!id) {
                return Promise.resolve();
            }

            var url = '/messages/' + id + '/feed';
            if (afterId) {
                url += '?after=' + encodeURIComponent(String(afterId));
            } else if (threadBody) {
                threadBody.innerHTML =
                    '<p class="muted messages-status" id="messages-thread-status">Loading messages…</p>';
            }

            return fetch(url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            })
                .then(function (res) {
                    return res.json().then(function (payload) {
                        return { ok: res.ok, payload: payload };
                    });
                })
                .then(function (result) {
                    if (!result.ok || !result.payload.ok) {
                        if (threadBody) {
                            threadBody.innerHTML =
                                '<p class="muted messages-status">Could not load messages.</p>';
                        }
                        return;
                    }

                    if (typeof result.payload.unread_count === 'number') {
                        setNavBadge(result.payload.unread_count);
                    }

                    showThreadPanel(result.payload.conversation);
                    var messages = result.payload.messages || [];

                    if (afterId && messages.length > 0 && threadBody) {
                        messages.forEach(function (msg) {
                            threadBody.insertAdjacentHTML('beforeend', renderMessage(msg));
                            lastMessageId = Math.max(lastMessageId, msg.id);
                        });
                        scrollThreadToBottom();
                        return;
                    }

                    if (!threadBody) {
                        return;
                    }

                    if (messages.length === 0) {
                        threadBody.innerHTML =
                            '<p class="muted messages-status">No messages yet. Say hello.</p>';
                        lastMessageId = 0;
                        if (result.payload.conversation) {
                            setDeleteConversationVisible(
                                !!result.payload.conversation.can_delete_conversation,
                            );
                        }
                        return;
                    }

                    threadBody.innerHTML = messages.map(renderMessage).join('');
                    lastMessageId = messages[messages.length - 1].id;
                    scrollThreadToBottom();
                })
                .catch(function () {
                    if (threadBody) {
                        threadBody.innerHTML =
                            '<p class="muted messages-status">Could not load messages.</p>';
                    }
                });
        }

        function openConversation(id, pushState) {
            activeId = id;
            app.dataset.activeId = String(id);

            if (!isOverlay && pushState !== false) {
                history.pushState({ conversationId: id }, '', '/messages/' + id);
            }

            if (isOverlay && window.LatchMessagesPanel) {
                window.LatchMessagesPanel.syncFullPageLink('/messages/' + id);
            }

            var items = listEl ? listEl.querySelectorAll('.messages-conversation-item') : [];
            items.forEach(function (el) {
                el.classList.toggle('is-active', parseInt(el.dataset.id, 10) === id);
            });

            lastMessageId = 0;
            return loadThread(id).then(function () {
                startPolling();
                return loadConversations();
            });
        }

        function resetToList(pushState) {
            activeId = 0;
            app.dataset.activeId = '';
            if (!isOverlay && pushState !== false) {
                history.pushState({}, '', '/messages');
            }
            if (isOverlay && window.LatchMessagesPanel) {
                window.LatchMessagesPanel.syncFullPageLink('/messages');
            }
            hideThreadPanel();
            if (listEl) {
                listEl.querySelectorAll('.messages-conversation-item.is-active').forEach(function (el) {
                    el.classList.remove('is-active');
                });
            }
        }

        function startPolling() {
            stopPolling();
            if (!activeId) {
                return;
            }
            pollTimer = window.setInterval(function () {
                if (!activeId || !lastMessageId) {
                    return;
                }
                loadThread(activeId, lastMessageId);
            }, 20000);
        }

        function stopPolling() {
            if (pollTimer !== null) {
                window.clearInterval(pollTimer);
                pollTimer = null;
            }
        }

        if (listEl) {
            listEl.addEventListener('click', function (event) {
                var btn = event.target.closest('.messages-conversation-item');
                if (!btn) {
                    return;
                }
                openConversation(parseInt(btn.dataset.id, 10));
            });
        }

        var composeBtn = app.querySelector('#messages-compose-btn');
        var composePanel = app.querySelector('#messages-compose');
        if (composeBtn && composePanel) {
            composeBtn.addEventListener('click', function () {
                var open = composePanel.hidden;
                composePanel.hidden = !open;
                composePanel.classList.toggle('hidden', !open);
                if (open) {
                    var input = app.querySelector('#messages-compose-username');
                    if (input) {
                        input.focus();
                    }
                }
            });
        }

        var composeStart = app.querySelector('#messages-compose-start');
        var composeInput = app.querySelector('#messages-compose-username');
        var composeError = app.querySelector('#messages-compose-error');
        if (composeStart && composeInput) {
            function startConversation() {
                var username = composeInput.value.replace(/^@/, '').trim();
                if (!username) {
                    if (composeError) {
                        composeError.textContent = 'Enter a username.';
                        composeError.hidden = false;
                    }
                    return;
                }

                composeStart.disabled = true;
                if (composeError) {
                    composeError.hidden = true;
                }

                postForm('/messages/start', { username: username })
                    .then(function (result) {
                        if (!result.ok || !result.payload.ok) {
                            if (composeError) {
                                composeError.textContent =
                                    (result.payload && result.payload.message) ||
                                    'Could not start conversation.';
                                composeError.hidden = false;
                            }
                            return;
                        }

                        composeInput.value = '';
                        if (composePanel) {
                            composePanel.hidden = true;
                            composePanel.classList.add('hidden');
                        }
                        loadConversations().then(function () {
                            openConversation(result.payload.conversation_id);
                        });
                    })
                    .catch(function () {
                        if (composeError) {
                            composeError.textContent = 'Could not start conversation.';
                            composeError.hidden = false;
                        }
                    })
                    .finally(function () {
                        composeStart.disabled = false;
                    });
            }

            composeStart.addEventListener('click', startConversation);
            composeInput.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    startConversation();
                }
            });
        }

        var refreshBtn = app.querySelector('#messages-refresh-btn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', function () {
                loadConversations();
                if (activeId) {
                    loadThread(activeId);
                }
            });
        }

        var backBtn = app.querySelector('#messages-back-btn');
        if (backBtn) {
            backBtn.addEventListener('click', function () {
                resetToList(true);
            });
        }

        if (deleteConversationBtn) {
            deleteConversationBtn.addEventListener('click', function () {
                deleteConversation();
            });
        }

        if (threadBody) {
            threadBody.addEventListener('click', function (event) {
                var deleteBtn = event.target.closest('[data-message-delete]');
                if (!deleteBtn) {
                    return;
                }
                event.preventDefault();
                deleteMessage(parseInt(deleteBtn.getAttribute('data-message-delete'), 10));
            });
        }

        if (sendForm && sendInput) {
            sendForm.addEventListener('submit', function (event) {
                event.preventDefault();
                if (!activeId) {
                    return;
                }

                var body = sendInput.value.trim();
                if (!body) {
                    return;
                }

                if (sendError) {
                    sendError.hidden = true;
                }

                var submitBtn = app.querySelector('#messages-send-btn');
                if (submitBtn) {
                    submitBtn.disabled = true;
                }

                postForm('/messages/' + activeId + '/send', { body: body })
                    .then(function (result) {
                        if (!result.ok || !result.payload.ok) {
                            if (sendError) {
                                sendError.textContent =
                                    (result.payload && result.payload.message) ||
                                    'Could not send message.';
                                sendError.hidden = false;
                            }
                            return;
                        }

                        sendInput.value = '';
                        if (result.payload.message && threadBody) {
                            var empty = threadBody.querySelector('.messages-status');
                            if (empty) {
                                empty.remove();
                            }
                            threadBody.insertAdjacentHTML('beforeend', renderMessage(result.payload.message));
                            lastMessageId = Math.max(lastMessageId, result.payload.message.id);
                            scrollThreadToBottom();
                        }
                        loadConversations();
                    })
                    .catch(function () {
                        if (sendError) {
                            sendError.textContent = 'Could not send message.';
                            sendError.hidden = false;
                        }
                    })
                    .finally(function () {
                        if (submitBtn) {
                            submitBtn.disabled = false;
                        }
                        sendInput.focus();
                    });
            });
        }

        if (!isOverlay) {
            window.addEventListener('popstate', function (event) {
                if (!document.body.contains(app)) {
                    return;
                }
                var id = event.state && event.state.conversationId ? event.state.conversationId : 0;
                if (id) {
                    openConversation(id, false);
                } else {
                    resetToList(false);
                }
            });
        }

        loadConversations().then(function () {
            if (activeId > 0) {
                openConversation(activeId, false);
            }
        });

        return {
            openConversation: openConversation,
            resetToList: resetToList,
            startWithUsername: function (username) {
                if (!composeInput) {
                    return Promise.resolve();
                }
                composeInput.value = username.replace(/^@/, '');
                if (composePanel) {
                    composePanel.hidden = false;
                    composePanel.classList.remove('hidden');
                }
                if (composeStart) {
                    composeStart.click();
                }
                return Promise.resolve();
            },
            stopPolling: stopPolling,
        };
    }

    function boot() {
        var pageApp = document.getElementById('messages-app');
        if (pageApp && document.body.classList.contains('page-messages')) {
            initMessagesApp(pageApp, { overlay: false });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    window.LatchMessages = {
        init: initMessagesApp,
    };
})();