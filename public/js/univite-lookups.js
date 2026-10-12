/*
 * Dropdowns that load their options when they are opened, so a faculty, programme or session added on another page
 * shows up without reloading this one.
 *
 *   <select name="department_id" data-lookup="departments" data-lookup-parent="faculty_id" data-lookup-active="1">
 *
 * data-lookup         the list to fetch from /lookups/{list} (see App\Services\Lookups\LookupService)
 * data-lookup-parent  optional: the name of another field in the same form; its value narrows this list and
 *                     changing it reloads this one
 * data-lookup-active  optional: only active rows
 * Options already on the page stay until the fresh list arrives; options with an empty value (Select…, All ...) and
 * the current choice are always kept. If the connection drops, the user is told and the list loads when it is back.
 */
(function () {
    'use strict';
    if (window.UvLookups) { return; }

    var FRESH_MS = 15000;
    var base = (document.querySelector('meta[name="uv-lookups"]') || {}).content || '/lookups';
    var state = new WeakMap();   // select -> { at, parent, busy }

    function info(el) { if (!state.has(el)) { state.set(el, { at: 0, parent: null, busy: null, parentChanged: false }); } return state.get(el); }

    function parentField(el) {
        var name = el.getAttribute('data-lookup-parent');
        if (!name) { return null; }
        var scope = el.form || el.closest('form') || document;
        return scope.querySelector('[name="' + name.replace(/"/g, '') + '"]');
    }

    function parentValue(el) { var p = parentField(el); return p ? (p.value || '') : ''; }

    function isFresh(el) { var s = info(el); return s.at && Date.now() - s.at < FRESH_MS && s.parent === parentValue(el); }

    function url(el) {
        var q = new URLSearchParams();
        var pv = parentValue(el); if (pv) { q.set('parent', pv); }
        if (el.getAttribute('data-lookup-active') === '1') { q.set('active', '1'); }
        var s = q.toString();
        return base.replace(/\/$/, '') + '/' + encodeURIComponent(el.getAttribute('data-lookup')) + (s ? '?' + s : '');
    }

    function selectedValues(el) { return Array.prototype.filter.call(el.options, function (o) { return o.selected; }).map(function (o) { return o.value; }); }

    function rebuild(el, options) {
        var keep = selectedValues(el);
        var before = keep.join('\u0001');
        var placeholders = Array.prototype.filter.call(el.options, function (o) { return o.value === ''; });
        var old = {};
        Array.prototype.forEach.call(el.options, function (o) { old[o.value] = o.textContent; });

        var frag = document.createDocumentFragment(), groups = {}, seen = {};
        placeholders.forEach(function (o) { frag.appendChild(o); });
        options.forEach(function (opt) {
            var o = document.createElement('option');
            o.value = opt.value; o.textContent = opt.label; seen[opt.value] = true;
            if (keep.indexOf(opt.value) > -1) { o.selected = true; }
            if (opt.group) {
                if (!groups[opt.group]) { groups[opt.group] = document.createElement('optgroup'); groups[opt.group].label = opt.group; frag.appendChild(groups[opt.group]); }
                groups[opt.group].appendChild(o);
            } else {
                frag.appendChild(o);
            }
        });
        // A current choice that is no longer listed (made inactive, or outside the new parent) is kept only when the
        // parent did not change, so an existing record still shows what it holds.
        var parentChanged = info(el).parentChanged;
        info(el).parentChanged = false;
        keep.forEach(function (v) {
            if (v === '' || seen[v] || parentChanged) { return; }
            var o = document.createElement('option'); o.value = v; o.textContent = old[v] || v; o.selected = true; frag.appendChild(o);
        });

        el.innerHTML = '';
        el.appendChild(frag);
        if (selectedValues(el).join('\u0001') !== before) { el.dispatchEvent(new Event('change', { bubbles: true })); }
    }

    function busy(el, on) {
        el.classList.toggle('uv-lookup-loading', on);
        if (on) { el.setAttribute('aria-busy', 'true'); } else { el.removeAttribute('aria-busy'); }
    }

    function label(el) {
        var lab = el.id && document.querySelector('label[for="' + el.id + '"]');
        var text = (lab ? lab.textContent : el.getAttribute('data-lookup').replace(/-/g, ' ')).replace(/\*/g, '').trim();
        return 'loading the ' + text.toLowerCase() + ' list';
    }

    /** Fetch the list for one select. Resolves true when fresh options are in place. */
    function load(el) {
        var s = info(el);
        if (s.busy) { return s.busy; }
        if (isFresh(el)) { return Promise.resolve(true); }
        busy(el, true);
        var mine = window.Uv.request(url(el), { timeout: 20000 })
            .then(function (out) {
                if (!out.res.ok || !out.data || !Array.isArray(out.data.options)) { return false; }
                rebuild(el, out.data.options);
                s.at = Date.now(); s.parent = parentValue(el);
                return true;
            })
            .catch(function (err) {
                if (err && (err.network || err.timeout)) {
                    s.busy = null; busy(el, false);
                    return window.Uv.whenOnline(label(el)).then(function (again) { return again ? load(el).then(function (ok) { if (ok) { open(el); } return ok; }) : false; });
                }
                return false;
            });
        s.busy = mine;
        mine.then(function () { if (s.busy === mine) { s.busy = null; } busy(el, !!s.busy); });
        return mine;
    }

    function open(el) {
        el.focus();
        if (el.multiple || el.size > 1) { return; }
        try { if (el.showPicker) { el.showPicker(); } } catch (e) { /* not allowed without a fresh click in some browsers */ }
    }

    function target(e) { var t = e.target; return t && t.closest ? t.closest('select[data-lookup]') : null; }

    // Start loading as soon as the pointer or keyboard reaches the dropdown, so it is usually ready by the click.
    document.addEventListener('pointerover', function (e) { var el = target(e); if (el && !el.disabled && !isFresh(el)) { load(el); } });
    document.addEventListener('focusin', function (e) { var el = target(e); if (el && !el.disabled && !isFresh(el)) { load(el); } });

    // A click on a stale dropdown waits for the fresh list, then opens it.
    document.addEventListener('mousedown', function (e) {
        var el = target(e);
        if (!el || el.disabled || el.multiple || el.size > 1 || isFresh(el) || e.button !== 0) { return; }
        e.preventDefault();
        load(el).then(function (ok) { if (ok) { open(el); } else { el.focus(); } });
    });

    // Changing a parent (faculty) reloads the lists that depend on it (departments).
    document.addEventListener('change', function (e) {
        var changed = e.target;
        if (!changed || !changed.name) { return; }
        var scope = changed.form || document;
        scope.querySelectorAll('select[data-lookup-parent="' + changed.name.replace(/"/g, '') + '"]').forEach(function (el) {
            info(el).at = 0;
            info(el).parentChanged = true;
            load(el);
        });
    });

    window.UvLookups = { load: load, refresh: function (el) { info(el).at = 0; return load(el); } };
})();
