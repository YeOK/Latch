(function () {
    'use strict';

    var btn = document.getElementById('topic-watch-btn');
    if (!btn) {
        return;
    }

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrf = csrfMeta ? csrfMeta.content : '';
    btn.addEventListener('click', function () {
        if (btn.disabled) {
            return;
        }

        var topicId = btn.dataset.topicId;
        btn.disabled = true;

        var body = new URLSearchParams();
        body.set('_csrf', csrf);

        fetch('/topic/' + topicId + '/watch', {
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
                    window.alert(result.payload.message || 'Could not update watch.');
                    return;
                }

                var watching = !!result.payload.watching;
                btn.dataset.watching = watching ? '1' : '0';
                btn.classList.toggle('is-watching', watching);
                btn.setAttribute('aria-pressed', watching ? 'true' : 'false');
                btn.title = watching ? 'Unwatch topic' : 'Watch topic';
                btn.setAttribute('aria-label', btn.title);
            })
            .catch(function () {
                window.alert('Could not update watch. Check your connection and try again.');
            })
            .finally(function () {
                btn.disabled = false;
            });
    });
})();