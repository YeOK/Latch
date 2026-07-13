/**
 * Copyright (c) 2026 Latch contributors
 * SPDX-License-Identifier: MIT
 */

/**
 * In-page account/admin/profile navigation and AJAX form handling.
 *
 * Forms with data-account-bypass submit normally (full page) — use for file uploads and
 * redirects outside /admin. Other POST forms in .admin-main use fetch + in-panel reload.
 * GET filter forms (e.g. Admin → Logs) load results in the panel via the form query string.
 */
(function () {
    'use strict';

    var overlay = document.getElementById('account-overlay');
    var body = document.getElementById('account-overlay-body');
    var titleEl = document.getElementById('account-overlay-title');
    var statusEl = document.getElementById('account-overlay-status');
    var dialog = overlay ? overlay.querySelector('.account-overlay-dialog') : null;

    var PANEL_PREFIXES = ['/profile', '/admin'];
    var isOpen = false;
    var pushedState = false;
    var overlayReturnUrl = '/';
    var overlayPushDepth = 0;
    var loading = false;

    if (!overlay || !body || !dialog) {
        return;
    }

    var i18n = window.LatchI18n || {};

    function loadingText() {
        return i18n.loading || 'Loading…';
    }

    function isPanelPath(pathname) {
        return PANEL_PREFIXES.some(function (prefix) {
            return pathname === prefix || pathname.indexOf(prefix + '/') === 0;
        });
    }

    function isPanelUrl(href) {
        try {
            var url = new URL(href, window.location.href);
            if (url.origin !== window.location.origin) {
                return false;
            }
            if (url.pathname === '/profile/export') {
                return false;
            }
            return isPanelPath(url.pathname);
        } catch (error) {
            return false;
        }
    }

    function shouldBypassLink(link) {
        if (!link || link.target === '_blank' || link.hasAttribute('download')) {
            return true;
        }
        if (link.hasAttribute('data-account-bypass')) {
            return true;
        }
        if (link.getAttribute('href') === '' || link.getAttribute('href') === '#') {
            return true;
        }
        return false;
    }

    function pageTitleFromDoc(doc) {
        var title = doc.title || 'Account';
        return title.replace(/\s+—\s+.*$/, '').trim();
    }

    function extractFlash(doc) {
        var candidates = doc.querySelectorAll(
            'main .flash, .admin-main .flash, .account-overlay-scope .flash',
        );
        for (var i = 0; i < candidates.length; i++) {
            var flash = candidates[i];
            if (flash.id === 'admin-spa-flash' && flash.hasAttribute('hidden')) {
                continue;
            }
            var text = (flash.textContent || '').trim();
            if (text) {
                return text;
            }
        }
        return '';
    }

    function showAdminFlash(message, isError) {
        var flashHost = document.getElementById('admin-spa-flash');
        if (!flashHost || !message) {
            return;
        }
        flashHost.textContent = message;
        flashHost.hidden = false;
        flashHost.className =
            'flash admin-spa-flash ' + (isError ? 'flash-error' : 'flash-success');
    }

    function isMaintenanceForm(form) {
        return form && form.classList.contains('admin-maintenance-form');
    }

    function renderSiteLockConfirmation(json) {
        var token = json.unlock_token || '';
        var hint = json.cli_hint || 'sudo latch lock off';
        var unlockPath = json.unlock_path || '/maintenance/unlock';
        return ''
            + '<section class="page-header"><h1>Maintenance mode enabled</h1>'
            + '<p class="muted">Web and API traffic is blocked. Copy this token before leaving — you cannot return to admin until unlock.</p></section>'
            + '<section class="form-section admin-alert" role="alert">'
            + '<h2>Unlock token</h2>'
            + '<p><code class="recovery-codes site-lock-token">' + token + '</code></p>'
            + '<p class="muted">Unlock at <a href="' + unlockPath + '">' + unlockPath + '</a> (paste token), or run: <code>' + hint + '</code></p>'
            + '</section>';
    }

    function extractSiteLockFromHtml(text) {
        try {
            var doc = new DOMParser().parseFromString(text, 'text/html');
            var tokenEl = doc.querySelector('.site-lock-token, .recovery-codes');
            if (!tokenEl) {
                return null;
            }
            var token = (tokenEl.textContent || '').trim();
            if (!token) {
                return null;
            }
            return {
                site_lock: true,
                unlock_token: token,
                message: 'Maintenance mode enabled. Copy your unlock token now.',
            };
        } catch (error) {
            return null;
        }
    }

    function showSiteLockConfirmation(json, form) {
        if (json.redirect) {
            window.location.href = json.redirect;
            return true;
        }

        var html = renderSiteLockConfirmation(json);
        if (isOpen && overlay.contains(form)) {
            body.innerHTML = '<div class="admin-shell"><div class="admin-main">' + html + '</div></div>';
            body.classList.add('is-admin');
            if (titleEl) {
                titleEl.textContent = 'Maintenance enabled';
            }
            setStatus(json.message || '', false);
            return true;
        }

        var adminMain = document.querySelector('.page-admin .admin-main');
        if (adminMain) {
            adminMain.innerHTML = html;
            showAdminFlash(json.message || 'Maintenance mode enabled.', false);
            return true;
        }

        return false;
    }

    function handleStaffJsonResponse(json, form) {
        if (json.site_lock && json.unlock_token) {
            return showSiteLockConfirmation(json, form);
        }

        var maintenance = isMaintenanceForm(form);
        var redirect = json.redirect || (maintenance ? '/admin/maintenance' : '/admin');
        var isError = !json.ok;

        if (isOpen && overlay.contains(form)) {
            if (json.message) {
                setStatus(json.message, isError);
            }
            if (!maintenance && !isError) {
                loadUrl(redirect, false);
            }
            return true;
        }

        if (document.body.classList.contains('page-admin') && form.closest('.admin-main')) {
            if (json.message) {
                showAdminFlash(json.message, isError);
            }
            if (!maintenance && !isError) {
                loadInPlaceAdmin(redirect, false);
            }
            return true;
        }

        return false;
    }

    function extractContent(doc, pathname) {
        if (pathname.indexOf('/admin') === 0) {
            var shell = doc.querySelector('.admin-shell');
            if (shell) {
                return {
                    html: shell.outerHTML,
                    mode: 'admin',
                    title: pageTitleFromDoc(doc),
                };
            }
        }

        var main = doc.querySelector('main.container, main.sc-main');
        if (main) {
            return {
                html: main.innerHTML,
                mode: 'profile',
                title: pageTitleFromDoc(doc),
            };
        }

        return null;
    }

    function setStatus(message, isError) {
        if (!statusEl) {
            return;
        }
        if (!message) {
            statusEl.hidden = true;
            statusEl.textContent = '';
            statusEl.className = 'account-overlay-status muted';
            return;
        }
        statusEl.hidden = false;
        statusEl.textContent = message;
        statusEl.className =
            'account-overlay-status ' + (isError ? 'flash flash-error' : 'flash flash-success');
    }

    function bindAdminTabs(root) {
        var tabRoot = root.querySelector('[data-admin-tabs]');
        if (!tabRoot) {
            return;
        }

        var tabs = tabRoot.querySelectorAll('[data-admin-tab]');
        var panels = tabRoot.querySelectorAll('[data-admin-tab-panel]');

        function activateTab(name) {
            tabs.forEach(function (tab) {
                var active = tab.getAttribute('data-admin-tab') === name;
                tab.classList.toggle('is-active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            panels.forEach(function (panel) {
                var active = panel.getAttribute('data-admin-tab-panel') === name;
                panel.hidden = !active;
            });
        }

        tabs.forEach(function (tab) {
            if (tab.dataset.adminTabWired === '1') {
                return;
            }
            tab.dataset.adminTabWired = '1';
            tab.addEventListener('click', function () {
                var name = tab.getAttribute('data-admin-tab');
                if (!name) {
                    return;
                }
                activateTab(name);
                var url = new URL(window.location.href);
                url.searchParams.set('tab', name);
                var next = url.pathname + url.search;
                if (document.body.classList.contains('page-admin')) {
                    history.replaceState(
                        Object.assign({}, history.state || {}, { adminSpa: next }),
                        '',
                        next,
                    );
                    return;
                }
                history.replaceState(history.state, '', next);
            });
        });

        activateTab(tabRoot.getAttribute('data-active-tab') || 'installed');
    }

    function bindConfirmForms(root) {
        root.querySelectorAll('form[data-confirm]').forEach(function (form) {
            if (form.dataset.confirmBound === '1') {
                return;
            }
            form.dataset.confirmBound = '1';
            form.addEventListener('submit', function (event) {
                var message = form.getAttribute('data-confirm');
                if (message && !window.confirm(message)) {
                    event.preventDefault();
                }
            });
        });
    }

    function isActiveNavLink(href, pathname) {
        if (href === '/admin') {
            return pathname === '/admin';
        }
        return pathname === href || pathname.indexOf(href + '/') === 0;
    }

    function updateAdminSidebarActive(pathname, root) {
        root.querySelectorAll('.admin-sidebar-link').forEach(function (link) {
            var href = link.getAttribute('href') || '';
            link.classList.toggle('is-active', isActiveNavLink(href, pathname));
        });
    }

    function renderContent(payload, url, pathname) {
        body.innerHTML = payload.html;
        body.classList.toggle('is-admin', payload.mode === 'admin');
        dialog.classList.toggle('is-admin', payload.mode === 'admin');

        if (titleEl) {
            titleEl.textContent = payload.title || 'Account';
        }

        if (payload.mode === 'admin') {
            updateAdminSidebarActive(pathname, body);
        }

        bindConfirmForms(body);
        bindAdminTabs(body);
        wirePanelLinks(body);
    }

    function fetchPage(url) {
        return fetch(url, {
            credentials: 'same-origin',
            headers: {
                Accept: 'text/html',
                'X-Requested-With': 'XMLHttpRequest',
            },
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.text();
        });
    }

    function loadUrl(url, pushHistory) {
        var parsed = new URL(url, window.location.href);
        if (!isPanelPath(parsed.pathname)) {
            window.location.href = url;
            return;
        }

        loading = true;
        body.innerHTML = '<p class="muted account-overlay-loading">' + loadingText() + '</p>';
        setStatus('');

        fetchPage(url)
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var payload = extractContent(doc, parsed.pathname);
                if (!payload) {
                    throw new Error('Could not parse page');
                }

                var flash = extractFlash(doc);
                if (flash) {
                    setStatus(flash, flash.toLowerCase().indexOf('error') >= 0);
                }

                renderContent(payload, url, parsed.pathname);

                if (pushHistory) {
                    history.pushState({ accountPanel: parsed.pathname + parsed.search }, '', parsed.pathname + parsed.search);
                    pushedState = true;
                    if (isOpen) {
                        overlayPushDepth++;
                    }
                }
            })
            .catch(function () {
                body.replaceChildren();
                var message = document.createElement('p');
                message.className = 'muted account-overlay-loading';
                var fallback = document.createElement('a');
                fallback.href = parsed.pathname + parsed.search;
                fallback.textContent = 'Open in full page';
                message.append('Could not load this page. ', fallback, '.');
                body.appendChild(message);
            })
            .finally(function () {
                loading = false;
            });
    }

    function openOverlay(url) {
        overlayReturnUrl = window.location.pathname + window.location.search;
        overlayPushDepth = 0;
        isOpen = true;
        overlay.hidden = false;
        document.body.classList.add('account-overlay-open');
        loadUrl(url, true);
        closeUserMenu();
    }

    function closeOverlay(useHistory) {
        if (!isOpen) {
            return;
        }
        isOpen = false;
        overlay.hidden = true;
        document.body.classList.remove('account-overlay-open');
        setStatus('');
        if (useHistory && overlayPushDepth > 0) {
            var depth = overlayPushDepth;
            overlayPushDepth = 0;
            pushedState = false;
            history.go(-depth);
        } else if (useHistory && pushedState) {
            pushedState = false;
            history.back();
        } else if (!useHistory) {
            overlayPushDepth = 0;
            pushedState = false;
        }
    }

    function closeUserMenu() {
        var panel = document.getElementById('user-menu-panel');
        var btn = document.getElementById('user-menu-toggle');
        if (panel) {
            panel.setAttribute('hidden', '');
        }
        if (btn) {
            btn.setAttribute('aria-expanded', 'false');
        }
    }

    function loadInPlaceAdmin(url, pushHistory) {
        var adminMain = document.querySelector('.page-admin .admin-main');
        if (!adminMain) {
            window.location.href = url;
            return;
        }

        loading = true;
        adminMain.innerHTML = '<p class="muted account-overlay-loading">' + loadingText() + '</p>';

        fetchPage(url)
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var nextMain = doc.querySelector('.admin-main');
                if (!nextMain) {
                    throw new Error('Missing admin content');
                }
                adminMain.innerHTML = nextMain.innerHTML;

                var parsed = new URL(url, window.location.href);
                var shell = document.querySelector('.page-admin .admin-shell');
                if (shell) {
                    updateAdminSidebarActive(parsed.pathname, shell);
                }

                var flash = extractFlash(doc);
                if (flash) {
                    showAdminFlash(flash, flash.toLowerCase().indexOf('error') >= 0);
                }

                bindConfirmForms(adminMain);
                bindAdminTabs(adminMain);
                wirePanelLinks(adminMain);

                if (pushHistory) {
                    history.pushState({ adminSpa: parsed.pathname + parsed.search }, '', parsed.pathname + parsed.search);
                }
            })
            .catch(function () {
                window.location.href = url;
            })
            .finally(function () {
                loading = false;
            });
    }

    function handleLinkClick(event, link) {
        if (event.defaultPrevented || event.button !== 0) {
            return;
        }
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        if (shouldBypassLink(link)) {
            return;
        }
        if (!isPanelUrl(link.href)) {
            return;
        }

        var url = link.href;
        var onAdminPage = document.body.classList.contains('page-admin');
        var inOverlay = overlay.contains(link);

        if (inOverlay || link.hasAttribute('data-account-panel')) {
            event.preventDefault();
            if (!isOpen) {
                openOverlay(url);
            } else {
                loadUrl(url, true);
            }
            return;
        }

        if (onAdminPage && url.indexOf('/admin') >= 0) {
            event.preventDefault();
            loadInPlaceAdmin(url, true);
        }
    }

    function wirePanelLinks(root) {
        root.querySelectorAll('a[href]').forEach(function (link) {
            if (link.dataset.accountWired === '1') {
                return;
            }
            link.dataset.accountWired = '1';
            link.addEventListener('click', function (event) {
                handleLinkClick(event, link);
            });
        });
    }

    function shouldHandleGetForm(form) {
        if (!form || form.method.toLowerCase() !== 'get') {
            return false;
        }
        if (form.hasAttribute('data-account-bypass')) {
            return false;
        }
        if (overlay.contains(form) && isOpen) {
            return true;
        }
        if (document.body.classList.contains('page-admin') && form.closest('.admin-main')) {
            return true;
        }
        return false;
    }

    function submitGetFormInPanel(form) {
        var action = form.getAttribute('action') || window.location.pathname;
        var url = new URL(action, window.location.href);
        var params = new URLSearchParams(new FormData(form));
        url.search = params.toString();
        var target = url.pathname + url.search;

        if (isOpen && overlay.contains(form)) {
            loadUrl(target, false);
            return;
        }

        if (document.body.classList.contains('page-admin') && form.closest('.admin-main')) {
            loadInPlaceAdmin(target, false);
        }
    }

    function shouldHandleForm(form) {
        if (!form || form.method.toLowerCase() !== 'post') {
            return false;
        }
        if (form.classList.contains('user-menu-signout')) {
            return false;
        }
        if (form.classList.contains('admin-site-lock-form')) {
            return false;
        }
        if (form.hasAttribute('data-account-bypass')) {
            return false;
        }
        if (overlay.contains(form) && isOpen) {
            return true;
        }
        if (document.body.classList.contains('page-admin') && form.closest('.admin-main')) {
            return true;
        }
        if (document.body.classList.contains('page-profile') && form.closest('main.container, main.sc-main')) {
            return true;
        }
        return false;
    }

    function loadInPlaceProfile(url) {
        var pageMain = document.querySelector('body.page-profile main.container, body.page-profile main.sc-main');
        if (!pageMain) {
            window.location.href = url;
            return;
        }

        loading = true;
        pageMain.innerHTML = '<p class="muted account-overlay-loading">' + loadingText() + '</p>';

        fetchPage(url)
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var main = doc.querySelector('main.container, main.sc-main');
                if (!main) {
                    throw new Error('Missing profile content');
                }
                pageMain.innerHTML = main.innerHTML;
                bindConfirmForms(pageMain);
                bindAdminTabs(pageMain);
                wirePanelLinks(pageMain);

                var flash = extractFlash(doc);
                if (flash) {
                    var notice = document.createElement('p');
                    notice.className =
                        'flash ' + (flash.toLowerCase().indexOf('error') >= 0 ? 'flash-error' : 'flash-success');
                    notice.textContent = flash;
                    pageMain.insertBefore(notice, pageMain.firstChild);
                }
            })
            .catch(function () {
                window.location.href = url;
            })
            .finally(function () {
                loading = false;
            });
    }

    function submitFormAjax(form) {
        var action = form.action || window.location.href;
        loading = true;

        fetch(action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(function (response) {
                return response.text().then(function (text) {
                    return { ok: response.ok, url: response.url, text: text };
                });
            })
            .then(function (result) {
                var json = null;
                try {
                    json = JSON.parse(result.text);
                } catch (error) {
                    json = null;
                }

                if (json && typeof json.ok !== 'undefined') {
                    if (handleStaffJsonResponse(json, form)) {
                        return;
                    }
                }

                if (json && json.site_lock && json.unlock_token) {
                    if (showSiteLockConfirmation(json, form)) {
                        return;
                    }
                }

                var lockFromHtml = extractSiteLockFromHtml(result.text);
                if (lockFromHtml && showSiteLockConfirmation(lockFromHtml, form)) {
                    return;
                }

                var targetUrl = result.url || action;
                var parsed = new URL(targetUrl, window.location.href);
                var target = parsed.pathname + parsed.search;

                if (isOpen && overlay.contains(form)) {
                    loadUrl(target, false);
                    return;
                }

                if (document.body.classList.contains('page-admin') && form.closest('.admin-main')) {
                    if (parsed.pathname.indexOf('/admin') !== 0) {
                        window.location.assign(target);
                        return;
                    }
                    loadInPlaceAdmin(target, false);
                    return;
                }

                if (document.body.classList.contains('page-profile')) {
                    loadInPlaceProfile(target);
                }
            })
            .catch(function () {
                setStatus('Could not save changes. Try again.', true);
            })
            .finally(function () {
                loading = false;
            });
    }

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-account-close]')) {
            event.preventDefault();
            closeOverlay(true);
            return;
        }

        var link = event.target.closest('a[href]');
        if (link) {
            handleLinkClick(event, link);
        }
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (shouldHandleGetForm(form)) {
            event.preventDefault();
            if (loading) {
                return;
            }
            submitGetFormInPanel(form);
            return;
        }
        if (!shouldHandleForm(form)) {
            return;
        }
        event.preventDefault();
        if (loading) {
            return;
        }
        submitFormAjax(form);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && isOpen) {
            closeOverlay(true);
        }
    });

    window.addEventListener('popstate', function (event) {
        if (event.state && event.state.accountPanel && isOpen) {
            if (overlayPushDepth > 0) {
                overlayPushDepth--;
            }
            loadUrl(event.state.accountPanel, false);
            return;
        }

        if (document.body.classList.contains('page-admin')) {
            if (event.state && event.state.adminSpa) {
                loadInPlaceAdmin(event.state.adminSpa, false);
                return;
            }
            if (isPanelPath(window.location.pathname)) {
                window.location.reload();
                return;
            }
        }

        if (isOpen) {
            overlayPushDepth = 0;
            pushedState = false;
            closeOverlay(false);
        }
    });

    document.querySelectorAll('[data-account-panel]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            handleLinkClick(event, link);
        });
    });

    if (document.body.classList.contains('page-admin')) {
        var adminShell = document.querySelector('.admin-shell');
        if (adminShell) {
            wirePanelLinks(adminShell);
            var adminMain = document.querySelector('.admin-main') || document;
            bindConfirmForms(adminMain);
            bindAdminTabs(adminMain);
        }
    }

    if (document.body.classList.contains('page-profile')) {
        var main = document.querySelector('main.container, main.sc-main');
        if (main) {
            wirePanelLinks(main);
            bindConfirmForms(main);
        }
    }

    function navigatePanelUrl(url) {
        if (!url) {
            return false;
        }

        var parsed;
        try {
            parsed = new URL(url, window.location.href);
        } catch (error) {
            window.location.href = url;
            return true;
        }

        if (parsed.origin !== window.location.origin) {
            window.location.href = url;
            return true;
        }

        var target = parsed.pathname + parsed.search;
        if (isPanelPath(parsed.pathname)) {
            if (isOpen) {
                loadUrl(target, false);
                return true;
            }
            if (document.body.classList.contains('page-admin')) {
                loadInPlaceAdmin(target, false);
                return true;
            }
            if (document.body.classList.contains('page-profile')) {
                loadInPlaceProfile(target);
                return true;
            }
        }

        window.location.href = url;
        return true;
    }

    window.LatchAccountPanel = {
        navigate: navigatePanelUrl,
        reloadAdmin: function (url) {
            return navigatePanelUrl(url || window.location.pathname + window.location.search);
        },
    };
})();