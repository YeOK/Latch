/**
 * Copyright (c) 2026 Latch contributors
 * SPDX-License-Identifier: MIT
 */

(function () {
    'use strict';

    var wrap = document.getElementById('mod-mode-wrap');
    if (!wrap) {
        return;
    }

    var topicId = parseInt(wrap.dataset.topicId || '0', 10);
    var modStorageKey = 'latch-mod-mode-' + topicId;
    var boards = [];
    try {
        boards = JSON.parse(wrap.dataset.modBoards || '[]');
    } catch (error) {
        boards = [];
    }

    var toggle = document.getElementById('mod-mode-toggle');
    var toolbar = document.getElementById('mod-toolbar');
    var selectAll = document.getElementById('mod-select-all');
    var countEl = document.getElementById('mod-selection-count');
    var dialog = document.getElementById('mod-dialog');
    var dialogTitle = document.getElementById('mod-dialog-title');
    var dialogBody = document.getElementById('mod-dialog-body');
    var dialogFooter = document.getElementById('mod-dialog-footer');
    var feedback = document.getElementById('staff-action-feedback');
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrf = csrfMeta ? csrfMeta.content : '';

    var modActive = false;
    var loading = false;

    function persistModActive(active) {
        try {
            if (active) {
                sessionStorage.setItem(modStorageKey, '1');
            } else {
                sessionStorage.removeItem(modStorageKey);
            }
        } catch (error) {
            /* ignore */
        }
    }

    function readPersistedModActive() {
        try {
            return sessionStorage.getItem(modStorageKey) === '1';
        } catch (error) {
            return false;
        }
    }

    function postCheckboxes() {
        return Array.prototype.slice.call(document.querySelectorAll('.mod-post-checkbox'));
    }

    function selectedIds() {
        return postCheckboxes()
            .filter(function (box) {
                return box.checked;
            })
            .map(function (box) {
                return parseInt(box.value, 10);
            })
            .filter(function (id) {
                return id > 0;
            });
    }

    function selectedQuarantinedCount() {
        return selectedIds().filter(function (id) {
            var post = document.getElementById('post-' + id);
            return post && post.dataset.postQuarantined === '1';
        }).length;
    }

    /** Earliest selected reply in chronological thread order (not the topic opener). */
    function selectedSplitPostId() {
        var bestId = null;
        var bestSeq = null;

        postCheckboxes().forEach(function (box) {
            if (!box.checked) {
                return;
            }
            var id = parseInt(box.value, 10);
            if (id <= 0) {
                return;
            }
            var postEl = document.getElementById('post-' + id);
            if (!postEl || postEl.dataset.postOp === '1') {
                return;
            }
            var seq = parseInt(postEl.dataset.postSeq || '0', 10);
            if (seq <= 0) {
                return;
            }
            if (bestSeq === null || seq < bestSeq) {
                bestSeq = seq;
                bestId = id;
            }
        });

        return bestId;
    }

    function updateSelectionUi() {
        var ids = selectedIds();
        var count = ids.length;
        var quarantinedSelected = selectedQuarantinedCount();
        var splitPostId = selectedSplitPostId();
        if (countEl) {
            countEl.textContent = count + ' selected';
        }
        document.querySelectorAll('[data-mod-action]').forEach(function (btn) {
            var action = btn.getAttribute('data-mod-action');
            if (action === 'merge' || action === 'move') {
                btn.disabled = loading;
                return;
            }
            if (action === 'split') {
                btn.disabled = loading || splitPostId === null;
                return;
            }
            if (action === 'quarantine') {
                btn.disabled = loading || count === 0 || quarantinedSelected === count;
                return;
            }
            if (action === 'lift-quarantine') {
                btn.disabled = loading || quarantinedSelected === 0;
                return;
            }
            btn.disabled = loading || count === 0;
        });
        if (selectAll) {
            var boxes = postCheckboxes();
            selectAll.checked = boxes.length > 0 && count === boxes.length;
            selectAll.indeterminate = count > 0 && count < boxes.length;
        }
    }

    function setModActive(active) {
        modActive = active;
        persistModActive(active);
        document.body.classList.toggle('topic-mod-mode', active);
        if (toggle) {
            toggle.setAttribute('aria-pressed', active ? 'true' : 'false');
            toggle.classList.toggle('is-active', active);
        }
        if (toolbar) {
            toolbar.classList.toggle('is-active', active);
        }
        if (!active) {
            postCheckboxes().forEach(function (box) {
                box.checked = false;
            });
        }
        if (!active && selectAll) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
        }
        if (!active) {
            closeDialog();
        }
        updateSelectionUi();
    }

    function showFeedback(message, isError) {
        if (!feedback) {
            return;
        }
        feedback.textContent = message;
        feedback.hidden = false;
        feedback.className =
            'staff-action-feedback flash ' + (isError ? 'flash-error' : 'flash-success');
        window.clearTimeout(feedback._modTimer);
        feedback._modTimer = window.setTimeout(function () {
            feedback.hidden = true;
        }, 5000);
    }

    function staffFetch(url, formData) {
        loading = true;
        updateSelectionUi();
        return fetch(url, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
        })
            .then(function (response) {
                return response.text().then(function (text) {
                    var body = {};
                    if (text) {
                        try {
                            body = JSON.parse(text);
                        } catch (error) {
                            body = { ok: false, message: 'Unexpected server response.' };
                        }
                    }
                    return { ok: response.ok, body: body };
                });
            })
            .finally(function () {
                loading = false;
                updateSelectionUi();
            });
    }

    function removeSelectedPosts() {
        selectedIds().forEach(function (id) {
            var post = document.getElementById('post-' + id);
            if (post) {
                post.remove();
            }
        });
        updateSelectionUi();
    }

    function reloadKeepingModMode() {
        if (modActive) {
            persistModActive(true);
        }
        window.location.reload();
    }

    function reloadOnRedirect(body) {
        if (modActive) {
            persistModActive(true);
        }
        if (body.redirect) {
            window.location.href = body.redirect;
        } else {
            window.location.reload();
        }
    }

    function confirmRemove() {
        var ids = selectedIds();
        if (ids.length === 0) {
            return;
        }
        if (
            !window.confirm(
                'Remove ' +
                    ids.length +
                    ' post(s) to the moderation trash board? Staff can restore or permanently delete them.',
            )
        ) {
            return;
        }

        var formData = new FormData();
        formData.append('_csrf', csrf);
        formData.append('topic_id', String(topicId));
        ids.forEach(function (id) {
            formData.append('post_ids[]', String(id));
        });

        staffFetch('/mod/posts/trash', formData).then(function (result) {
            if (!result.ok || !result.body.ok) {
                showFeedback(result.body.message || 'Could not remove posts.', true);
                return;
            }
            showFeedback(result.body.message, false);
            removeSelectedPosts();
            closeDialog();
        });
    }

    function confirmQuarantine() {
        var ids = selectedIds();
        if (ids.length === 0) {
            return;
        }
        if (!window.confirm('Quarantine ' + ids.length + ' post(s)? Members will see blurred content.')) {
            return;
        }

        var formData = new FormData();
        formData.append('_csrf', csrf);
        formData.append('topic_id', String(topicId));
        ids.forEach(function (id) {
            formData.append('post_ids[]', String(id));
        });

        staffFetch('/mod/posts/quarantine', formData).then(function (result) {
            if (!result.ok || !result.body.ok) {
                showFeedback(result.body.message || 'Could not quarantine posts.', true);
                return;
            }
            showFeedback(result.body.message, false);
            reloadKeepingModMode();
        });
    }

    function confirmLiftQuarantine() {
        var ids = selectedIds().filter(function (id) {
            var post = document.getElementById('post-' + id);
            return post && post.dataset.postQuarantined === '1';
        });
        if (ids.length === 0) {
            return;
        }
        if (!window.confirm('Lift quarantine on ' + ids.length + ' post(s)? Members will see the content again.')) {
            return;
        }

        var formData = new FormData();
        formData.append('_csrf', csrf);
        formData.append('topic_id', String(topicId));
        ids.forEach(function (id) {
            formData.append('post_ids[]', String(id));
        });

        staffFetch('/mod/posts/lift-quarantine', formData).then(function (result) {
            if (!result.ok || !result.body.ok) {
                showFeedback(result.body.message || 'Could not lift quarantine.', true);
                return;
            }
            showFeedback(result.body.message, false);
            reloadKeepingModMode();
        });
    }

    function openDialog(title) {
        if (!dialog) {
            return;
        }
        if (dialogBody) {
            dialogBody.innerHTML = '';
        }
        if (dialogFooter) {
            dialogFooter.innerHTML = '';
        }
        dialog.hidden = false;
        if (dialogTitle) {
            dialogTitle.textContent = title;
        }
    }

    function closeDialog() {
        if (!dialog) {
            return;
        }
        dialog.hidden = true;
        if (dialogBody) {
            dialogBody.innerHTML = '';
        }
        if (dialogFooter) {
            dialogFooter.innerHTML = '';
        }
    }

    function dialogSubmitButton(label, onClick) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-small btn-primary';
        btn.textContent = label;
        btn.addEventListener('click', onClick);
        return btn;
    }

    function dialogCancelButton() {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-small';
        btn.textContent = 'Cancel';
        btn.addEventListener('click', closeDialog);
        return btn;
    }

    function openMoveDialog() {
        openDialog('Move topic');
        if (!dialogBody || !dialogFooter) {
            return;
        }

        var label = document.createElement('label');
        label.className = 'staff-action-label';
        label.innerHTML = 'Destination board<select name="board_id" id="mod-move-board" required></select>';
        var select = label.querySelector('select');
        boards.forEach(function (board) {
            var opt = document.createElement('option');
            opt.value = String(board.id);
            opt.textContent = board.name;
            select.appendChild(opt);
        });
        dialogBody.appendChild(label);

        dialogFooter.appendChild(dialogCancelButton());
        dialogFooter.appendChild(
            dialogSubmitButton('Move topic', function () {
                var formData = new FormData();
                formData.append('_csrf', csrf);
                formData.append('board_id', select.value);
                staffFetch('/mod/topic/' + topicId + '/move', formData).then(function (result) {
                    if (!result.ok || !result.body.ok) {
                        showFeedback(result.body.message || 'Could not move topic.', true);
                        return;
                    }
                    showFeedback(result.body.message, false);
                    reloadOnRedirect(result.body);
                });
            }),
        );
    }

    function openMergeDialog() {
        openDialog('Merge into another topic');
        if (!dialogBody || !dialogFooter) {
            return;
        }

        var label = document.createElement('label');
        label.className = 'staff-action-label';
        label.innerHTML =
            'Target topic ID<input type="number" id="mod-merge-target" min="1" step="1" required placeholder="e.g. 42">';
        dialogBody.appendChild(label);
        var note = document.createElement('p');
        note.className = 'staff-action-confirm muted';
        note.textContent = 'All posts here will join the target topic. This topic will be removed.';
        dialogBody.appendChild(note);

        dialogFooter.appendChild(dialogCancelButton());
        dialogFooter.appendChild(
            dialogSubmitButton('Merge topics', function () {
                var target = document.getElementById('mod-merge-target');
                var formData = new FormData();
                formData.append('_csrf', csrf);
                formData.append('target_topic_id', target ? target.value : '');
                staffFetch('/mod/topic/' + topicId + '/merge', formData).then(function (result) {
                    if (!result.ok || !result.body.ok) {
                        showFeedback(result.body.message || 'Could not merge topics.', true);
                        return;
                    }
                    showFeedback(result.body.message, false);
                    reloadOnRedirect(result.body);
                });
            }),
        );
    }

    function openSplitDialog() {
        var postId = selectedSplitPostId();
        if (postId === null) {
            showFeedback(
                'Select at least one reply to split from (not the first post in the topic).',
                true,
            );
            return;
        }

        openDialog('Split thread');
        if (!dialogBody || !dialogFooter) {
            return;
        }

        var label = document.createElement('label');
        label.className = 'staff-action-label';
        label.innerHTML =
            'New topic title<input type="text" id="mod-split-title" maxlength="255" required placeholder="Title for split topic">';
        dialogBody.appendChild(label);
        var note = document.createElement('p');
        note.className = 'staff-action-confirm muted';
        note.textContent =
            'The earliest selected reply and all posts after it become a new topic.';
        dialogBody.appendChild(note);

        dialogFooter.appendChild(dialogCancelButton());
        dialogFooter.appendChild(
            dialogSubmitButton('Split thread', function () {
                var titleInput = document.getElementById('mod-split-title');
                var formData = new FormData();
                formData.append('_csrf', csrf);
                formData.append('post_id', String(postId));
                formData.append('title', titleInput ? titleInput.value : '');
                staffFetch('/mod/topic/' + topicId + '/split', formData).then(function (result) {
                    if (!result.ok || !result.body.ok) {
                        showFeedback(result.body.message || 'Could not split topic.', true);
                        return;
                    }
                    showFeedback(result.body.message, false);
                    reloadOnRedirect(result.body);
                });
            }),
        );
    }

    if (toggle) {
        toggle.addEventListener('click', function () {
            setModActive(!modActive);
        });
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            var checked = selectAll.checked;
            postCheckboxes().forEach(function (box) {
                box.checked = checked;
            });
            updateSelectionUi();
        });
    }

    document.addEventListener('change', function (event) {
        if (event.target.classList.contains('mod-post-checkbox')) {
            updateSelectionUi();
        }
    });

    document.addEventListener('click', function (event) {
        var actionBtn = event.target.closest('[data-mod-action]');
        if (
            actionBtn &&
            actionBtn.disabled &&
            actionBtn.getAttribute('data-mod-action') === 'split'
        ) {
            showFeedback(
                'Select at least one reply to split from (not the first post in the topic).',
                true,
            );
            return;
        }

        if (actionBtn && !actionBtn.disabled) {
            var action = actionBtn.getAttribute('data-mod-action');
            if (action === 'remove') {
                confirmRemove();
            } else if (action === 'quarantine') {
                confirmQuarantine();
            } else if (action === 'lift-quarantine') {
                confirmLiftQuarantine();
            } else if (action === 'move') {
                openMoveDialog();
            } else if (action === 'merge') {
                openMergeDialog();
            } else if (action === 'split') {
                openSplitDialog();
            }
            return;
        }

        if (event.target.closest('[data-mod-dialog-close]')) {
            closeDialog();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            if (modActive && dialog && !dialog.hidden) {
                closeDialog();
                return;
            }
            if (modActive) {
                setModActive(false);
            }
        }
    });

    updateSelectionUi();

    if (readPersistedModActive()) {
        setModActive(true);
    }
})();