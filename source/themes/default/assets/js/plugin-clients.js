/**
 * Hydrate plugin client-mode placeholders (guest_page: client).
 * Fetches same-origin JSON from data-src and injects the html field.
 */
(function () {
    'use strict';

    function loadSlot(slot) {
        var url = slot.getAttribute('data-src');
        if (!url) {
            return;
        }

        fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('plugin client load failed');
                }

                return response.json();
            })
            .then(function (data) {
                if (!data || typeof data.html !== 'string' || data.html === '') {
                    slot.classList.add('is-empty');
                    return;
                }

                slot.innerHTML = data.html;
                slot.classList.add('is-loaded');
            })
            .catch(function () {
                slot.classList.add('is-error');
            });
    }

    function mount() {
        var slots = document.querySelectorAll('.plugin-client-slot[data-src]');
        for (var i = 0; i < slots.length; i++) {
            loadSlot(slots[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount);
    } else {
        mount();
    }
})();