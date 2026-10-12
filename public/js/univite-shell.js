/*
 * The portal shell: light/dark switch, top-bar drop-downs, sidebar touches (active page, ripple), back-to-top and the
 * search box (Ctrl+K / ⌘K). Ported from the topclass-portal look. The search box lists the pages of the sidebar menu
 * (filtered by what the person may open) and asks the server for records (students, staff, courses, invoices …).
 */
(function () {
    'use strict';

    function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
    function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function store(k, v) { try { if (v === undefined) { return localStorage.getItem(k); } if (v === null) { localStorage.removeItem(k); } else { localStorage.setItem(k, v); } } catch (e) { return null; } }

    /* ── drop-downs that close on outside click or Escape ── */
    function dropdown(btnId, panelId) {
        var btn = document.getElementById(btnId), panel = document.getElementById(panelId);
        if (!btn || !panel) { return; }
        function close() { panel.style.display = 'none'; btn.setAttribute('aria-expanded', 'false'); }
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = panel.style.display === 'none';
            qsa('.uv-pop').forEach(function (p) { p.style.display = 'none'; });
            panel.style.display = open ? 'block' : 'none';
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.addEventListener('click', function (e) { if (!btn.contains(e.target) && !panel.contains(e.target)) { close(); } });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); } });
    }

    /* ── light / dark / same as device ── */
    function initTheme() {
        var html = document.documentElement, icon = document.getElementById('theme-icon');
        var ICON = { light: 'ri-sun-line fs-20', dark: 'ri-moon-line fs-20', auto: 'ri-contrast-2-line fs-20' };
        var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
        function apply(mode) {
            var scheme = mode === 'auto' ? (media && media.matches ? 'dark' : 'light') : mode;
            html.setAttribute('data-bs-theme', scheme);
            html.setAttribute('data-topbar', scheme === 'dark' ? 'dark' : 'light');
            try { sessionStorage.setItem('data-bs-theme', scheme); } catch (e) { /* Velzon's own memory of the choice */ }
            if (icon) { icon.className = ICON[mode] || ICON.light; }
            store('uv-theme', mode);
            qsa('.theme-mode-item').forEach(function (a) { a.classList.toggle('is-current', a.getAttribute('data-mode') === mode); });
        }
        apply(store('uv-theme') || 'light');
        qsa('.theme-mode-item').forEach(function (a) {
            a.addEventListener('click', function (e) { e.preventDefault(); apply(a.getAttribute('data-mode')); document.getElementById('theme-dropdown').style.display = 'none'; });
        });
        if (media && media.addEventListener) { media.addEventListener('change', function () { if (store('uv-theme') === 'auto') { apply('auto'); } }); }
    }

    /* ── sidebar: bring the current page into view, ripple on click ── */
    function initSidebar() {
        var current = qs('#navbar-nav .nav-active-child') || qs('#navbar-nav .nav-link.active');
        if (current) { setTimeout(function () { current.scrollIntoView({ block: 'center' }); }, 350); }
        qsa('#navbar-nav .nav-link').forEach(function (link) {
            link.addEventListener('click', function (e) {
                if (link.hasAttribute('data-bs-toggle')) { return; }
                var r = document.createElement('span'), rect = link.getBoundingClientRect(), s = Math.max(rect.width, rect.height);
                r.className = 'nav-ripple';
                r.style.cssText = 'width:' + s + 'px;height:' + s + 'px;left:' + (e.clientX - rect.left - s / 2) + 'px;top:' + (e.clientY - rect.top - s / 2) + 'px;';
                link.appendChild(r);
                setTimeout(function () { if (r.parentNode) { r.parentNode.removeChild(r); } }, 650);
            });
        });
    }

    function initBackToTop() {
        var btn = document.getElementById('back-to-top');
        if (!btn) { return; }
        window.addEventListener('scroll', function () { btn.classList.toggle('show', window.scrollY > 300); }, { passive: true });
        btn.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
    }

    function initSearchTooltip() {
        var btn = document.getElementById('spotlight-trigger'), tip = qs('.search-tooltip');
        if (!btn || !tip) { return; }
        btn.addEventListener('mouseenter', function () { tip.style.opacity = '1'; });
        btn.addEventListener('mouseleave', function () { tip.style.opacity = '0'; });
    }

    /* ────────────────────────────── search box ────────────────────────────── */
    function initSpotlight() {
        var overlay = document.getElementById('spotlight-overlay'), dataEl = document.getElementById('spotlight-data');
        if (!overlay || !dataEl) { return; }
        var data = {};
        try { data = JSON.parse(dataEl.textContent || '{}'); } catch (e) { data = {}; }
        var PAGES = data.pages || [], URL_SEARCH = data.url, HISTORY_KEY = 'uv-search-history-' + (data.user || '');

        var COLORS = { 'Menu': '#4f8ef7', 'My Portal': '#20c997', 'Students': '#e76f51', 'Applicants': '#f4a261', 'Staff': '#457b9d', 'User Accounts': '#405189',
            'Courses': '#e9c46a', 'Programmes': '#0a9396', 'Departments': '#0a9396', 'Invoices & Payments': '#10b981', 'Library': '#6a0572', 'Venues': '#4f8ef7', 'Hostels': '#2a9d8f' };
        function color(cat) { return COLORS[cat] || '#4f8ef7'; }
        var POPULAR = [{ label: 'Students', q: 'students' }, { label: 'Invoices', q: 'invoice' }, { label: 'Results', q: 'results' }, { label: 'Timetables', q: 'timetable' },
            { label: 'Courses', q: 'courses' }, { label: 'Payroll', q: 'payroll' }, { label: 'Leave', q: 'leave' }, { label: 'Library', q: 'library' }];

        var input = document.getElementById('spotlight-input'), list = document.getElementById('spotlight-list'), emptyEl = document.getElementById('spotlight-empty'),
            emptyHtml = emptyEl.innerHTML, loadEl = document.getElementById('spotlight-loading'), histSec = document.getElementById('spotlight-history-section'),
            histList = document.getElementById('spotlight-history-list'), suggestEl = document.getElementById('spotlight-suggestions'),
            chipWrap = document.getElementById('spotlight-suggestion-chips'), clearMain = document.getElementById('spotlight-clear-history');
        var timer = null, active = -1, results = [], seq = 0;

        function history() { try { return JSON.parse(store(HISTORY_KEY) || '[]'); } catch (e) { return []; } }
        function saveHistory(h) { store(HISTORY_KEY, JSON.stringify(h.slice(0, 10))); }
        function remember(query, r) {
            if (!query || query.trim().length < 2) { return; }
            var h = history().filter(function (x) { return x.url !== r.url; });
            h.unshift({ query: query.trim(), url: r.url, title: r.title, icon: r.icon, category: r.category });
            saveHistory(h);
        }
        function go(r) { remember(input.value, r); window.location.href = r.url; }

        function score(p, q) {
            var lq = q.toLowerCase().trim(), lt = p.title.toLowerCase(), lc = p.category.toLowerCase(), lk = (p.keywords || []).join(' ').toLowerCase(), s = 0;
            if (lt === lq) { s += 100; }
            if (lt.indexOf(lq) === 0) { s += 60; }
            if (lt.indexOf(lq) !== -1) { s += 40; }
            if (lc.indexOf(lq) !== -1) { s += 20; }
            if (lk.indexOf(lq) !== -1) { s += 30; }
            var words = lq.split(/\s+/).filter(Boolean);
            if (words.length > 1 && words.every(function (w) { return lt.indexOf(w) !== -1 || lc.indexOf(w) !== -1 || lk.indexOf(w) !== -1; })) { s += 35; }
            if (s === 0 && lq.length >= 2) { // letters in order, e.g. "invpay" finds "Invoices & Payments"
                var at = 0;
                for (var i = 0; i < lq.length && at !== -1; i++) { at = lt.indexOf(lq[i], at); if (at !== -1) { at++; } }
                if (at !== -1) { s += 10; }
            }
            return s;
        }
        function searchPages(q) {
            return PAGES.map(function (p) { return { p: p, s: score(p, q) }; }).filter(function (x) { return x.s > 0; })
                .sort(function (a, b) { return b.s - a.s; }).slice(0, 12).map(function (x) { return x.p; });
        }
        function suggestions(q) {
            if (!q) { return POPULAR; }
            var lq = q.toLowerCase(), cats = {};
            PAGES.forEach(function (p) {
                if (p.title.toLowerCase().indexOf(lq) !== -1 || (p.keywords || []).join(' ').toLowerCase().indexOf(lq) !== -1 || p.category.toLowerCase().indexOf(lq) !== -1) { cats[p.category] = (cats[p.category] || 0) + 1; }
            });
            var names = Object.keys(cats).sort(function (a, b) { return cats[b] - cats[a]; }).slice(0, 6);
            return names.length ? names.map(function (c) { return { label: c, q: c }; }) : POPULAR.slice(0, 5);
        }

        function chips(items) {
            chipWrap.innerHTML = '';
            suggestEl.style.display = items.length ? 'block' : 'none';
            items.forEach(function (s, i) {
                var b = document.createElement('button');
                b.type = 'button'; b.className = 'sl-chip sl-anim'; b.style.animationDelay = (i * 0.04) + 's'; b.textContent = s.label;
                b.addEventListener('click', function () { input.value = s.q; input.focus(); run(s.q); });
                chipWrap.appendChild(b);
            });
        }
        function renderHistory() {
            var h = history(), show = h.length > 0 && !input.value.trim();
            histSec.style.display = show ? 'block' : 'none';
            clearMain.style.display = show ? 'inline-block' : 'none';
            histList.innerHTML = '';
            if (!show) { return; }
            h.forEach(function (item, idx) {
                var div = document.createElement('div');
                div.className = 'sl-item sl-anim'; div.style.animationDelay = (idx * 0.04) + 's';
                var c = color(item.category);
                div.innerHTML = '<span class="sl-icon" style="background:' + c + '22;color:' + c + '"><i class="' + esc(item.icon || 'ri-history-line') + '"></i></span>'
                    + '<span class="sl-text"><span class="sl-title">' + esc(item.title) + '</span><span class="sl-sub">' + esc(item.category) + '</span></span>'
                    + '<button type="button" class="sl-x" aria-label="Remove">✕</button>';
                div.querySelector('.sl-x').addEventListener('click', function (e) { e.stopPropagation(); var x = history(); x.splice(idx, 1); saveHistory(x); empty(); });
                div.addEventListener('click', function () { window.location.href = item.url; });
                histList.appendChild(div);
            });
        }
        function empty() {
            emptyEl.innerHTML = emptyHtml; emptyEl.style.display = 'block';
            loadEl.style.display = 'none'; list.style.display = 'none'; list.innerHTML = '';
            results = []; active = -1;
            renderHistory(); chips(POPULAR);
        }
        function loading() { emptyEl.style.display = 'none'; loadEl.style.display = 'block'; list.style.display = 'none'; histSec.style.display = 'none'; }

        function mark(title, q) {
            var i = title.toLowerCase().indexOf(q.toLowerCase());
            return i === -1 ? esc(title) : esc(title.slice(0, i)) + '<mark>' + esc(title.slice(i, i + q.length)) + '</mark>' + esc(title.slice(i + q.length));
        }
        function render(items, q) {
            loadEl.style.display = 'none'; histSec.style.display = 'none'; clearMain.style.display = 'none';
            list.innerHTML = ''; active = -1; results = items;
            if (!items.length) {
                list.style.display = 'none';
                emptyEl.innerHTML = '<i class="ri-search-eye-line"></i><span>No results for "' + esc(q) + '"</span><div class="sl-hint">Try another word, or browse from the sidebar.</div>';
                emptyEl.style.display = 'block';
                chips(POPULAR);
                return;
            }
            emptyEl.style.display = 'none'; list.style.display = 'block';
            var groups = {}, order = [];
            items.forEach(function (r) { if (!groups[r.category]) { groups[r.category] = []; order.push(r.category); } groups[r.category].push(r); });
            var n = 0;
            order.forEach(function (cat) {
                var h = document.createElement('li'); h.className = 'sl-cat'; h.textContent = cat; list.appendChild(h);
                groups[cat].forEach(function (r) {
                    var li = document.createElement('li'), idx = n, c = color(r.category);
                    li.className = 'sl-item sl-anim' + (idx === 0 ? ' top-match' : ''); li.style.animationDelay = Math.min(idx, 8) * 0.03 + 's';
                    li.setAttribute('data-idx', idx);
                    li.innerHTML = '<span class="sl-icon" style="background:' + c + '22;color:' + c + '"><i class="' + esc(r.icon || 'ri-arrow-right-s-line') + '"></i></span>'
                        + '<span class="sl-text"><span class="sl-title">' + mark(r.title, q) + '</span><span class="sl-sub">' + esc(r.subtitle || r.category) + '</span></span>'
                        + '<i class="ri-arrow-right-line sl-go"></i>';
                    li.addEventListener('mouseenter', function () { active = idx; highlight(); });
                    li.addEventListener('click', function () { go(r); });
                    list.appendChild(li);
                    n++;
                });
            });
            // keep the flat order the same as the grouped display, for the arrow keys
            results = order.reduce(function (all, cat) { return all.concat(groups[cat]); }, []);
        }
        function highlight() {
            qsa('li[data-idx]', list).forEach(function (li) {
                var on = Number(li.getAttribute('data-idx')) === active;
                li.classList.toggle('is-active', on);
                if (on) { li.scrollIntoView({ block: 'nearest' }); }
            });
        }

        function run(q) {
            q = (q || '').trim();
            if (!q) { empty(); return; }
            var pages = searchPages(q), mine = ++seq;
            chips(suggestions(q));
            if (pages.length) { render(pages, q); } else { loading(); }
            clearTimeout(timer);
            if (q.length < 2 || !URL_SEARCH) { if (!pages.length) { render([], q); } return; }
            timer = setTimeout(function () {
                fetch(URL_SEARCH + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                    .then(function (r) { return r.ok ? r.json() : { results: [] }; })
                    .then(function (d) {
                        if (mine !== seq) { return; } // a newer search has started
                        var seen = {}, merged = pages.concat(d.results || []).filter(function (r) { if (seen[r.url]) { return false; } seen[r.url] = true; return true; });
                        render(merged, q);
                    })
                    .catch(function () { if (mine === seq) { render(pages, q); } });
            }, 250);
        }

        function open() {
            overlay.style.display = 'flex'; overlay.classList.add('is-open');
            setTimeout(function () { input.focus(); }, 60);
            empty();
        }
        function close() { overlay.classList.remove('is-open'); overlay.style.display = 'none'; input.value = ''; }
        function isOpen() { return overlay.style.display === 'flex'; }

        ['spotlight-trigger', 'spotlight-trigger-sm'].forEach(function (id) { var b = document.getElementById(id); if (b) { b.addEventListener('click', open); } });
        document.getElementById('spotlight-esc').addEventListener('click', close);
        document.getElementById('spotlight-clear-history-btn').addEventListener('click', function () { store(HISTORY_KEY, null); empty(); });
        clearMain.addEventListener('click', function () { store(HISTORY_KEY, null); empty(); });
        overlay.addEventListener('click', function (e) { if (e.target === overlay) { close(); } });
        document.addEventListener('keydown', function (e) {
            if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); if (isOpen()) { close(); } else { open(); } }
            else if (e.key === '/' && !isOpen() && !/^(input|textarea|select)$/i.test((e.target.tagName || '')) && !e.target.isContentEditable) { e.preventDefault(); open(); }
            else if (e.key === 'Escape' && isOpen()) { close(); }
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, results.length - 1); highlight(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); highlight(); }
            else if (e.key === 'Enter') { e.preventDefault(); var r = results[active >= 0 ? active : 0]; if (r) { go(r); } }
        });
        input.addEventListener('input', function () { run(input.value); });
    }

    function start() {
        initTheme();
        dropdown('theme-toggle-btn', 'theme-dropdown');
        dropdown('pn-btn', 'pn-dropdown');
        dropdown('user-menu-btn', 'user-dropdown');
        initSidebar();
        initBackToTop();
        initSearchTooltip();
        initSpotlight();
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
