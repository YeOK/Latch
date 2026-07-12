/**
 * Copyright (c) 2026 Latch contributors
 * SPDX-License-Identifier: MIT
 */

(function () {
    'use strict';

    var header = document.getElementById('sc-header');
    if (header) {
        var onScroll = function () {
            if (window.scrollY > 12) {
                header.classList.add('is-scrolled');
            } else {
                header.classList.remove('is-scrolled');
            }
        };
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
    }

    document.querySelectorAll('.sc-board-card').forEach(function (card, index) {
        card.style.setProperty('--sc-card-delay', (index * 40) + 'ms');
    });
})();