/*
 * Searchable dropdowns. Every <select> on a page gets a box that opens a list with a search field, so a long list
 * (courses, staff, departments) can be narrowed by typing. The real <select> stays in the form and holds the value:
 * picking an item sets it and fires a normal "change" event, so forms, filters that submit on change, dependent
 * lists (data-lookup-parent) and required checks keep working.
 *
 *   <select data-no-search>   keeps the browser's own dropdown
 *
 * Lists that load when opened (data-lookup, see univite-lookups.js) fetch first, then open. A list with several
 * choices (multiple) ticks items on and off and stays open. Keyboard: arrows move, Enter picks, Esc closes, typing
 * on the closed box opens it with the search started.
 */
(function () {
    'use strict';
    if (window.UvSelect) { return; }

    var boxes = new WeakMap();   // select -> box state
    var openBox = null;
    var syncing = false;

    function text(o) { return (o.textContent || '').replace(/\s+/g, ' ').trim(); }
    function norm(s) { s = (s || '').toLowerCase(); return s.normalize ? s.normalize('NFD').replace(/[\u0300-\u036f]/g, '') : s; }
    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    function eligible(el) {
        return el instanceof HTMLSelectElement && !boxes.has(el) && !el.hasAttribute('data-no-search')
            && !el.closest('[data-no-search]') && !el.closest('.uv-ss-panel');
    }

    function placeholderOf(el) {
        var first = el.options[0];
        return first && first.value === '' ? text(first) : 'Select…';
    }

    /* ------------------------------------------------------------ the closed box */

    function label(st) {
        var el = st.el, chosen = Array.prototype.filter.call(el.options, function (o) { return o.selected && o.value !== ''; });
        var html;
        if (!chosen.length) {
            var first = el.options[el.selectedIndex];
            html = '<span class="uv-ss-placeholder">' + esc(first && first.value === '' ? text(first) : placeholderOf(el)) + '</span>';
        } else if (!el.multiple) {
            html = '<span class="uv-ss-value">' + esc(text(chosen[0])) + '</span>';
        } else {
            html = chosen.slice(0, 3).map(function (o) { return '<span class="uv-ss-chip">' + esc(text(o)) + '</span>'; }).join('')
                + (chosen.length > 3 ? '<span class="uv-ss-more">+' + (chosen.length - 3) + ' more</span>' : '');
        }
        st.label.innerHTML = html;
        st.box.classList.toggle('is-disabled', el.disabled);
        st.box.setAttribute('aria-disabled', el.disabled ? 'true' : 'false');
        st.box.tabIndex = el.disabled ? -1 : 0;
        st.box.classList.toggle('is-invalid', el.classList.contains('is-invalid'));
        if (st.panel && st === openBox) { renderList(st); }
    }

    function sync(el) { var st = boxes.get(el); if (st && !syncing) { label(st); } }

    function build(el) {
        if (!eligible(el)) { return; }
        var wrap = document.createElement('div');
        wrap.className = 'uv-ss';
        var sm = el.classList.contains('form-select-sm') || el.classList.contains('form-control-sm');
        var lg = el.classList.contains('form-select-lg');
        var box = document.createElement('div');
        box.className = 'uv-ss-box form-select' + (sm ? ' form-select-sm' : '') + (lg ? ' form-select-lg' : '') + (el.multiple ? ' is-multiple' : '');
        box.setAttribute('role', 'combobox');
        box.setAttribute('aria-haspopup', 'listbox');
        box.setAttribute('aria-expanded', 'false');
        var lab = el.id && document.querySelector('label[for="' + el.id.replace(/"/g, '') + '"]');
        if (lab) { box.setAttribute('aria-label', text(lab)); }
        var inner = document.createElement('span');
        inner.className = 'uv-ss-label';
        box.appendChild(inner);

        // Keep the width the page gave the select (filters set style="width:auto;max-width:220px").
        if (el.style.width) { wrap.style.width = el.style.width; }
        if (el.style.maxWidth) { wrap.style.maxWidth = el.style.maxWidth; }
        if (el.style.minWidth) { wrap.style.minWidth = el.style.minWidth; }
        if (el.style.flex) { wrap.style.flex = el.style.flex; }
        if (el.classList.contains('w-auto') || el.style.width === 'auto') { wrap.classList.add('is-auto'); }

        el.parentNode.insertBefore(wrap, el);
        wrap.appendChild(el);
        wrap.appendChild(box);
        el.classList.add('uv-ss-native');
        el.tabIndex = -1;
        el.setAttribute('aria-hidden', 'true');

        var st = { el: el, wrap: wrap, box: box, label: inner, panel: null, active: -1, items: [] };
        boxes.set(el, st);
        label(st);

        box.addEventListener('mousedown', function (e) { if (e.button === 0) { e.preventDefault(); toggle(st); } });
        box.addEventListener('keydown', function (e) { keyOnBox(st, e); });
        box.addEventListener('pointerover', function () { if (el.hasAttribute('data-lookup') && window.UvLookups) { window.UvLookups.load(el); } });
        el.addEventListener('change', function () { sync(el); });
        el.addEventListener('invalid', function () { box.classList.add('is-invalid'); });
        el.addEventListener('focus', function () { box.focus(); });

        new MutationObserver(function () { sync(el); }).observe(el, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'class', 'selected'] });
    }

    /* ------------------------------------------------------------ the open list */

    function host(st) { return st.wrap.closest('.modal') || st.wrap.closest('.offcanvas') || document.body; }

    function toggle(st) { if (openBox === st) { close(); } else { open(st); } }

    function open(st, typed) {
        if (st.el.disabled) { return; }
        close();
        var panel = document.createElement('div');
        panel.className = 'uv-ss-panel' + (st.el.multiple ? ' is-multiple' : '');
        panel.innerHTML = '<div class="uv-ss-search"><i class="ri-search-line"></i><input type="text" autocomplete="off" spellcheck="false" placeholder="Type to search…" aria-label="Search the list"></div>'
            + '<div class="uv-ss-list" role="listbox"' + (st.el.multiple ? ' aria-multiselectable="true"' : '') + '></div>'
            + (st.el.multiple ? '<div class="uv-ss-foot"><button type="button" data-ss-clear>Clear</button><button type="button" data-ss-done>Done</button></div>' : '');
        host(st).appendChild(panel);
        st.panel = panel;
        st.input = panel.querySelector('input');
        st.list = panel.querySelector('.uv-ss-list');
        openBox = st;
        st.box.classList.add('is-open');
        st.box.setAttribute('aria-expanded', 'true');
        if (typed) { st.input.value = typed; }
        renderList(st);
        place(st);
        st.input.focus();

        st.input.addEventListener('input', function () { st.active = -1; renderList(st); });
        st.input.addEventListener('keydown', function (e) { keyInList(st, e); });
        panel.addEventListener('mousedown', function (e) { if (e.target !== st.input) { e.preventDefault(); } });
        panel.addEventListener('click', function (e) {
            var item = e.target.closest('[data-ss-i]');
            if (item) { pick(st, +item.getAttribute('data-ss-i')); return; }
            if (e.target.closest('[data-ss-done]')) { close(true); return; }
            if (e.target.closest('[data-ss-clear]')) {
                Array.prototype.forEach.call(st.el.options, function (o) { o.selected = false; });
                changed(st);
            }
        });

        if (st.el.hasAttribute('data-lookup') && window.UvLookups) {
            panel.classList.add('is-loading');
            window.UvLookups.load(st.el).then(function () { panel.classList.remove('is-loading'); if (openBox === st) { renderList(st); place(st); } });
        }
    }

    function close(focusBox) {
        var st = openBox;
        if (!st) { return; }
        openBox = null;
        if (st.panel) { st.panel.remove(); }
        st.panel = null;
        st.box.classList.remove('is-open');
        st.box.setAttribute('aria-expanded', 'false');
        if (focusBox) { st.box.focus(); }
    }

    function place(st) {
        if (!st.panel) { return; }
        var r = st.box.getBoundingClientRect(), p = st.panel, vh = window.innerHeight, vw = window.innerWidth;
        var width = Math.max(r.width, 220);
        p.style.width = Math.min(width, vw - 16) + 'px';
        p.style.left = Math.max(8, Math.min(r.left, vw - width - 8)) + 'px';
        var below = vh - r.bottom - 8, above = r.top - 8;
        var h = Math.min(p.scrollHeight, 340);
        if (below < Math.min(h, 220) && above > below) {
            p.style.top = ''; p.style.bottom = (vh - r.top + 4) + 'px'; p.classList.add('is-up');
            p.style.maxHeight = Math.min(340, above) + 'px';
        } else {
            p.style.bottom = ''; p.style.top = (r.bottom + 4) + 'px'; p.classList.remove('is-up');
            p.style.maxHeight = Math.min(340, Math.max(below, 160)) + 'px';
        }
    }

    function renderList(st) {
        var q = norm(st.input ? st.input.value.trim() : ''), words = q.split(/\s+/).filter(Boolean);
        var html = '', items = [], lastGroup = null;
        Array.prototype.forEach.call(st.el.options, function (o, i) {
            if (st.el.multiple && o.value === '') { return; }
            var t = text(o), g = o.parentNode && o.parentNode.tagName === 'OPTGROUP' ? o.parentNode.label : null;
            var hay = norm(t + ' ' + (g || ''));
            if (words.length && !words.every(function (w) { return hay.indexOf(w) > -1; })) { return; }
            if (g !== lastGroup) { if (g) { html += '<div class="uv-ss-group">' + esc(g) + '</div>'; } lastGroup = g; }
            var shown = esc(t);
            words.forEach(function (w) {
                var at = norm(t).indexOf(w);
                if (at > -1 && shown === esc(t)) { shown = esc(t.slice(0, at)) + '<mark>' + esc(t.slice(at, at + w.length)) + '</mark>' + esc(t.slice(at + w.length)); }
            });
            var cls = 'uv-ss-item' + (o.selected && (o.value !== '' || !st.el.multiple) ? ' is-selected' : '') + (o.disabled ? ' is-disabled' : '') + (o.value === '' ? ' is-blank' : '');
            html += '<div class="' + cls + '" role="option" data-ss-i="' + i + '" aria-selected="' + (o.selected ? 'true' : 'false') + '">'
                + (st.el.multiple ? '<span class="uv-ss-tick"><i class="ri-check-line"></i></span>' : '') + '<span>' + shown + '</span>'
                + (!st.el.multiple && o.selected && o.value !== '' ? '<i class="ri-check-line uv-ss-check"></i>' : '') + '</div>';
            items.push(i);
        });
        if (!items.length) {
            html = '<div class="uv-ss-empty">' + (st.panel && st.panel.classList.contains('is-loading') ? 'Loading…' : 'Nothing matches “' + esc(st.input.value.trim()) + '”') + '</div>';
        }
        st.list.innerHTML = html;
        st.items = items;
        if (st.active < 0 || st.active >= items.length) {
            var sel = items.indexOf(st.el.selectedIndex);
            st.active = words.length ? 0 : (sel > -1 ? sel : 0);
        }
        highlight(st);
    }

    function highlight(st) {
        var nodes = st.list.querySelectorAll('[data-ss-i]');
        nodes.forEach(function (n, k) { n.classList.toggle('is-active', k === st.active); });
        var a = nodes[st.active];
        if (a) { var lt = st.list, top = a.offsetTop - lt.offsetTop; if (top < lt.scrollTop) { lt.scrollTop = top; } else if (top + a.offsetHeight > lt.scrollTop + lt.clientHeight) { lt.scrollTop = top + a.offsetHeight - lt.clientHeight; } }
    }

    function changed(st) {
        syncing = true;
        st.el.dispatchEvent(new Event('input', { bubbles: true }));
        st.el.dispatchEvent(new Event('change', { bubbles: true }));
        syncing = false;
        st.el.classList.remove('is-invalid');
        label(st);
    }

    function pick(st, index) {
        var o = st.el.options[index];
        if (!o || o.disabled) { return; }
        if (st.el.multiple) {
            o.selected = !o.selected;
            st.active = st.items.indexOf(index);
            changed(st);
            return;
        }
        var before = st.el.value;
        st.el.selectedIndex = index;
        close(true);
        if (st.el.value !== before) { changed(st); } else { label(st); }
    }

    /* ------------------------------------------------------------ keyboard */

    function keyOnBox(st, e) {
        if (st.el.disabled) { return; }
        if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === 'F4') { e.preventDefault(); open(st); return; }
        if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) { e.preventDefault(); open(st, e.key); }
    }

    function keyInList(st, e) {
        var n = st.items.length;
        if (e.key === 'ArrowDown') { e.preventDefault(); st.active = Math.min(n - 1, st.active + 1); highlight(st); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); st.active = Math.max(0, st.active - 1); highlight(st); }
        else if (e.key === 'PageDown') { e.preventDefault(); st.active = Math.min(n - 1, st.active + 8); highlight(st); }
        else if (e.key === 'PageUp') { e.preventDefault(); st.active = Math.max(0, st.active - 8); highlight(st); }
        else if (e.key === 'Enter') { e.preventDefault(); if (n) { pick(st, st.items[st.active]); } }
        else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(true); }
        else if (e.key === 'Tab') { close(); }
    }

    document.addEventListener('mousedown', function (e) {
        if (openBox && !openBox.panel.contains(e.target) && !openBox.box.contains(e.target)) { close(); }
    }, true);
    window.addEventListener('resize', function () { if (openBox) { place(openBox); } });
    document.addEventListener('scroll', function (e) {
        if (openBox && !(openBox.panel && openBox.panel.contains(e.target))) { place(openBox); }
    }, true);
    document.addEventListener('hide.bs.modal', function () { close(); });

    // A label click focuses the box, as it would the select.
    document.addEventListener('click', function (e) {
        var lab = e.target.closest && e.target.closest('label[for]');
        if (!lab) { return; }
        var el = document.getElementById(lab.getAttribute('for'));
        var st = el && boxes.get(el);
        if (st) { e.preventDefault(); st.box.focus(); }
    });

    // Scripts that set a value directly (el.value = …, a form reset) update the box too.
    function hook(proto, prop) {
        var d = Object.getOwnPropertyDescriptor(proto, prop);
        if (!d || !d.set) { return; }
        Object.defineProperty(proto, prop, {
            configurable: true, enumerable: d.enumerable, get: d.get,
            set: function (v) {
                d.set.call(this, v);
                var sel = this instanceof HTMLSelectElement ? this : (this.parentNode && (this.parentNode.tagName === 'SELECT' ? this.parentNode : this.parentNode.parentNode));
                if (sel && boxes.has(sel)) { sync(sel); }
            }
        });
    }
    hook(HTMLSelectElement.prototype, 'value');
    hook(HTMLSelectElement.prototype, 'selectedIndex');
    hook(HTMLOptionElement.prototype, 'selected');
    document.addEventListener('reset', function (e) {
        setTimeout(function () { e.target.querySelectorAll && e.target.querySelectorAll('select').forEach(sync); });
    });

    /* ------------------------------------------------------------ start */

    function init(root) { (root || document).querySelectorAll('select').forEach(build); }

    var pending = false;
    new MutationObserver(function (records) {
        if (pending) { return; }
        var found = records.some(function (r) {
            return Array.prototype.some.call(r.addedNodes, function (n) { return n.nodeType === 1 && (n.tagName === 'SELECT' || (n.querySelector && n.querySelector('select'))); });
        });
        if (!found) { return; }
        pending = true;
        requestAnimationFrame(function () { pending = false; init(); });
    }).observe(document.documentElement, { childList: true, subtree: true });

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { init(); }); } else { init(); }
    document.addEventListener('uv:page', function () { init(); });

    window.UvSelect = { init: init, refresh: sync, close: close };
})();
