/**
 * Copyright (c) 2026 Latch contributors
 * SPDX-License-Identifier: MIT
 */

(function () {
    'use strict';

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrf = csrfMeta ? csrfMeta.content : '';

    function updateBlock(block, data) {
        block.dataset.likeCount = String(data.like_count);
        block.dataset.dislikeCount = String(data.dislike_count);
        block.dataset.viewerVote = data.viewer_vote || '';

        var likeBtn = block.querySelector('.post-vote-like');
        var dislikeBtn = block.querySelector('.post-vote-dislike');
        var likeCount = block.querySelector('[data-vote-count="like"]');
        var dislikeCount = block.querySelector('[data-vote-count="dislike"]');

        if (likeCount) {
            likeCount.textContent = String(data.like_count);
        }
        if (dislikeCount) {
            dislikeCount.textContent = String(data.dislike_count);
        }

        if (likeBtn) {
            likeBtn.classList.toggle('is-active', data.viewer_vote === 'like');
            likeBtn.setAttribute('aria-pressed', data.viewer_vote === 'like' ? 'true' : 'false');
        }
        if (dislikeBtn) {
            dislikeBtn.classList.toggle('is-active', data.viewer_vote === 'dislike');
            dislikeBtn.setAttribute('aria-pressed', data.viewer_vote === 'dislike' ? 'true' : 'false');
        }
    }

    document.querySelectorAll('[data-post-votes]').forEach(function (block) {
        block.querySelectorAll('.post-vote-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var postId = block.dataset.postId;
                var vote = btn.getAttribute('data-vote');
                if (!postId || !vote || btn.disabled) {
                    return;
                }

                btn.disabled = true;
                block.querySelectorAll('.post-vote-btn').forEach(function (peer) {
                    peer.disabled = true;
                });

                var body = new URLSearchParams();
                body.set('_csrf', csrf);
                body.set('vote', vote);

                fetch('/post/' + postId + '/vote', {
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
                            window.alert(result.payload.message || 'Vote failed.');
                            return;
                        }
                        updateBlock(block, result.payload);
                    })
                    .catch(function () {
                        window.alert('Vote failed. Check your connection and try again.');
                    })
                    .finally(function () {
                        block.querySelectorAll('.post-vote-btn').forEach(function (peer) {
                            peer.disabled = false;
                        });
                    });
            });
        });
    });
})();