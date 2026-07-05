/**
 * Copyright (c) 2026 Latch contributors
 * SPDX-License-Identifier: MIT
 */

(function () {
    'use strict';

    var ACCEPT = 'image/jpeg,image/png,image/gif,image/webp';
    var PRESIGN_URL = '/plugin/image-upload/presign';

    var fileInput = document.createElement('input');
    fileInput.type = 'file';
    fileInput.accept = ACCEPT;
    fileInput.hidden = true;
    document.body.appendChild(fileInput);

    var activeTextarea = null;
    var busy = false;

    document.addEventListener('click', function (event) {
        var btn = event.target.closest('.composer-btn[data-action="image-upload"]');
        if (!btn || busy) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();

        var root = btn.closest('[data-editor]');
        if (!root) {
            return;
        }

        var textarea = root.querySelector('.composer-textarea');
        if (!textarea) {
            return;
        }

        activeTextarea = textarea;
        fileInput.click();
    }, true);

    fileInput.addEventListener('change', function () {
        var file = fileInput.files && fileInput.files[0];
        fileInput.value = '';

        if (!file || !activeTextarea) {
            return;
        }

        uploadImage(file, activeTextarea);
    });

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    function insertMarkdown(textarea, markdown) {
        textarea.focus();
        var start = textarea.selectionStart;
        var end = textarea.selectionEnd;
        var prefix = start > 0 && textarea.value.charAt(start - 1) !== '\n' ? '\n\n' : '';
        var suffix = '\n\n';
        textarea.setRangeText(prefix + markdown + suffix, start, end, 'end');
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function setButtonBusy(btn, isBusy) {
        if (!btn) {
            return;
        }
        btn.disabled = isBusy;
        btn.classList.toggle('is-busy', isBusy);
        btn.setAttribute('aria-busy', isBusy ? 'true' : 'false');
    }

    function uploadImage(file, textarea) {
        var btn = textarea.closest('[data-editor]');
        btn = btn ? btn.querySelector('.composer-btn[data-action="image-upload"]') : null;

        if (!ACCEPT.split(',').includes(file.type)) {
            window.alert('Use a JPEG, PNG, GIF, or WebP image.');
            return;
        }

        busy = true;
        setButtonBusy(btn, true);

        var body = new URLSearchParams();
        body.set('_csrf', csrfToken());
        body.set('filename', file.name);
        body.set('content_type', file.type);
        body.set('size', String(file.size));

        fetch(PRESIGN_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: body.toString(),
        })
            .then(function (res) {
                return res.json().then(function (data) {
                    return { ok: res.ok, data: data };
                });
            })
            .then(function (result) {
                if (!result.ok) {
                    throw new Error(result.data.error || 'Could not prepare upload.');
                }

                var payload = result.data;
                var headers = payload.headers || {};
                headers['Content-Type'] = file.type;

                return fetch(payload.upload_url, {
                    method: payload.method || 'PUT',
                    headers: headers,
                    body: file,
                }).then(function (putRes) {
                    if (!putRes.ok) {
                        throw new Error('Upload to storage failed (' + putRes.status + ').');
                    }
                    return payload;
                });
            })
            .then(function (payload) {
                insertMarkdown(textarea, payload.markdown || ('![' + file.name + '](' + payload.public_url + ')'));
            })
            .catch(function (err) {
                window.alert(err && err.message ? err.message : 'Image upload failed.');
            })
            .finally(function () {
                busy = false;
                setButtonBusy(btn, false);
            });
    }
})();