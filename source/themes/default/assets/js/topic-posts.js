(function () {
    var button = document.getElementById('topic-load-older');
    var list = document.getElementById('topic-post-list');
    if (!button || !list) {
        return;
    }

    var topicId = button.getAttribute('data-topic-id');
    var loading = false;

    button.addEventListener('click', function () {
        if (loading) {
            return;
        }

        var after = parseInt(button.getAttribute('data-after') || '0', 10);
        if (!topicId || after <= 0) {
            return;
        }

        loading = true;
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');

        fetch('/topic/' + encodeURIComponent(topicId) + '/posts?after=' + encodeURIComponent(String(after)), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('load_failed');
                }
                return response.json();
            })
            .then(function (payload) {
                if (!payload || typeof payload.html !== 'string' || payload.html === '') {
                    button.hidden = true;
                    return;
                }

                var anchor = document.getElementById('latest');
                var wrapper = document.createElement('div');
                wrapper.innerHTML = payload.html;
                while (wrapper.firstChild) {
                    if (anchor) {
                        list.insertBefore(wrapper.firstChild, anchor);
                    } else {
                        list.appendChild(wrapper.firstChild);
                    }
                }

                if (payload.has_more && payload.cursor_after) {
                    button.setAttribute('data-after', String(payload.cursor_after));
                    button.disabled = false;
                    button.removeAttribute('aria-busy');
                } else {
                    button.hidden = true;
                }

                if (window.hljs) {
                    list.querySelectorAll('pre code').forEach(function (block) {
                        window.hljs.highlightElement(block);
                    });
                }
            })
            .catch(function () {
                button.disabled = false;
                button.removeAttribute('aria-busy');
            })
            .finally(function () {
                loading = false;
            });
    });
})();