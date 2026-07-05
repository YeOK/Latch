/**
 * Copyright (c) 2026 Latch contributors
 * SPDX-License-Identifier: MIT
 */

(function () {
    var inputs = document.querySelectorAll('input[list="tag-suggestions"]');
    if (!inputs.length) {
        return;
    }

    var datalist = document.getElementById('tag-suggestions');
    if (!datalist) {
        return;
    }

    var timer = null;
    var lastQuery = '';

    function updateDatalist(query) {
        if (query.length < 1) {
            datalist.innerHTML = '';
            return;
        }

        fetch('/tags/suggest?q=' + encodeURIComponent(query), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        })
            .then(function (res) {
                return res.ok ? res.json() : { tags: [] };
            })
            .then(function (data) {
                datalist.innerHTML = '';
                (data.tags || []).forEach(function (tag) {
                    var option = document.createElement('option');
                    option.value = tag.name;
                    datalist.appendChild(option);
                });
            })
            .catch(function () {});
    }

    inputs.forEach(function (input) {
        input.addEventListener('input', function () {
            var value = input.value;
            var comma = value.lastIndexOf(',');
            var query = (comma >= 0 ? value.slice(comma + 1) : value).trim();

            if (query === lastQuery) {
                return;
            }
            lastQuery = query;

            clearTimeout(timer);
            timer = setTimeout(function () {
                updateDatalist(query);
            }, 200);
        });
    });
})();