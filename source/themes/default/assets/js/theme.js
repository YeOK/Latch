/**
 * Copyright (c) 2026 Latch contributors
 * SPDX-License-Identifier: MIT
 */

(function () {
    'use strict';

    var COOKIE = 'latch_theme';
    var STORAGE = 'latch_theme';
    var MODES = ['light', 'dark', 'system'];

    function getStored() {
        try {
            var stored = localStorage.getItem(STORAGE);
            if (stored && MODES.indexOf(stored) >= 0) {
                return stored;
            }
        } catch (e) {
            /* ignore */
        }

        var match = document.cookie.match(new RegExp('(?:^|; )' + COOKIE + '=([^;]*)'));
        if (match) {
            return decodeURIComponent(match[1]);
        }

        return document.documentElement.dataset.themeMode || 'system';
    }

    function resolveEffective(mode) {
        if (mode === 'dark' || mode === 'light') {
            return mode;
        }

        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }

    function apply(mode) {
        document.documentElement.dataset.theme = resolveEffective(mode);
        document.documentElement.dataset.themeMode = mode;
        updateToggleLabel(mode);
    }

    function setStored(mode) {
        document.cookie = COOKIE + '=' + encodeURIComponent(mode) + ';path=/;max-age=31536000;SameSite=Lax';
        try {
            localStorage.setItem(STORAGE, mode);
        } catch (e) {
            /* ignore */
        }
    }

    function updateToggleLabel(mode) {
        var btn = document.getElementById('theme-toggle');
        if (!btn) {
            return;
        }

        var labels = { light: 'Light', dark: 'Dark', system: 'System' };
        var label = labels[mode] || 'System';
        btn.setAttribute('aria-label', 'Theme: ' + label);
        btn.setAttribute('title', 'Theme: ' + label + ' (click to change)');
        btn.dataset.themeMode = mode;

        btn.querySelectorAll('[data-theme-icon]').forEach(function (icon) {
            if (icon.getAttribute('data-theme-icon') === mode) {
                icon.removeAttribute('hidden');
            } else {
                icon.setAttribute('hidden', '');
            }
        });
    }

    function persistToServer(mode) {
        if (document.body.dataset.loggedIn !== '1') {
            return;
        }

        var token = document.querySelector('meta[name="csrf-token"]');
        if (!token) {
            return;
        }

        fetch('/profile/theme', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: '_csrf=' + encodeURIComponent(token.content) + '&theme_mode=' + encodeURIComponent(mode),
        }).catch(function () {
            /* non-blocking */
        });
    }

    apply(getStored());

    var themeBtn = document.getElementById('theme-toggle');
    if (themeBtn) {
        themeBtn.addEventListener('click', function () {
            var current = document.documentElement.dataset.themeMode || getStored();
            var idx = MODES.indexOf(current);
            var next = MODES[(idx + 1) % MODES.length];
            apply(next);
            setStored(next);
            persistToServer(next);
        });
    }

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
        if ((document.documentElement.dataset.themeMode || getStored()) === 'system') {
            apply('system');
        }
    });

    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            var message = form.getAttribute('data-confirm');
            if (message && !window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    var userMenuBtn = document.getElementById('user-menu-toggle');
    var userMenuPanel = document.getElementById('user-menu-panel');
    if (userMenuBtn && userMenuPanel) {
        userMenuBtn.addEventListener('click', function (event) {
            event.stopPropagation();
            var open = userMenuPanel.hasAttribute('hidden');
            if (open) {
                userMenuPanel.removeAttribute('hidden');
                userMenuBtn.setAttribute('aria-expanded', 'true');
            } else {
                userMenuPanel.setAttribute('hidden', '');
                userMenuBtn.setAttribute('aria-expanded', 'false');
            }
        });

        document.addEventListener('click', function (event) {
            if (!userMenuPanel.contains(event.target) && event.target !== userMenuBtn && !userMenuBtn.contains(event.target)) {
                userMenuPanel.setAttribute('hidden', '');
                userMenuBtn.setAttribute('aria-expanded', 'false');
            }
        });
    }

    var searchWrap = document.getElementById('site-search-wrap');
    var searchToggle = document.getElementById('site-search-toggle');
    var searchForm = document.getElementById('site-search-form');
    var searchInput = document.getElementById('site-search-input');

    function openSiteSearch() {
        if (!searchWrap || !searchForm || !searchToggle) {
            return;
        }

        searchWrap.classList.add('is-open');
        searchForm.removeAttribute('hidden');
        searchToggle.setAttribute('hidden', '');
        searchToggle.setAttribute('aria-expanded', 'true');
        if (searchInput) {
            window.setTimeout(function () {
                searchInput.focus();
            }, 50);
        }
    }

    function closeSiteSearch() {
        if (!searchWrap || !searchForm || !searchToggle) {
            return;
        }

        searchWrap.classList.remove('is-open');
        searchForm.setAttribute('hidden', '');
        searchToggle.removeAttribute('hidden');
        searchToggle.setAttribute('aria-expanded', 'false');
    }

    if (searchToggle) {
        searchToggle.addEventListener('click', function (event) {
            event.stopPropagation();
            openSiteSearch();
        });
    }

    if (searchWrap) {
        document.addEventListener('click', function (event) {
            if (!searchWrap.classList.contains('is-open')) {
                return;
            }

            if (!searchWrap.contains(event.target)) {
                closeSiteSearch();
            }
        });
    }

    function isTypingTarget(target) {
        if (!target || !(target instanceof Element)) {
            return false;
        }

        var tag = target.tagName;
        return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || target.isContentEditable;
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === '/' && !event.metaKey && !event.ctrlKey && !event.altKey && !isTypingTarget(event.target)) {
            if (searchWrap && searchInput) {
                event.preventDefault();
                openSiteSearch();
            }
            return;
        }

        if (event.key !== 'Escape') {
            return;
        }

        if (searchWrap && searchWrap.classList.contains('is-open')) {
            closeSiteSearch();
            if (searchToggle) {
                searchToggle.focus();
            }
            return;
        }

        if (userMenuPanel && !userMenuPanel.hasAttribute('hidden')) {
            userMenuPanel.setAttribute('hidden', '');
            if (userMenuBtn) {
                userMenuBtn.setAttribute('aria-expanded', 'false');
                userMenuBtn.focus();
            }
        }

        var accountOverlay = document.getElementById('account-overlay');
        if (accountOverlay && !accountOverlay.hasAttribute('hidden')) {
            var closeBtn = accountOverlay.querySelector('[data-account-close]');
            if (closeBtn) {
                closeBtn.click();
            }
        }
    });

    var CONSENT_COOKIE = 'latch_cookie_consent';
    var CONSENT_ACCEPTED = 'accepted';
    var consentBanner = document.getElementById('cookie-consent');
    var gdprMode = document.body.dataset.gdpr === '1';

    function activatePendingAvatars() {
        document.querySelectorAll('.avatar-gravatar-pending[data-gravatar-src]').forEach(function (el) {
            var src = el.getAttribute('data-gravatar-src');
            if (!src) {
                return;
            }

            var size = parseInt(el.style.width, 10) || 40;
            var img = document.createElement('img');
            img.className = 'avatar';
            img.src = src;
            img.alt = '';
            img.width = size;
            img.height = size;
            img.loading = 'lazy';
            img.decoding = 'async';
            el.replaceWith(img);
        });
    }

    function getConsent() {
        var match = document.cookie.match(new RegExp('(?:^|; )' + CONSENT_COOKIE + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : '';
    }

    function setConsent(value) {
        document.cookie = CONSENT_COOKIE + '=' + encodeURIComponent(value) + ';path=/;max-age=31536000;SameSite=Lax';
    }

    function hideConsentBanner() {
        if (!consentBanner) {
            return;
        }

        consentBanner.setAttribute('hidden', '');
        document.body.classList.remove('cookie-consent-visible');
    }

    function showConsentBanner() {
        if (!consentBanner) {
            return;
        }

        consentBanner.removeAttribute('hidden');
        document.body.classList.add('cookie-consent-visible');
    }

    function initConsent() {
        if (!gdprMode || !consentBanner) {
            return;
        }

        if (getConsent() === '') {
            showConsentBanner();
        }
    }

    initConsent();

    var consentAccept = document.getElementById('cookie-consent-accept');
    if (consentAccept) {
        consentAccept.addEventListener('click', function () {
            setConsent(CONSENT_ACCEPTED);
            hideConsentBanner();
            activatePendingAvatars();
        });
    }

    var consentReject = document.getElementById('cookie-consent-reject');
    if (consentReject) {
        consentReject.addEventListener('click', function () {
            setConsent('rejected');
            hideConsentBanner();
        });
    }

    function reopenConsentSettings() {
        document.cookie = CONSENT_COOKIE + '=;path=/;max-age=0;SameSite=Lax';
        showConsentBanner();
    }

    var footerCookieSettings = document.getElementById('footer-cookie-settings');
    if (footerCookieSettings) {
        footerCookieSettings.addEventListener('click', reopenConsentSettings);
    }

    var policyCookieSettings = document.getElementById('cookie-policy-settings');
    if (policyCookieSettings) {
        policyCookieSettings.addEventListener('click', reopenConsentSettings);
    }

    document.querySelectorAll('.locale-select').forEach(function (select) {
        select.addEventListener('change', function () {
            var code = select.value;
            if (!code) {
                return;
            }

            var tokenEl = document.querySelector('meta[name="csrf-token"]');
            var token = tokenEl ? tokenEl.getAttribute('content') : '';
            var body = new FormData();
            body.append('_csrf', token || '');
            body.append('locale', code);

            fetch('/locale', {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then(function (response) {
                    window.location.href = response.url || window.location.href;
                })
                .catch(function () {
                    window.location.reload();
                });
        });
    });
})();