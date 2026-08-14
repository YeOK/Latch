/**
 * Copyright (c) 2026 Latch contributors
 * SPDX-License-Identifier: MIT
 */

(function () {
    'use strict';

    var btn = document.getElementById('profile-follow-btn');
    if (!btn) {
        return;
    }

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrf = csrfMeta ? csrfMeta.content : '';
    var followLabel = btn.dataset.labelFollow || btn.textContent.trim();
    var unfollowLabel = btn.dataset.labelUnfollow || 'Unfollow';

    btn.addEventListener('click', function () {
        if (btn.disabled) {
            return;
        }

        var userId = btn.dataset.userId;
        btn.disabled = true;

        var body = new URLSearchParams();
        body.set('_csrf', csrf);

        fetch('/user/' + userId + '/follow', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: body.toString(),
            credentials: 'same-origin',
        })
            .then(function (res) {
                return res.json().then(function (payload) {
                    return { ok: res.ok, payload: payload };
                });
            })
            .then(function (result) {
                if (!result.ok || !result.payload.ok) {
                    window.alert(result.payload.message || 'Could not update follow.');
                    return;
                }

                var following = !!result.payload.following;
                btn.dataset.following = following ? '1' : '0';
                btn.classList.toggle('is-following', following);
                btn.setAttribute('aria-pressed', following ? 'true' : 'false');
                var label = following ? unfollowLabel : followLabel;
                btn.textContent = label;
                btn.title = label;
            })
            .catch(function () {
                window.alert('Could not update follow. Check your connection and try again.');
            })
            .finally(function () {
                btn.disabled = false;
            });
    });
})();
