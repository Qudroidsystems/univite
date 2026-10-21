/*
 * UniVite navigation: the portal behaves like a single-page app without being rewritten as one.
 *
 *  - Every link (content, sidebar, top bar, search box) loads the next page in place: the shell stays, only the page
 *    body changes, the sidebar's active item and the notification count follow.
 *  - Staying on the same page (filters, pagination, sorting, live search, refreshing after a save) updates only the
 *    blocks whose content changed; the rest of the page is left untouched, the scroll position is kept and the field
 *    being typed in keeps its focus, caret and text.
 *  - Links are fetched when the pointer rests on them, so most pages open instantly.
 *  - Back and forward show the page as it was at once, then refresh it quietly.
 *  - Search boxes in filter forms search as you type; drop-downs, dates and ticks in a page's filter form apply at once.
 *  - <div data-uv-lazy="url" [data-uv-select="#id"]> loads a slow section after the page has appeared.
 *
 * Opt out: <meta name="uv-ajax" content="off"> for a page, data-no-ajax on a link or form, data-no-prefetch on a link,
 * data-uv-manual on a filter form that should wait for its button.
 */
(function () {
    'use strict';
    var Uv = window.Uv;
    if (!Uv || Uv.nav) { return; }
    var Nav = Uv.nav = {};

    function meta(name) { var m = document.querySelector('meta[name="' + name + '"]'); return m ? m.getAttribute('content') : null; }
    function root() { return document.getElementById('ajax-root'); }
    function abs(url) { return new URL(url, location.href); }
    function key(url) { var u = abs(url); return u.pathname + u.search; }

    /* ------------------------------------------------------------------ cache */

    // Fetched pages: prefetches (short-lived) and pages left behind (for back/forward).
    var cache = {}, PREFETCH_TTL = 20000, HISTORY_TTL = 10 * 60000, MAX = 30;
    function remember(url, html, finalUrl, ttl, kind) {
        cache[key(url)] = { html: html, url: finalUrl || url, at: Date.now(), ttl: ttl, kind: kind || 'history' };
        var keys = Object.keys(cache);
        if (keys.length > MAX) { keys.sort(function (a, b) { return cache[a].at - cache[b].at; }).slice(0, keys.length - MAX).forEach(function (k) { delete cache[k]; }); }
    }
    function cached(url, ttl, kind) {
        var c = cache[key(url)];
        return c && c.html && (!kind || c.kind === kind) && Date.now() - c.at < (ttl || c.ttl) ? c : null;
    }
    Nav.forget = function () { cache = {}; };
    // Anything saved makes every remembered page out of date.
    document.addEventListener('submit', function (e) { if ((e.target.getAttribute('method') || 'GET').toUpperCase() !== 'GET') { Nav.forget(); } }, true);

    /* ----------------------------------------------------------- fetching */

    var inflight = {};
    function load(url) {
        var k = key(url);
        if (inflight[k]) { return inflight[k]; }
        var p = Uv.request(url, { html: true }).then(function (out) {
            var res = out.res, ct = res.headers.get('content-type') || '';
            var r = { res: res, url: res.url || abs(url).href, status: res.status, html: null };
            if (res.ok && ct.indexOf('text/html') !== -1) { return res.text().then(function (h) { r.html = h; return r; }); }
            return r;
        });
        inflight[k] = p;
        p.then(function () { delete inflight[k]; }, function () { delete inflight[k]; });
        return p;
    }

    /* ---------------------------------------------------------- prefetching */

    var SKIP = /\/(logout|export|exports|download|downloads|print|pdf|excel|csv|xlsx|open|delete|destroy|toggle|mark|read|impersonate|leave-impersonation)(\/|\?|$|\.)/i;
    function linkUrl(a) {
        if (!a || a.closest('[data-no-ajax]') || a.hasAttribute('download') || a.hasAttribute('data-bs-toggle')) { return null; }
        var href = a.getAttribute('href');
        if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) { return null; }
        if (a.target && a.target !== '_self') { return null; }
        var u = abs(a.href);
        if (u.origin !== location.origin) { return null; }
        if (u.pathname === location.pathname && u.search === location.search && u.hash) { return null; }
        if (!a.closest('#ajax-root, .app-menu, #page-topbar, #spotlight, .uv-spotlight, [data-uv-nav]')) { return null; }
        return u;
    }
    var hoverTimer = null;
    function prefetch(a) {
        if (!Uv.ajaxOn() || a.hasAttribute('data-no-prefetch') || (Uv.net && (Uv.net.state === 'offline' || Uv.net.state === 'poor'))) { return; }
        var u = linkUrl(a);
        if (!u || SKIP.test(u.pathname) || cached(u.href, PREFETCH_TTL, 'prefetch') || inflight[key(u.href)]) { return; }
        load(u.href).then(function (r) { if (r.html && r.status === 200) { remember(u.href, r.html, r.url, PREFETCH_TTL, 'prefetch'); } }).catch(function () {});
    }
    document.addEventListener('mouseover', function (e) {
        var a = e.target.closest && e.target.closest('a[href]');
        if (!a) { return; }
        clearTimeout(hoverTimer);
        hoverTimer = setTimeout(function () { prefetch(a); }, 70);
    });
    document.addEventListener('mouseout', function () { clearTimeout(hoverTimer); });
    document.addEventListener('touchstart', function (e) { var a = e.target.closest && e.target.closest('a[href]'); if (a) { prefetch(a); } }, { passive: true });

    /* ---------------------------------------------------------- page styles */

    // Styles a page pushes into <head> (@push('styles')) sit between two markers and change with the page.
    function swapStyles(doc) {
        var begin = document.querySelector('meta[name="uv-styles-begin"]'), end = document.querySelector('meta[name="uv-styles-end"]');
        var nb = doc.querySelector('meta[name="uv-styles-begin"]'), ne = doc.querySelector('meta[name="uv-styles-end"]');
        if (!begin || !end || !nb || !ne) { return; }
        var mine = [], theirs = [];
        for (var n = begin.nextSibling; n && n !== end; n = n.nextSibling) { mine.push(n); }
        for (var m = nb.nextSibling; m && m !== ne; m = m.nextSibling) { theirs.push(m); }
        var a = mine.map(function (x) { return x.outerHTML || x.textContent; }).join(''), b = theirs.map(function (x) { return x.outerHTML || x.textContent; }).join('');
        if (a === b) { return; }
        mine.forEach(function (x) { x.remove(); });
        theirs.forEach(function (x) { end.parentNode.insertBefore(document.importNode(x, true), end); });
    }

    /* --------------------------------------------------------- page scripts */

    // External scripts load once, in order, before the inline scripts that follow them run.
    function runScripts(nodes) {
        var chain = Promise.resolve();
        nodes.forEach(function (old) {
            var src = old.getAttribute('src');
            if (src) {
                chain = chain.then(function () {
                    if (document.querySelector('script[src="' + src.replace(/"/g, '\\"') + '"]')) { return; }
                    return new Promise(function (done) {
                        var s = document.createElement('script'); s.src = src; s.onload = s.onerror = function () { done(); };
                        document.body.appendChild(s);
                    });
                });
                return;
            }
            var type = (old.getAttribute('type') || '').toLowerCase();
            if (type && type !== 'text/javascript' && type !== 'module' && type !== 'application/javascript') { return; }
            var code = old.textContent;
            chain = chain.then(function () { try { (new Function(code))(); } catch (e) { window.console && console.error('Page script failed', e); } });
        });
        return chain;
    }
    function pageScripts(doc, scope) {
        var list = Array.prototype.slice.call((scope || doc.getElementById('ajax-root')).querySelectorAll('script'));
        var stack = doc.getElementById('uv-page-scripts');
        return list.concat(stack ? Array.prototype.slice.call(stack.querySelectorAll('script')) : []);
    }

    /* ---------------------------------------------------------- the shell */

    // The sidebar's open group and active item, and the notification bell, follow the page.
    function syncShell(doc) {
        var nav = document.getElementById('navbar-nav'), fresh = doc.getElementById('navbar-nav');
        if (nav && fresh) {
            var cls = {};
            fresh.querySelectorAll('a.nav-link[href]').forEach(function (a) { cls[a.getAttribute('href')] = a; });
            nav.querySelectorAll('a.nav-link[href]').forEach(function (a) {
                var f = cls[a.getAttribute('href')];
                if (!f) { return; }
                a.className = f.className;
                if (f.hasAttribute('aria-expanded')) { a.setAttribute('aria-expanded', f.getAttribute('aria-expanded')); }
            });
            fresh.querySelectorAll('.menu-dropdown[id]').forEach(function (d) {
                var mine = document.getElementById(d.id);
                if (!mine || mine.classList.contains('collapsing')) { return; }
                // keep a group the user opened; open the one holding the new page
                if (d.classList.contains('show')) { mine.classList.add('show'); }
            });
        }
        [['#pn-btn .topbar-badge', '#pn-btn'], ['#pn-dropdown > div:last-child', null]].forEach(function (pair) {
            var now = document.querySelector(pair[0]), then = doc.querySelector(pair[0]);
            if (pair[1]) {
                var host = document.querySelector(pair[1]);
                if (now && !then) { now.remove(); }
                else if (then && !now && host) { host.appendChild(document.importNode(then, true)); }
                else if (now && then && now.outerHTML !== then.outerHTML) { now.replaceWith(document.importNode(then, true)); }
            } else if (now && then && now.innerHTML !== then.innerHTML) { now.innerHTML = then.innerHTML; }
        });
        // phones: the sidebar slides away once a page is chosen
        document.body.classList.remove('vertical-sidebar-enable');
        document.querySelectorAll('.uv-pop').forEach(function (p) { p.style.display = 'none'; });
    }

    function closeModals() {
        document.querySelectorAll('.modal.show').forEach(function (m) { if (window.bootstrap) { var i = bootstrap.Modal.getInstance(m); if (i) { i.hide(); } } });
        document.querySelectorAll('.modal-backdrop').forEach(function (b) { b.remove(); });
        document.querySelectorAll('.popover, .tooltip').forEach(function (p) { p.remove(); });
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    }

    /* ------------------------------------------------- focus and typing state */

    function focusState() {
        var el = document.activeElement;
        if (!el || el === document.body || !root().contains(el) || !/^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName)) { return null; }
        var form = el.form;
        return {
            id: el.id, name: el.name, value: el.value, type: el.type,
            form: form ? (form.id || form.getAttribute('action') || '') : null,
            start: typeof el.selectionStart === 'number' ? el.selectionStart : null, end: typeof el.selectionEnd === 'number' ? el.selectionEnd : null
        };
    }
    function restoreFocus(st) {
        if (!st) { return; }
        var el = st.id ? document.getElementById(st.id) : null;
        if (!el && st.name) {
            var list = root().querySelectorAll('[name="' + st.name.replace(/"/g, '\\"') + '"]');
            el = Array.prototype.filter.call(list, function (x) { return !x.form || !st.form || (x.form.id || x.form.getAttribute('action') || '') === st.form; })[0] || list[0];
        }
        if (!el || el.type === 'hidden') { return; }
        // what the person typed while the page was loading wins over what the server echoed back
        if (/^(text|search|email|number|tel|url)$/.test(el.type || 'text') || el.tagName === 'TEXTAREA') {
            if (el.value !== st.value) { el.value = st.value; }
        }
        try { el.focus({ preventScroll: true }); if (st.start !== null && el.setSelectionRange) { el.setSelectionRange(st.start, st.end); } } catch (e) { /* not focusable */ }
    }

    /* ---------------------------------------------------- partial updates */

    // The blocks of a page: the children of its main column, and of the rows inside it.
    function container(scope) { return scope.querySelector('.page-content > .container-fluid') || scope.querySelector('.container-fluid') || scope; }
    function blocks(scope) {
        var out = [];
        Array.prototype.forEach.call(container(scope).children, function (el) {
            if (el.classList.contains('row') && !el.closest('form')) { Array.prototype.forEach.call(el.children, function (c) { out.push(c); }); }
            else { out.push(el); }
        });
        return out;
    }
    function shape(scope) {
        return blocks(scope).map(function (b) { return (b.parentNode.classList.contains('row') ? 'r>' : '') + b.tagName + '.' + (b.className || '').split(/\s+/).filter(function (c) { return /^(col|card|row|alert|d-|table)/.test(c); }).join('.'); }).join('|');
    }
    // What the server sent for each block, before scripts and enhancements changed the live page.
    var served = new WeakMap();
    function note(scope) { blocks(scope).forEach(function (b) { served.set(b, b.outerHTML); }); }

    /**
     * Replace only the blocks whose server HTML changed. Returns the replaced blocks, or null when the two pages are
     * laid out differently (then the whole body is swapped).
     */
    function patch(freshRoot) {
        var cur = root(), mine = blocks(cur), theirs = blocks(freshRoot);
        if (mine.length !== theirs.length || shape(cur) !== shape(freshRoot)) { return null; }
        var changed = [];
        theirs.forEach(function (t, i) {
            var html = t.outerHTML;
            if (served.get(mine[i]) === html) { return; }
            var el = document.importNode(t, true);
            mine[i].replaceWith(el);
            served.set(el, html);
            changed.push(el);
        });
        return changed;
    }

    /* ------------------------------------------------------------- render */

    var navToken = 0;

    function render(html, finalUrl, opts) {
        var doc = new DOMParser().parseFromString(html, 'text/html'), fresh = doc.getElementById('ajax-root');
        var layout = doc.querySelector('meta[name="uv-layout"]'), off = doc.querySelector('meta[name="uv-ajax"]');
        if (!fresh || !layout || layout.getAttribute('content') !== meta('uv-layout') || (off && off.getAttribute('content') === 'off')) { location.href = finalUrl; return false; }
        pageHtml = html;

        var samePath = abs(finalUrl).pathname === location.pathname, y = window.scrollY, focus = focusState();
        var stack = doc.getElementById('uv-page-scripts'), hasScripts = !!fresh.querySelector('script') || !!(stack && stack.querySelector('script'));
        var cur = root(), changed = null;

        // Same page, no page scripts: patch only what changed. Page scripts bind to their elements, so those pages are
        // swapped whole (their scripts run again on fresh elements), keeping the scroll position.
        if (samePath && !hasScripts && !opts.full) { changed = patch(fresh); }
        if (changed === null) {
            closeModals();
            cur.innerHTML = fresh.innerHTML;
            note(cur);
            changed = [cur];
        } else if (changed.length) {
            document.querySelectorAll('.popover, .tooltip').forEach(function (p) { p.remove(); });
        }

        document.title = doc.title;
        var token = doc.querySelector('meta[name="csrf-token"]'), mineToken = document.querySelector('meta[name="csrf-token"]');
        if (token && mineToken) { mineToken.setAttribute('content', token.getAttribute('content')); }
        swapStyles(doc);
        syncShell(doc);

        var holder = document.getElementById('uv-page-scripts');
        if (holder) { holder.innerHTML = ''; }
        var done = changed[0] === cur ? runScripts(pageScripts(doc)) : Promise.resolve();

        var url = abs(finalUrl).href;
        if (opts.push === false) { /* back/forward: the address is already right */ }
        else if (opts.replace || url === location.href) { history.replaceState({ uv: 1 }, '', url); }
        else { history.pushState({ uv: 1 }, '', url); }

        if (opts.keepScroll || (samePath && opts.keepScroll !== false)) {
            window.scrollTo(0, y);
            // pagination: bring the list into view when its top went above the screen
            var first = changed[0] !== cur && changed[0];
            if (first && first.getBoundingClientRect().top < 0 && !focus) { first.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
        } else if (opts.scrollY !== undefined) {
            window.scrollTo(0, opts.scrollY);
        } else {
            window.scrollTo(0, 0);
        }
        restoreFocus(focus);
        changed.forEach(function (el) { if (el !== cur) { el.classList.add('uv-updated'); setTimeout(function () { el.classList.remove('uv-updated'); }, 700); } });
        Uv.upgrade(cur);
        done.then(function () {
            lazy(cur);
            document.dispatchEvent(new CustomEvent('uv:page', { detail: { url: url, partial: changed[0] !== cur } }));
        });
        return true;
    }

    var dimTimer = null;
    function busy(on) {
        Uv.progress(on);
        clearTimeout(dimTimer);
        if (on) { dimTimer = setTimeout(function () { root().classList.add('uv-loading'); }, 180); }
        else { root().classList.remove('uv-loading'); }
    }

    /** Open a page without reloading. opts: push (false for back/forward), replace, keepScroll, full, scrollY. */
    Uv.visit = Nav.visit = function (url, opts) {
        opts = opts || {};
        if (!Uv.ajaxOn()) { location.href = url; return Promise.resolve(); }
        var token = ++navToken;
        var hit = !opts.fresh && cached(url, PREFETCH_TTL, 'prefetch');
        if (hit) {
            delete cache[key(url)];
            leaving();
            render(hit.html, hit.url, opts);
            return Promise.resolve();
        }
        busy(true);
        return load(url)
            .then(function (r) {
                if (token !== navToken) { return; }
                var finalUrl = r.url;
                if (r.res.redirected && /\/(login|logout)(\?|$|\/)/.test(abs(finalUrl).pathname + '/')) { location.href = finalUrl; return; }
                if (r.status === 403) { return Uv.alert('error', 'Not allowed', 'You do not have permission to open that page.'); }
                if (r.status === 404) { return Uv.alert('error', 'Not found', 'That page does not exist or was removed.'); }
                if (r.status === 419 || r.status === 401) { location.href = '/login'; return; }
                if (!r.html) { location.href = url; return; }
                leaving();
                render(r.html, finalUrl, opts);
            })
            .catch(function (err) {
                if (err && err.aborted) { return; }
                if (err && (err.timeout || err.network)) {
                    return Uv.networkProblem(err, 'opening that page').then(function (again) { if (again) { return Uv.visit(url, opts); } });
                }
                window.console && console.error(err);
                location.href = url;
            })
            .finally(function () { if (token === navToken) { busy(false); } });
    };

    /* ------------------------------------------------------- back / forward */

    // The page being left is kept as the server sent it (with where it was scrolled to), so Back shows it at once.
    var pageHtml = null, scrollAt = {};
    function leaving() {
        scrollAt[location.href] = window.scrollY;
        if (pageHtml) { remember(location.href, pageHtml, location.href, HISTORY_TTL); }
    }

    window.addEventListener('popstate', function () {
        if (!Uv.ajaxOn()) { return; }
        var url = location.href, y = scrollAt[url] || 0, hit = cached(url, HISTORY_TTL);
        if (hit) {
            ++navToken;
            render(hit.html, url, { push: false, scrollY: y, full: true });
            // then quietly bring it up to date
            load(url).then(function (r) { if (r.html && location.href === url) { render(r.html, r.url, { push: false, replace: true, keepScroll: true }); } }).catch(function () {});
        } else {
            Uv.visit(url, { push: false, scrollY: y, fresh: true });
        }
    });

    /* -------------------------------------------------------------- links */

    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || !Uv.ajaxOn()) { return; }
        var a = e.target.closest && e.target.closest('a[href]');
        var u = linkUrl(a);
        if (!u) { return; }
        e.preventDefault();
        Uv.visit(u.href);
    });

    /* ------------------------------------------------------- live filters */

    // A filter form on this page (GET, pointing at this page) applies as you type in its search box and as soon as a
    // drop-down, date or tick changes. Forms with data-uv-manual wait for their button.
    function liveForm(form) {
        if (!form || !Uv.formEligible(form) || form.hasAttribute('data-uv-manual')) { return false; }
        if ((form.getAttribute('method') || 'GET').toUpperCase() !== 'GET') { return false; }
        try { return abs(form.getAttribute('action') || location.href).pathname === location.pathname; } catch (e) { return false; }
    }
    var SEARCH_NAMES = /^(q|search|term|keyword|keywords|query|find|s)$/i;
    function isSearchBox(el) { return el.tagName === 'INPUT' && (el.type === 'search' || el.hasAttribute('data-uv-live') || (el.type === 'text' && SEARCH_NAMES.test(el.name || ''))); }
    function submit(form) {
        form.dataset.uvTyping = '1';
        if (form.requestSubmit) { form.requestSubmit(); } else { form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true })); }
        delete form.dataset.uvTyping;
    }
    var typeTimer = null;
    document.addEventListener('input', function (e) {
        var el = e.target;
        if (!el.form || !isSearchBox(el) || !liveForm(el.form)) { return; }
        clearTimeout(typeTimer);
        typeTimer = setTimeout(function () { submit(el.form); }, 350);
    });
    document.addEventListener('change', function (e) {
        var el = e.target;
        if (!el.form || el.hasAttribute('onchange') || el.hasAttribute('data-uv-auto') || isSearchBox(el) || !liveForm(el.form)) { return; }
        if (!/^(SELECT)$/.test(el.tagName) && !/^(checkbox|radio|date|month|week)$/.test(el.type)) { return; }
        if (el.multiple) { return; }
        submit(el.form);
    });

    /* ------------------------------------------------------- lazy sections */

    /**
     * <div data-uv-lazy="/some/url" data-uv-select="#part">placeholder</div> fills itself once the page is shown: with
     * the element #part of the page at that URL, or the whole response when no selector is given.
     */
    // Several sections of one page often come from one URL: fetch it once.
    var parts = {};
    function fetchPart(url) {
        if (!parts[url]) {
            parts[url] = fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' }, credentials: 'same-origin' })
                .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.text(); });
            parts[url].then(function () { setTimeout(function () { delete parts[url]; }, 1000); }, function () { delete parts[url]; });
        }
        return parts[url];
    }
    function lazy(scope) {
        (scope || document).querySelectorAll('[data-uv-lazy]:not([data-uv-lazy-done])').forEach(function (box) {
            box.setAttribute('data-uv-lazy-done', '1');
            if (!box.innerHTML.trim()) { box.innerHTML = '<div class="uv-skeleton"><span></span><span></span><span></span></div>'; }
            fetchPart(box.getAttribute('data-uv-lazy'))
                .then(function (html) {
                    var sel = box.getAttribute('data-uv-select'), part = html;
                    if (sel) { var d = new DOMParser().parseFromString(html, 'text/html'), el = d.querySelector(sel); part = el ? el.innerHTML : ''; }
                    box.innerHTML = part;
                    runScripts(Array.prototype.slice.call(box.querySelectorAll('script')));
                    Uv.upgrade(box);
                    document.dispatchEvent(new CustomEvent('uv:page', { detail: { url: location.href, partial: true, lazy: box } }));
                })
                .catch(function () { box.innerHTML = '<div class="text-muted fs-13 p-3">Could not load this part. <a href="' + location.href + '">Reload the page</a>.</div>'; });
        });
    }
    Nav.lazy = lazy;

    /* -------------------------------------------------------------- start */

    // This script runs at the end of <body>, before the page's enhancements change it: note the page as served now.
    (function () {
        var r = root();
        if (!r) { return; }
        note(r);
        // the first page is not kept for Back: it was not fetched, so Back loads it afresh
    })();
    function start() { if (root()) { lazy(root()); } }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
