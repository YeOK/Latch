(function () {
    'use strict';

    var wrap = document.getElementById('board-mod-wrap');
    if (!wrap) {
        return;
    }

    var boardSlug = wrap.dataset.boardSlug || '';
    var modStorageKey = 'latch-board-mod-mode-' + boardSlug;
    var toggle = document.getElementById('board-mod-toggle');
    var toolbar = document.getElementById('board-mod-toolbar');
    var selectAll = document.getElementById('board-mod-select-all');
    var countEl = document.getElementById('board-mod-selection-count');
    var feedback = document.getElementById('board-mod-feedback');
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrf = csrfMeta ? csrfMeta.content : '';

    var modActive = false;
    var loading = false;

    var actionLabels = {
        pin: 'Pin',
        unpin: 'Unpin',
        lock: 'Lock',
        unlock: 'Unlock',
        delete: 'Remove',
    };

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

    function topicCheckboxes() {
        return Array.prototype.slice.call(document.querySelectorAll('.mod-topic-checkbox'));
    }

    function selectedIds() {
        return topicCheckboxes()
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

    function updateSelectionUi() {
        var ids = selectedIds();
        var count = ids.length;
        if (countEl) {
            countEl.textContent = count + ' selected';
        }
        document.querySelectorAll('[data-board-mod-action]').forEach(function (btn) {
            btn.disabled = loading || count === 0;
        });
        if (selectAll) {
            var boxes = topicCheckboxes();
            selectAll.checked = boxes.length > 0 && count === boxes.length;
            selectAll.indeterminate = count > 0 && count < boxes.length;
        }
    }

    function setModActive(active) {
        modActive = active;
        persistModActive(active);
        document.body.classList.toggle('board-mod-mode', active);
        if (toggle) {
            toggle.setAttribute('aria-pressed', active ? 'true' : 'false');
            toggle.classList.toggle('is-active', active);
        }
        if (toolbar) {
            toolbar.classList.toggle('is-active', active);
        }
        if (!active) {
            topicCheckboxes().forEach(function (box) {
                box.checked = false;
            });
        }
        if (!active && selectAll) {
            selectAll.checked = false;
            selectAll.indeterminate = false;
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
        window.clearTimeout(feedback._boardModTimer);
        feedback._boardModTimer = window.setTimeout(function () {
            feedback.hidden = true;
        }, 5000);
    }

    function staffFetch(formData) {
        loading = true;
        updateSelectionUi();
        return fetch('/mod/topics/bulk', {
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

    function buildFormData(action) {
        var formData = new FormData();
        formData.append('_csrf', csrf);
        formData.append('action', action);
        formData.append('board_slug', boardSlug);
        formData.append('page', wrap.dataset.boardPage || '1');
        if (wrap.dataset.boardTag) {
            formData.append('tag', wrap.dataset.boardTag);
        }
        if (wrap.dataset.boardSort && wrap.dataset.boardSort !== 'activity') {
            formData.append('sort', wrap.dataset.boardSort);
        }
        selectedIds().forEach(function (id) {
            formData.append('topic_ids[]', String(id));
        });
        return formData;
    }

    function confirmAction(action) {
        var ids = selectedIds();
        if (ids.length === 0) {
            return;
        }

        var label = actionLabels[action] || 'Update';
        var message =
            action === 'delete'
                ? 'Remove ' +
                  ids.length +
                  ' topic(s) from the board? Posts may be moved to the moderation trash.'
                : label + ' ' + ids.length + ' topic(s)?';
        if (!window.confirm(message)) {
            return;
        }

        staffFetch(buildFormData(action)).then(function (result) {
            if (!result.ok || !result.body.ok) {
                showFeedback(result.body.message || 'Could not update topics.', true);
                return;
            }
            showFeedback(result.body.message, false);
            if (modActive) {
                persistModActive(true);
            }
            if (result.body.redirect) {
                window.location.href = result.body.redirect;
            } else {
                window.location.reload();
            }
        });
    }

    if (toggle) {
        toggle.addEventListener('click', function () {
            setModActive(!modActive);
        });
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            var checked = selectAll.checked;
            topicCheckboxes().forEach(function (box) {
                box.checked = checked;
            });
            updateSelectionUi();
        });
    }

    document.addEventListener('change', function (event) {
        if (event.target.classList.contains('mod-topic-checkbox')) {
            updateSelectionUi();
        }
    });

    document.addEventListener('click', function (event) {
        var actionBtn = event.target.closest('[data-board-mod-action]');
        if (actionBtn && !actionBtn.disabled) {
            confirmAction(actionBtn.getAttribute('data-board-mod-action'));
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modActive) {
            setModActive(false);
        }
    });

    updateSelectionUi();

    if (readPersistedModActive()) {
        setModActive(true);
    }
})();