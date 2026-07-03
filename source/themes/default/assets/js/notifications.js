(function () {
    'use strict';

    var wrap = document.getElementById('notification-bell-wrap');
    var btn = document.getElementById('notification-bell-btn');
    var panel = document.getElementById('notification-panel');
    var body = document.getElementById('notification-panel-body');
    var statusEl = document.getElementById('notification-panel-status');
    var markAllBtn = document.getElementById('notification-mark-all');
    var badge = document.getElementById('notification-bell-badge');

    if (!wrap || !btn || !panel || !body) {
        return;
    }

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrf = csrfMeta ? csrfMeta.content : '';
    var isOpen = false;
    var isLoading = false;
    var loadedOnce = false;

    function setBadge(count) {
        if (!badge) {
            return;
        }

        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : String(count);
            badge.hidden = false;
            badge.classList.remove('is-hidden');
            btn.classList.add('has-unread');
            btn.setAttribute(
                'aria-label',
                'Notifications (' + count + ' unread)',
            );
        } else {
            badge.hidden = true;
            badge.classList.add('is-hidden');
            btn.classList.remove('has-unread');
            btn.setAttribute('aria-label', 'Notifications');
        }
    }

    function setOpen(open) {
        isOpen = open;
        panel.hidden = !open;
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open && !loadedOnce && !isLoading) {
            loadFeed();
        }
    }

    function closePanel() {
        setOpen(false);
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function renderFeed(payload) {
        var items = payload.notifications || [];
        var unread = payload.unread_count || 0;

        setBadge(unread);

        if (markAllBtn) {
            markAllBtn.hidden = unread <= 0;
        }

        if (items.length === 0) {
            body.innerHTML =
                '<p class="notification-panel-empty muted">No notifications yet. You will be notified when someone replies, quotes, likes, mentions you, or sends a message.</p>';
            return;
        }

        var html = '<ul class="notification-list notification-panel-list" role="list">';
        items.forEach(function (item) {
            var unreadClass = item.is_unread ? ' is-unread' : '';
            var markBtn = item.is_unread
                ? '<button type="button" class="link-button notification-panel-item-mark" data-mark-id="' +
                  item.id +
                  '" title="Mark as read" aria-label="Mark as read">✓</button>'
                : '';
            html +=
                '<li class="notification-item' +
                unreadClass +
                ' type-' +
                escapeHtml(item.event_type || 'default') +
                '" data-id="' +
                item.id +
                '">' +
                '<button type="button" class="notification-item-link notification-panel-item-open" data-id="' +
                item.id +
                '" data-url="' +
                escapeHtml(item.url) +
                '">' +
                '<span class="notification-item-message">' +
                escapeHtml(item.message) +
                '</span>' +
                '<time class="notification-item-time muted" datetime="' +
                escapeHtml(item.created_at) +
                '">' +
                escapeHtml(item.created_at_label || '') +
                '</time>' +
                '</button>' +
                markBtn +
                '</li>';
        });
        html += '</ul>';

        if (payload.has_more) {
            html +=
                '<p class="notification-panel-more muted"><a href="/notifications">Older notifications…</a></p>';
        }

        body.innerHTML = html;
    }

    function loadFeed() {
        isLoading = true;
        if (statusEl) {
            statusEl.textContent = 'Loading…';
        }
        body.innerHTML = '<p class="notification-panel-status muted">Loading…</p>';

        fetch('/notifications/feed', {
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
                    body.innerHTML =
                        '<p class="notification-panel-status muted">Could not load notifications.</p>';
                    return;
                }
                loadedOnce = true;
                renderFeed(result.payload);
            })
            .catch(function () {
                body.innerHTML =
                    '<p class="notification-panel-status muted">Could not load notifications.</p>';
            })
            .finally(function () {
                isLoading = false;
            });
    }

    function postForm(url) {
        var bodyParams = new URLSearchParams();
        bodyParams.set('_csrf', csrf);

        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: bodyParams.toString(),
        }).then(function (res) {
            return res.json().then(function (payload) {
                return { ok: res.ok, payload: payload };
            });
        });
    }

    function openMessagesFromUrl(url) {
        if (!window.LatchMessagesPanel || url.indexOf('/messages') !== 0) {
            return false;
        }

        var match = url.match(/^\/messages\/(\d+)/);
        if (match) {
            window.LatchMessagesPanel.openConversation(parseInt(match[1], 10));
        } else {
            window.LatchMessagesPanel.open(url);
        }
        closePanel();
        return true;
    }

    function openNotification(id, fallbackUrl) {
        fetch('/notifications/' + id + '/go', {
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
        })
            .then(function (res) {
                return res.json().then(function (payload) {
                    return { ok: res.ok, payload: payload };
                });
            })
            .then(function (result) {
                var url = fallbackUrl;
                if (result.ok && result.payload.ok && result.payload.url) {
                    url = result.payload.url;
                    if (typeof result.payload.unread_count === 'number') {
                        setBadge(result.payload.unread_count);
                    }
                }
                if (!openMessagesFromUrl(url)) {
                    window.location.href = url;
                }
            })
            .catch(function () {
                if (!openMessagesFromUrl(fallbackUrl)) {
                    window.location.href = fallbackUrl;
                }
            });
    }

    btn.addEventListener('click', function (event) {
        event.stopPropagation();
        setOpen(!isOpen);
    });

    document.addEventListener('click', function (event) {
        if (!isOpen) {
            return;
        }
        if (!wrap.contains(event.target)) {
            closePanel();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && isOpen) {
            closePanel();
            btn.focus();
        }
    });

    if (markAllBtn) {
        markAllBtn.addEventListener('click', function () {
            markAllBtn.disabled = true;
            postForm('/notifications/read-all')
                .then(function (result) {
                    if (!result.ok || !result.payload.ok) {
                        window.alert(result.payload.message || 'Could not mark all as read.');
                        return;
                    }
                    setBadge(0);
                    loadFeed();
                })
                .catch(function () {
                    window.alert('Could not mark all as read.');
                })
                .finally(function () {
                    markAllBtn.disabled = false;
                });
        });
    }

    body.addEventListener('click', function (event) {
        var openBtn = event.target.closest('.notification-panel-item-open');
        if (openBtn) {
            event.preventDefault();
            openNotification(openBtn.dataset.id, openBtn.dataset.url);
            return;
        }

        var markBtn = event.target.closest('.notification-panel-item-mark');
        if (!markBtn) {
            return;
        }

        event.preventDefault();
        var id = markBtn.dataset.markId;
        markBtn.disabled = true;

        postForm('/notifications/' + id + '/read')
            .then(function (result) {
                if (!result.ok || !result.payload.ok) {
                    window.alert(result.payload.message || 'Could not mark as read.');
                    return;
                }
                if (typeof result.payload.unread_count === 'number') {
                    setBadge(result.payload.unread_count);
                }
                var row = markBtn.closest('.notification-item');
                if (row) {
                    row.classList.remove('is-unread');
                    markBtn.remove();
                }
                if (markAllBtn && result.payload.unread_count === 0) {
                    markAllBtn.hidden = true;
                }
            })
            .catch(function () {
                window.alert('Could not mark as read.');
            })
            .finally(function () {
                markBtn.disabled = false;
            });
    });
})();