(function () {
    'use strict';

    var mount = document.getElementById('staff-report-bar-mount');
    if (!mount) {
        return;
    }

    var loading = false;
    var pollTimer = null;
    var POLL_MS = 90000;

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    function severityClass(severity) {
        var value = String(severity || 'medium').toLowerCase();
        if (value === 'critical' || value === 'high') {
            return value;
        }
        return 'medium';
    }

    function renderBar(queue) {
        if (!queue || !queue.count) {
            mount.hidden = true;
            mount.setAttribute('aria-hidden', 'true');
            mount.innerHTML = '';
            return;
        }

        var count = queue.count;
        var top = queue.top;
        var severity = top ? severityClass(top.severity) : 'medium';
        var countLabel = count === 1 ? '' : 's';
        var topHtml = '';

        if (top) {
            topHtml =
                ' — top: <span class="severity-badge severity-' +
                escapeHtml(top.severity || 'medium') +
                '">' +
                escapeHtml(top.severity || 'medium') +
                '</span> ' +
                escapeHtml(top.reason_label || '') +
                ' (' +
                escapeHtml(top.target_type || '') +
                ' #' +
                escapeHtml(top.target_id) +
                ')';
        }

        var viewBtn = top
            ? '<a class="btn btn-small" href="' +
              escapeHtml(top.url || '/admin/reports') +
              '">View</a>'
            : '';

        mount.innerHTML =
            '<aside class="staff-report-bar severity-' +
            escapeHtml(severity) +
            '" role="region" aria-label="Open moderation reports">' +
            '<div class="container staff-report-bar-inner">' +
            '<p class="staff-report-bar-text"><strong>' +
            escapeHtml(count) +
            '</strong> open report' +
            countLabel +
            topHtml +
            '</p>' +
            '<div class="staff-report-bar-actions">' +
            viewBtn +
            '<a class="btn btn-small btn-primary" href="/admin/reports">Open queue</a>' +
            '</div></div></aside>';

        mount.hidden = false;
        mount.removeAttribute('aria-hidden');
    }

    function loadQueue() {
        if (loading) {
            return Promise.resolve();
        }
        loading = true;

        return fetch('/admin/reports/feed', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
            .then(function (response) {
                return response.json().then(function (payload) {
                    return { ok: response.ok, payload: payload };
                });
            })
            .then(function (result) {
                if (!result.ok || !result.payload.ok) {
                    return;
                }
                renderBar(result.payload.queue);
            })
            .catch(function () {
                /* keep previous bar state on transient errors */
            })
            .finally(function () {
                loading = false;
            });
    }

    function refresh() {
        return loadQueue();
    }

    function startPolling() {
        if (pollTimer) {
            return;
        }
        pollTimer = window.setInterval(refresh, POLL_MS);
    }

    window.LatchStaffReports = { refresh: refresh };

    loadQueue().then(startPolling);

    window.addEventListener('focus', refresh);

    document.addEventListener('latch:reports-changed', refresh);
})();