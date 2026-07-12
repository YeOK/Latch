/**
 * Copyright (c) 2026 Latch contributors
 * SPDX-License-Identifier: MIT
 */

(function () {
    'use strict';

    var root = document.getElementById('admin-logs-live-root');
    if (!root) {
        return;
    }

    var feedUrl = root.getAttribute('data-feed-url');
    if (!feedUrl) {
        return;
    }

    var intervalMs = parseInt(root.getAttribute('data-interval') || '30000', 10);
    if (!Number.isFinite(intervalMs) || intervalMs < 5000) {
        intervalMs = 30000;
    }

    var output = document.getElementById('log-viewer-output');
    var linesMount = document.getElementById('log-viewer-lines');
    if (!output || !linesMount) {
        return;
    }

    var format = output.getAttribute('data-format') || 'text';
    var loading = false;
    var timer = null;

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    function renderJsonLines(lines) {
        var html = '';
        for (var i = 0; i < lines.length; i++) {
            var entry = lines[i];
            var row = entry && entry.parsed ? entry.parsed : null;
            var ts = row && row.ts ? escapeHtml(row.ts) : '—';
            var eventCell = row && row.parse_error
                ? '<span class="log-parse-error">parse error</span>'
                : escapeHtml(row && row.event ? row.event : '—');
            var ip = escapeHtml(row && row.ip ? row.ip : '—');
            var user = escapeHtml(row && row.username ? row.username : '—');
            var detailsCell = '<span class="log-code-empty">—</span>';
            if (row && row.meta != null) {
                detailsCell = '<pre class="log-code-details">' + escapeHtml(JSON.stringify(row.meta)) + '</pre>';
            }
            var raw = escapeHtml(entry.raw || '');

            html +=
                '<tr class="log-code-row">' +
                '<td class="log-col-n">' + (i + 1) + '</td>' +
                '<td class="log-col-ts">' + ts + '</td>' +
                '<td class="log-col-event">' + eventCell + '</td>' +
                '<td class="log-col-ip">' + ip + '</td>' +
                '<td class="log-col-user">' + user + '</td>' +
                '<td class="log-col-details">' + detailsCell + '</td>' +
                '<td class="log-col-raw"><pre class="log-code-line">' + raw + '</pre></td>' +
                '</tr>';
        }
        linesMount.innerHTML = html;
    }

    function renderTextLines(lines) {
        var html = '';
        for (var i = 0; i < lines.length; i++) {
            html +=
                '<div class="log-code-text-row">' +
                '<span class="log-code-gutter" aria-hidden="true">' + (i + 1) + '</span>' +
                '<pre class="log-code-line">' + escapeHtml(lines[i].raw || '') + '</pre>' +
                '</div>';
        }
        linesMount.innerHTML = html;
    }

    function applyPayload(payload) {
        if (!payload || !payload.ok || !Array.isArray(payload.lines)) {
            return;
        }

        if (payload.rotated) {
            window.location.reload();
            return;
        }

        if (format === 'json_lines') {
            renderJsonLines(payload.lines);
        } else {
            renderTextLines(payload.lines);
        }
    }

    function poll() {
        if (loading) {
            return;
        }
        loading = true;

        fetch(feedUrl, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('feed ' + response.status);
                }
                return response.json();
            })
            .then(applyPayload)
            .catch(function () {
                /* silent — operator can refresh manually */
            })
            .finally(function () {
                loading = false;
            });
    }

    timer = window.setInterval(poll, intervalMs);
    window.addEventListener('pagehide', function () {
        if (timer !== null) {
            window.clearInterval(timer);
        }
    });
})();