/**
 * Copyright (c) 2026 Latch contributors
 * SPDX-License-Identifier: MIT
 */

(function () {
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrf = csrfMeta ? csrfMeta.content : '';

    function showFeedback(container, message, isError) {
        if (!container) {
            return;
        }
        container.textContent = message;
        container.hidden = false;
        container.className = 'staff-action-feedback flash ' + (isError ? 'flash-error' : 'flash-success');
        window.clearTimeout(container._staffTimer);
        container._staffTimer = window.setTimeout(function () {
            container.hidden = true;
        }, 4000);
    }

    function followStaffRedirect(url) {
        if (!url) {
            return false;
        }
        if (window.LatchAccountPanel && window.LatchAccountPanel.navigate(url)) {
            return true;
        }
        window.location.href = url;
        return true;
    }

    function showStaffFeedback(panel, message, isError) {
        if (document.body.classList.contains('page-admin')) {
            var adminFlash = document.getElementById('admin-spa-flash');
            if (adminFlash) {
                adminFlash.textContent = message;
                adminFlash.hidden = false;
                adminFlash.className =
                    'flash admin-spa-flash ' + (isError ? 'flash-error' : 'flash-success');
                window.clearTimeout(adminFlash._staffTimer);
                adminFlash._staffTimer = window.setTimeout(function () {
                    adminFlash.hidden = true;
                }, 4000);
                return;
            }
        }
        showFeedback(findFeedback(panel), message, isError);
    }

    function findFeedback(panel) {
        var scoped = panel.closest('[data-staff-feedback]');
        if (scoped) {
            var el = scoped.querySelector('.staff-action-feedback');
            if (el) {
                return el;
            }
        }
        return document.getElementById('staff-action-feedback');
    }

    var POPOVER_GAP = 6;
    var VIEWPORT_PAD = 8;

    function resetStaffPopover(panel) {
        var popover = panel.querySelector('.staff-action-popover');
        if (!popover) {
            return;
        }
        popover.classList.remove('is-fixed');
        popover.style.position = '';
        popover.style.left = '';
        popover.style.top = '';
        popover.style.right = '';
        popover.style.bottom = '';
        popover.style.zIndex = '';
        popover.style.margin = '';
        popover.style.maxWidth = '';
    }

    function positionStaffPopover(panel) {
        var popover = panel.querySelector('.staff-action-popover');
        var trigger = panel.querySelector('summary');
        if (!popover || !trigger || !panel.open) {
            return;
        }

        popover.classList.add('is-fixed');
        popover.style.position = 'fixed';
        popover.style.right = 'auto';
        popover.style.bottom = 'auto';
        popover.style.margin = '0';
        popover.style.zIndex = '200';
        popover.style.maxWidth = 'min(20rem, calc(100vw - ' + (VIEWPORT_PAD * 2) + 'px))';

        var triggerRect = trigger.getBoundingClientRect();
        var popWidth = popover.offsetWidth;
        var popHeight = popover.offsetHeight;
        var left = triggerRect.right - popWidth;
        var top = triggerRect.bottom + POPOVER_GAP;

        if (left < VIEWPORT_PAD) {
            left = VIEWPORT_PAD;
        }
        if (left + popWidth > window.innerWidth - VIEWPORT_PAD) {
            left = window.innerWidth - popWidth - VIEWPORT_PAD;
        }
        if (top + popHeight > window.innerHeight - VIEWPORT_PAD) {
            top = triggerRect.top - popHeight - POPOVER_GAP;
        }
        if (top < VIEWPORT_PAD) {
            top = VIEWPORT_PAD;
        }

        popover.style.left = Math.round(left) + 'px';
        popover.style.top = Math.round(top) + 'px';
    }

    function closePanels(root, except) {
        (root || document).querySelectorAll('.staff-action-panel').forEach(function (item) {
            if (item !== except) {
                if (item.open) {
                    item.open = false;
                }
                resetStaffPopover(item);
            }
        });
    }

    function repositionOpenPopovers() {
        document.querySelectorAll('.staff-action-panel[open]').forEach(function (panel) {
            positionStaffPopover(panel);
        });
    }

    document.querySelectorAll('.staff-action-panel').forEach(function (panel) {
        panel.addEventListener('toggle', function () {
            if (!panel.open) {
                resetStaffPopover(panel);
                return;
            }
            var scope = panel.closest('[data-staff-feedback]') || document;
            closePanels(scope, panel);
            window.requestAnimationFrame(function () {
                positionStaffPopover(panel);
            });
        });
    });

    window.addEventListener('resize', repositionOpenPopovers);
    window.addEventListener('scroll', repositionOpenPopovers, true);

    function syncBanCustomDays(panel) {
        if (!panel) {
            return;
        }
        var select = panel.querySelector('.ban-duration-select');
        var custom = panel.querySelector('.ban-custom-days');
        if (!select || !custom) {
            return;
        }
        custom.hidden = select.value !== 'custom';
    }

    document.querySelectorAll('.ban-duration-select').forEach(function (select) {
        var panel = select.closest('.staff-action-panel') || select.closest('form');
        syncBanCustomDays(panel);
        select.addEventListener('change', function () {
            syncBanCustomDays(panel);
        });
    });

    function sliceDate(value) {
        if (!value) {
            return '';
        }
        var text = String(value);
        var match = text.match(/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/);
        return match ? match[1] + ' ' + match[2] : text.slice(0, 16).replace('T', ' ');
    }

    function setText(id, value) {
        var el = document.getElementById(id);
        if (el) {
            el.textContent = value;
        }
    }

    function setHidden(id, hidden) {
        var el = document.getElementById(id);
        if (el) {
            el.hidden = hidden;
        }
    }

    function handleAdminUserSuccess(action, data) {
        if (data.role) {
            setText('admin-user-meta-role', data.role);
            setText('admin-user-detail-role', data.role);
        }
        if (typeof data.is_banned !== 'undefined') {
            var banned = !!data.is_banned;
            setHidden('admin-user-ban-badge', !banned);
            setHidden('admin-user-icons-normal', banned);
            setHidden('admin-user-icons-banned', !banned);
            setHidden('admin-user-banned-since-row', !data.banned_at);
            setHidden('admin-user-ban-expires-row', !data.banned_until);
            setHidden('admin-user-ban-reason-row', !data.ban_reason);
            setText('admin-user-banned-since', sliceDate(data.banned_at));
            setText('admin-user-ban-expires', sliceDate(data.banned_until));
            setText('admin-user-ban-reason', data.ban_reason || '');
        } else if (action === 'ban') {
            handleAdminUserSuccess('ban', {
                is_banned: true,
                banned_at: data.banned_at,
                banned_until: data.banned_until,
            });
        }
    }

    function normalizeUserId(userId) {
        return String(userId || '').replace(/[^0-9]/g, '');
    }

    function appendEyeIcon(container, userId, username) {
        var id = normalizeUserId(userId);
        if (!id) {
            return;
        }

        var link = document.createElement('a');
        link.className = 'btn btn-small btn-icon';
        link.href = '/admin/users/' + id;
        var label = 'View ' + String(username || 'user');
        link.title = label;
        link.setAttribute('aria-label', label);
        link.innerHTML =
            '<svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">'
            + '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7-10-7-10-7z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>'
            + '<circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"/></svg>';
        container.appendChild(link);
    }

    function handleUsersRowBan(panel, data) {
        var row = panel.closest('tr');
        if (!row) {
            return;
        }
        var userId = data.user_id || '';
        var username = row.querySelector('.user-cell a');
        var name = username ? username.textContent : 'user';
        row.classList.add('row-muted');
        var statusCell = row.querySelector('[data-user-status]');
        if (statusCell) {
            statusCell.replaceChildren();
            var badge = document.createElement('span');
            badge.className = 'badge badge-muted';
            badge.textContent = 'Banned';
            statusCell.appendChild(badge);
            if (data.banned_until) {
                statusCell.appendChild(document.createTextNode(' '));
                var until = document.createElement('span');
                until.className = 'muted';
                until.textContent = 'until ' + sliceDate(data.banned_until);
                statusCell.appendChild(until);
            }
        }
        var actionsCell = row.querySelector('[data-user-actions]');
        if (actionsCell) {
            actionsCell.replaceChildren();
            var icons = document.createElement('div');
            icons.className = 'staff-action-icons';
            appendEyeIcon(icons, userId, name);
            actionsCell.appendChild(icons);
        }
    }

    function handleUsersRowUnban(panel) {
        if (window.LatchAccountPanel && window.LatchAccountPanel.reloadAdmin()) {
            return;
        }
        window.location.reload();
    }

    function handleTopicModSuccess(panel, data) {
        if (typeof data.is_locked !== 'undefined') {
            var lockBtn = document.querySelector('[data-topic-lock-toggle]');
            var lockBadge = document.querySelector('[data-topic-lock-badge]');
            if (lockBtn) {
                lockBtn.title = data.is_locked ? 'Unlock topic' : 'Lock topic';
                lockBtn.setAttribute('aria-label', data.is_locked ? 'Unlock topic' : 'Lock topic');
                var whenLocked = lockBtn.querySelector('[data-show-when-locked]');
                var whenUnlocked = lockBtn.querySelector('[data-show-when-unlocked]');
                if (whenLocked) {
                    whenLocked.hidden = !data.is_locked;
                }
                if (whenUnlocked) {
                    whenUnlocked.hidden = data.is_locked;
                }
            }
            if (lockBadge) {
                lockBadge.hidden = !data.is_locked;
            }
        }
        if (typeof data.is_pinned !== 'undefined') {
            var pinBtn = document.querySelector('[data-topic-pin-toggle]');
            var pinBadge = document.querySelector('[data-topic-pin-badge]');
            if (pinBtn) {
                pinBtn.title = data.is_pinned ? 'Unpin topic' : 'Pin topic';
                pinBtn.setAttribute('aria-label', data.is_pinned ? 'Unpin topic' : 'Pin topic');
            }
            if (pinBadge) {
                pinBadge.hidden = !data.is_pinned;
            }
        }
        if (data.redirect) {
            followStaffRedirect(data.redirect);
        }
    }

    function applySuccess(panel, data) {
        var mode = panel.dataset.staffSuccess || '';
        var action = panel.dataset.staffAction || '';

        if (mode === 'trash-item') {
            if (data.redirect) {
                followStaffRedirect(data.redirect);
                return;
            }

            var trashEntry = panel.closest('.trash-item') || panel.closest('.post');
            if (trashEntry) {
                trashEntry.remove();
            }
            var trashList = document.querySelector('.trash-list');
            if (trashList && !trashList.querySelector('.trash-item')) {
                var trashEmpty = document.createElement('p');
                trashEmpty.className = 'muted';
                trashEmpty.textContent = 'No posts in the trash queue.';
                trashList.replaceWith(trashEmpty);
            }
            var topicPostList = document.getElementById('topic-post-list');
            if (topicPostList && !topicPostList.querySelector('.post')) {
                topicPostList.innerHTML =
                    '<p class="muted">This archive topic is empty. <a href="/board/mod-trash">Back to moderation trash</a>.</p>';
            }
            return;
        }

        if (mode === 'quarantine-item') {
            var quarantineEntry = panel.closest('.quarantine-item');
            if (quarantineEntry) {
                quarantineEntry.remove();
            }
            var quarantineList = document.querySelector('.quarantine-list');
            if (quarantineList && !quarantineList.querySelector('.quarantine-item')) {
                var quarantineEmpty = document.createElement('p');
                quarantineEmpty.className = 'muted';
                quarantineEmpty.textContent = 'No quarantined posts.';
                quarantineList.replaceWith(quarantineEmpty);
            }
            return;
        }

        if (mode === 'remove-item') {
            var item = panel.closest('.report-item');
            if (item) {
                item.remove();
            }
            var list = document.querySelector('.report-list');
            if (list && !list.querySelector('.report-item')) {
                var empty = document.createElement('p');
                empty.className = 'muted';
                empty.textContent = 'No open reports.';
                list.replaceWith(empty);
            }
            if (window.LatchStaffReports && typeof window.LatchStaffReports.refresh === 'function') {
                window.LatchStaffReports.refresh();
            } else {
                document.dispatchEvent(new CustomEvent('latch:reports-changed'));
            }
            return;
        }

        if (mode === 'admin-user') {
            handleAdminUserSuccess(action, data);
            return;
        }

        if (mode === 'users-row-ban') {
            handleUsersRowBan(panel, data);
            return;
        }

        if (mode === 'users-row-unban') {
            handleUsersRowUnban(panel);
            return;
        }

        if (mode === 'topic-mod') {
            handleTopicModSuccess(panel, data);
            return;
        }

        if (mode === 'remove-post') {
            var post = panel.closest('.post');
            if (post) {
                post.remove();
            }
        }

        if (data.redirect) {
            followStaffRedirect(data.redirect);
        }
    }

    function responseOk(result) {
        return result.ok && result.body && result.body.ok !== false;
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-staff-action-submit]');
        if (!button) {
            return;
        }

        event.preventDefault();
        var panel = button.closest('.staff-action-panel');
        if (!panel) {
            return;
        }

        var url = panel.dataset.staffPost;
        if (!url) {
            return;
        }

        var formData = new FormData();
        formData.append('_csrf', csrf);

        var triageAction = panel.dataset.staffAction;
        if (triageAction) {
            formData.append('action', triageAction);
        }

        panel.querySelectorAll('select[name], textarea[name], input[name]').forEach(function (field) {
            if (!field.name || field.name === '_csrf') {
                return;
            }
            if (field.type === 'submit' || field.type === 'button') {
                return;
            }
            formData.append(field.name, field.value);
        });

        button.disabled = true;

        fetch(url, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
        })
            .then(function (response) {
                return response.text().then(function (text) {
                    var body = {};
                    if (text) {
                        try {
                            body = JSON.parse(text);
                        } catch (error) {
                            return {
                                ok: false,
                                body: {
                                    ok: false,
                                    message: response.ok
                                        ? 'Unexpected server response.'
                                        : 'Request failed (' + response.status + ').',
                                },
                            };
                        }
                    }
                    return { ok: response.ok, body: body };
                });
            })
            .then(function (result) {
                button.disabled = false;
                if (!responseOk(result)) {
                    showStaffFeedback(panel, result.body.message || 'Action failed.', true);
                    return;
                }
                panel.open = false;
                showStaffFeedback(panel, result.body.message, false);
                applySuccess(panel, result.body);
            })
            .catch(function () {
                button.disabled = false;
                showStaffFeedback(panel, 'Request failed.', true);
            });
    });
})();