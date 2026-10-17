/*
 * UniVite browser layer:
 *  - saves forms and refreshes pages without a full reload (fetch + page swap)
 *  - shows validation errors next to the fields
 *  - watches the connection (online/offline and its strength) and tells the user with SweetAlert
 * Pages opt out with <meta name="uv-ajax" content="off"> or data-no-ajax on a form or link.
 */
(function () {
    'use strict';
    if (window.Uv) { return; }

    var meta = function (name) { var m = document.querySelector('meta[name="' + name + '"]'); return m ? m.getAttribute('content') : null; };
    var Uv = window.Uv = { config: { ping: meta('uv-ping') || '/ping' } };
    var hasSwal = function () { return typeof window.Swal !== 'undefined'; };
    var brand = '#405189';

    /* ---------------------------------------------------------------- messages */

    var toastMixin = null;
    function toast() {
        if (!hasSwal()) { return null; }
        if (!toastMixin) {
            toastMixin = Swal.mixin({
                toast: true, position: 'top-end', showConfirmButton: false, timer: 3800, timerProgressBar: true,
                didOpen: function (t) { t.addEventListener('mouseenter', Swal.stopTimer); t.addEventListener('mouseleave', Swal.resumeTimer); }
            });
        }
        return toastMixin;
    }
    Uv.toast = function (icon, title) { var t = toast(); if (t) { t.fire({ icon: icon, title: title }); } };

    Uv.alert = function (icon, title, text, html) {
        if (!hasSwal()) { window.console && console.warn(title, text); return Promise.resolve(); }
        return Swal.fire({ icon: icon, title: title, text: html ? undefined : text, html: html, confirmButtonColor: brand });
    };

    Uv.confirm = function (text, opts) {
        opts = opts || {};
        if (!hasSwal()) { return Promise.resolve(window.confirm(text)); }
        var danger = opts.danger !== undefined ? opts.danger : /delete|remove|cannot be undone|cancel|undo|reject|take back|withdraw/i.test(text);
        return Swal.fire({
            title: opts.title || (danger ? 'Are you sure?' : 'Please confirm'), text: text, icon: opts.icon || (danger ? 'warning' : 'question'),
            showCancelButton: true, confirmButtonText: opts.confirmText || (danger ? 'Yes, do it' : 'Yes, continue'), cancelButtonText: 'Cancel',
            confirmButtonColor: danger ? '#d63939' : brand, reverseButtons: true, focusCancel: danger
        }).then(function (r) { return !!r.isConfirmed; });
    };

    // Native alert() boxes (still used by a few inline handlers) become SweetAlert; they do not block.
    window.alert = function (msg) { Uv.alert('info', String(msg)); };

    /* ------------------------------------------------------------ connection */

    var Net = Uv.net = { state: 'unknown', rtt: null, unreachable: false, quality: null };
    var samples = [];
    var offlineModalOpen = false;
    var lastPoorToast = 0;
    var LABELS = { good: 'Good connection', fair: 'Fair connection', poor: 'Weak connection', offline: 'Offline', unknown: 'Checking…' };
    var SHORT = { good: 'Good', fair: 'Fair', poor: 'Weak', offline: 'Offline', unknown: '…' };

    function median(a) { var s = a.slice().sort(function (x, y) { return x - y; }); return s.length ? s[Math.floor(s.length / 2)] : null; }

    function classify() {
        if (navigator.onLine === false || Net.unreachable) { return 'offline'; }
        var q = 'good', m = median(samples), c = navigator.connection || navigator.mozConnection || navigator.webkitConnection || {};
        if (m !== null) { q = m < 600 ? 'good' : m < 1600 ? 'fair' : 'poor'; }
        if (c.effectiveType && /(^|-)2g$/.test(c.effectiveType)) { q = 'poor'; }
        else if (c.effectiveType === '3g' && q === 'good') { q = 'fair'; }
        if (m === null && !c.effectiveType) { return Net.state === 'unknown' ? 'unknown' : Net.state; }
        return q;
    }

    function ensureIndicator() {
        var el = document.getElementById('uv-net');
        if (!el) {
            el = document.createElement('div');
            el.id = 'uv-net';
            el.className = 'uv-net uv-net-float';
            el.innerHTML = '<i></i><i></i><i></i><i></i><span class="uv-net-label"></span>';
            document.body.appendChild(el);
        }
        return el;
    }

    function paint() {
        var el = ensureIndicator();
        el.className = el.className.replace(/\buv-net--\w+\b/g, '').trim() + ' uv-net--' + Net.state;
        var detail = Net.rtt !== null && Net.state !== 'offline' ? ' · ' + Net.rtt + ' ms' : '';
        var c = navigator.connection;
        var extra = c && c.effectiveType && Net.state !== 'offline' ? ' (' + c.effectiveType + (c.downlink ? ', ~' + c.downlink + ' Mbps' : '') + ')' : '';
        var label = el.querySelector('.uv-net-label');
        if (label) { label.textContent = SHORT[Net.state] + detail; }
        el.setAttribute('title', LABELS[Net.state] + detail + extra + (Net.state === 'offline' && navigator.onLine ? ' - the server cannot be reached' : ''));
    }

    function setState() {
        var next = classify(), prev = Net.state;
        Net.state = next;
        paint();
        if (next === prev) { return; }
        document.dispatchEvent(new CustomEvent('uv:network', { detail: { state: next, previous: prev, rtt: Net.rtt } }));

        if (next === 'offline') { showOffline(); }
        else if (prev === 'offline') {
            closeOffline();
            backOnline();
        } else if (next === 'poor' && prev !== 'poor' && Date.now() - lastPoorToast > 90000) {
            lastPoorToast = Date.now();
            Uv.toast('warning', 'Your connection is weak. Saving may take a little longer.');
        }
    }

    /*
     * An action (saving a form, opening a page, loading a list) that failed because the connection dropped waits here.
     * While offline the user sees which action is paused; when the connection is back they are told it can continue
     * and choose Continue (the action runs again with the same idempotency key, so nothing is done twice) or Not now.
     */
    var pending = null, modalSeq = 0, offlineModalFor = null;

    function esc(text) { var d = document.createElement('div'); d.textContent = String(text); return d.innerHTML; }

    function showOffline() {
        if (!hasSwal()) { return; }
        var what = pending ? pending.what : null;
        if (offlineModalOpen && offlineModalFor === what) { return; }
        var seq = ++modalSeq;
        offlineModalOpen = true; offlineModalFor = what;
        Swal.fire({
            icon: 'warning', title: navigator.onLine === false ? 'You are offline' : 'Cannot reach the server',
            html: '<span class="uv-spin uv-offline-spin"></span>' + (what
                ? 'The connection dropped while <b>' + esc(what) + '</b>, so it is paused. Whatever you typed is still on this page. We keep checking and will tell you as soon as you are back online so you can continue.'
                : 'Whatever you typed is still on this page. We keep trying and will tell you as soon as you are connected. Nothing is saved until then.'),
            allowOutsideClick: false, allowEscapeKey: !what, confirmButtonText: 'Check again', confirmButtonColor: brand,
            showCancelButton: !!what, cancelButtonText: 'Cancel it',
            showLoaderOnConfirm: true,
            preConfirm: function () { return probe().then(function () { if (Net.state === 'offline') { Swal.showValidationMessage('Still offline. We will keep checking.'); return false; } return true; }); }
        }).then(function (r) {
            if (seq !== modalSeq) { return; }   // replaced by another dialog
            offlineModalOpen = false; offlineModalFor = null;
            if (r.dismiss === Swal.DismissReason.cancel) { settle(false); }
        });
    }

    function closeOffline() {
        if (offlineModalOpen && hasSwal()) { modalSeq++; Swal.close(); }
        offlineModalOpen = false; offlineModalFor = null;
    }

    function settle(again) {
        var p = pending; pending = null;
        if (p) { p.resolve(again); }
    }

    function backOnline() {
        if (!pending) { Uv.toast('success', 'You are back online'); return; }
        if (!hasSwal()) { settle(true); return; }
        var p = pending, seq = ++modalSeq;
        p.told = true;
        document.dispatchEvent(new CustomEvent('uv:resume', { detail: { what: p.what } }));
        Swal.fire({
            icon: 'success', title: 'You are back online',
            html: 'The connection is back, so <b>' + esc(p.what) + '</b> can now continue.',
            showCancelButton: true, confirmButtonText: 'Continue', cancelButtonText: 'Not now', confirmButtonColor: brand,
            allowOutsideClick: false, reverseButtons: true
        }).then(function (r) {
            if (seq !== modalSeq || pending !== p) { return; }
            settle(!!r.isConfirmed);
        });
    }

    /**
     * Pause an action until the connection is back. Resolves true when the user chooses Continue, false when they
     * cancel it or start another action instead.
     */
    Uv.whenOnline = function (what) {
        return new Promise(function (resolve) {
            settle(false);
            var p = pending = { what: what, resolve: resolve, told: false };
            if (Net.state !== 'offline') { Net.unreachable = true; setState(); }
            showOffline();
            probe().then(function () { if (pending === p && !p.told && Net.state !== 'offline') { closeOffline(); backOnline(); } });
        });
    };

    var probing = null;
    function probe() {
        if (probing) { return probing; }
        if (navigator.onLine === false) { Net.unreachable = true; setState(); return Promise.resolve(); }
        var t0 = performance.now(), ctl = new AbortController(), timer = setTimeout(function () { ctl.abort(); }, 8000);
        probing = fetch(Uv.config.ping + (Uv.config.ping.indexOf('?') > -1 ? '&' : '?') + 't=' + Date.now(), { cache: 'no-store', signal: ctl.signal, credentials: 'same-origin' })
            .then(function (r) {
                if (r.status >= 500) { throw new Error('server'); }
                record(performance.now() - t0, true);
            })
            .catch(function (e) {
                if (ctl.signal.aborted) { record(8000, true); }      // so slow it timed out: a weak link, not a dead one
                else { Net.unreachable = true; setState(); }
            })
            .then(function () { clearTimeout(timer); probing = null; });
        return probing;
    }

    function record(ms, reachable) {
        samples.push(ms); if (samples.length > 5) { samples.shift(); }
        Net.rtt = Math.round(median(samples));
        if (reachable) { Net.unreachable = false; }
        setState();
    }
    Uv.net.probe = probe;

    function schedule() {
        var wait = Net.state === 'offline' ? 4000 : 20000;
        setTimeout(function () { if (!document.hidden || Net.state === 'offline') { probe(); } schedule(); }, wait);
    }

    window.addEventListener('online', function () { Net.unreachable = false; probe(); setState(); });
    window.addEventListener('offline', function () { setState(); });
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { probe(); } });
    if (navigator.connection && navigator.connection.addEventListener) { navigator.connection.addEventListener('change', setState); }

    /* --------------------------------------------------------------- requests */

    function uuid() {
        if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) { var r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16); });
    }

    function csrf() { return meta('csrf-token') || ''; }

    /**
     * Send a request the way the portal wants: returns {res, data}. Throws {timeout:true} or {network:true}.
     * The same idempotency key is reused on retries, so a save that actually went through is not done twice.
     */
    Uv.request = function (url, opts) {
        opts = opts || {};
        var method = (opts.method || 'GET').toUpperCase();
        var timeoutMs = opts.timeout || (Net.state === 'poor' ? 120000 : 60000);
        var ctl = new AbortController(), timedOut = false;
        var timer = setTimeout(function () { timedOut = true; ctl.abort(); }, timeoutMs);
        var headers = { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf(), 'Accept': opts.html ? 'text/html' : 'application/json' };
        if (method !== 'GET' && opts.key) { headers['X-Idempotency-Key'] = opts.key; }
        var t0 = performance.now();

        return fetch(url, { method: method, body: opts.body || null, headers: headers, credentials: 'same-origin', signal: ctl.signal, redirect: 'follow' })
            .then(function (res) {
                record(performance.now() - t0, true);
                if (opts.html) { return { res: res, data: null }; }
                return res.text().then(function (text) {
                    var data = null;
                    try { data = text ? JSON.parse(text) : null; } catch (e) { data = { message: text.slice(0, 200) }; }
                    return { res: res, data: data };
                });
            })
            .catch(function (err) {
                if (timedOut) { throw { timeout: true }; }
                if (err && err.name === 'AbortError') { throw { aborted: true }; }
                Net.unreachable = true; setState(); probe();
                throw { network: true };
            })
            .finally(function () { clearTimeout(timer); });
    };

    /** Shows the right message for a failed request. Resolves true when the user chooses to try again. */
    function networkProblem(err, what) {
        if (err && err.aborted) { return Promise.resolve(false); }
        if (!hasSwal()) { return Promise.resolve(false); }
        var weak = err && err.timeout;
        if (!weak) { return Uv.whenOnline(what); }
        return Swal.fire({
            icon: 'warning',
            title: weak ? 'This is taking too long' : (navigator.onLine === false ? 'You are offline' : 'Could not reach the server'),
            html: (weak ? 'Your connection looks <b>weak</b>' : 'Your connection dropped') + ' so ' + what + ' may not have gone through. Nothing you typed is lost. It is safe to try again: it will not be done twice.',
            showCancelButton: true, confirmButtonText: 'Try again', cancelButtonText: 'Not now', confirmButtonColor: brand, allowOutsideClick: false
        }).then(function (r) { return !!r.isConfirmed; });
    }

    /* ------------------------------------------------------------- busy state */

    function setBusy(form, submitter, busy) {
        var buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
        form.classList.toggle('uv-busy', busy);
        buttons.forEach(function (b) {
            if (busy) {
                b.dataset.uvWasDisabled = b.disabled ? '1' : '';
                b.disabled = true;
                if (b === submitter && b.tagName === 'BUTTON') { b.dataset.uvHtml = b.innerHTML; b.innerHTML = '<span class="uv-spin"></span>' + (b.dataset.busyText || 'Working…'); }
            } else {
                b.disabled = b.dataset.uvWasDisabled === '1';
                if (b.dataset.uvHtml !== undefined) { b.innerHTML = b.dataset.uvHtml; delete b.dataset.uvHtml; }
            }
        });
    }

    var bar = null, barTimer = null;
    function progress(on) {
        if (!bar) { bar = document.createElement('div'); bar.id = 'uv-progress'; document.body.appendChild(bar); }
        clearTimeout(barTimer);
        if (on) { bar.classList.add('on'); bar.style.width = '12%'; var w = 12; barTimer = setInterval(function () { w = Math.min(90, w + (90 - w) * .15); bar.style.width = w + '%'; }, 250); }
        else { clearInterval(barTimer); bar.style.width = '100%'; setTimeout(function () { bar.classList.remove('on'); bar.style.width = '0'; }, 250); }
    }

    /* ------------------------------------------------------------ validation */

    function clearErrors(form) {
        form.querySelectorAll('.uv-err').forEach(function (n) { n.remove(); });
        form.querySelectorAll('.is-invalid').forEach(function (n) { n.classList.remove('is-invalid'); });
        form.querySelectorAll('.uv-form-errors').forEach(function (n) { n.remove(); });
    }

    function findInputs(form, key) {
        var parts = key.split('.');
        var bracket = parts[0] + parts.slice(1).map(function (p) { return '[' + p + ']'; }).join('');
        var cands = [key, bracket, parts[0] + '[]', parts[0]];
        for (var i = 0; i < cands.length; i++) {
            var found = form.querySelectorAll('[name="' + cands[i].replace(/"/g, '\\"') + '"]:not([type="hidden"])');
            if (found.length) { return found; }
        }
        return [];
    }

    function showErrors(form, errors) {
        var leftovers = [], first = null;
        Object.keys(errors || {}).forEach(function (key) {
            var inputs = findInputs(form, key), msg = [].concat(errors[key]).join(' ');
            if (!inputs.length) { leftovers.push(msg); return; }
            var input = inputs[0];
            input.classList.add('is-invalid');
            var holder = input.closest('.input-group') || input;
            var div = document.createElement('div');
            div.className = 'invalid-feedback d-block uv-err';
            div.textContent = msg;
            holder.insertAdjacentElement('afterend', div);
            input.addEventListener('input', function clear() { input.classList.remove('is-invalid'); div.remove(); input.removeEventListener('input', clear); });
            if (!first) { first = input; }
        });
        if (leftovers.length) {
            var box = document.createElement('div');
            box.className = 'alert alert-danger uv-form-errors';
            box.innerHTML = '<ul class="mb-0 ps-3"></ul>';
            leftovers.forEach(function (m) { var li = document.createElement('li'); li.textContent = m; box.firstChild.appendChild(li); });
            var body = form.querySelector('.modal-body') || form;
            body.insertBefore(box, body.firstChild);
        }
        var target = first || form.querySelector('.uv-form-errors');
        if (target && target.scrollIntoView) { target.scrollIntoView({ block: 'center', behavior: 'smooth' }); if (first && first.focus) { first.focus({ preventScroll: true }); } }
    }

    /* ------------------------------------------------------------ form submit */

    function ajaxOn() { return meta('uv-ajax') !== 'off'; }

    // A field called "target" or "action" shadows form.target / form.action, so read the attributes.
    function formAction(form) { return form.getAttribute('action') || location.href; }

    function formEligible(form) {
        if (!ajaxOn()) { return false; }
        if (form.hasAttribute('data-no-ajax') || form.closest('[data-no-ajax]')) { return false; }
        var tgt = form.getAttribute('target'); if (tgt && tgt !== '_self') { return false; }
        try { if (new URL(formAction(form), location.href).origin !== location.origin) { return false; } } catch (e) { return false; }
        return !!form.closest('#ajax-root');
    }

    function samePage(url) {
        var a = new URL(url, location.href);
        return a.origin === location.origin && a.pathname === location.pathname && a.search === location.search;
    }

    function credentialsBox(c) {
        var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
        return '<p class="text-muted mb-2">Shown only once. Copy it now.</p><div class="p-3 bg-light rounded text-start"><div class="small text-muted">' + esc(c.email || '') + '</div><code class="fs-5 user-select-all" id="uv-pw">' + esc(c.password || '') + '</code></div>';
    }

    function afterSuccess(form, data) {
        var modal = form.closest('.modal');
        if (modal && window.bootstrap) { var inst = bootstrap.Modal.getInstance(modal); if (inst) { inst.hide(); } }

        var go = function () {
            var target = data && data.redirect;
            if (target && !samePage(target)) { return Uv.visit(target); }
            return Uv.visit(location.href, { push: false, keepScroll: true });
        };

        if (data && data.flash && data.flash.generated_password && hasSwal()) {
            if (data.message) { Uv.toast('success', data.message); }
            return Swal.fire({
                icon: 'success', title: 'Temporary password', html: credentialsBox(data.flash.generated_password),
                confirmButtonText: 'Copy and close', confirmButtonColor: brand, allowOutsideClick: false,
                preConfirm: function () { try { navigator.clipboard.writeText(data.flash.generated_password.password); } catch (e) { /* the box is selectable */ } }
            }).then(go);
        }
        if (data && data.message) { Uv.toast('success', data.message); }
        return go();
    }

    function handleResponse(form, out) {
        var res = out.res, data = out.data || {};
        if (res.status === 422) {
            showErrors(form, data.errors || {});
            Uv.toast('error', data.errors ? 'Please check the highlighted fields.' : (data.message || 'Please check the form.'));
            return;
        }
        if (res.status === 419) {
            Uv.alert('warning', 'Your session expired', 'For your safety the page needs to reload. Anything you typed will have to be entered again.').then(function () { location.reload(); });
            return;
        }
        if (res.status === 401) { location.href = data.redirect || '/login'; return; }
        if (res.status === 403) { Uv.alert('error', 'Not allowed', data.message && data.message !== 'This action is unauthorized.' ? data.message : 'You do not have permission to do that.'); return; }
        if (res.status === 409) { Uv.alert('info', 'Please wait', data.message || 'That is still being processed.'); return; }
        if (res.status === 429) { Uv.alert('warning', 'Too many attempts', 'Please wait a moment and try again.'); return; }
        if (res.status >= 400) { Uv.alert('error', 'Something went wrong', data.message || 'The server could not complete that. Nothing was changed. Please try again.'); return; }

        if (data.ok === false) { Uv.alert('error', 'That did not work', data.message || 'The action could not be completed.'); return; }
        return afterSuccess(form, data);
    }

    function buildFormData(form, submitter) {
        try { return new FormData(form, submitter || undefined); }
        catch (e) {
            var fd = new FormData(form);
            if (submitter && submitter.name) { fd.append(submitter.name, submitter.value); }
            return fd;
        }
    }

    function submitForm(form, submitter, key) {
        var method = (form.getAttribute('method') || 'GET').toUpperCase();
        if (method === 'GET') {
            var params = new URLSearchParams(new FormData(form)).toString();
            var base = new URL(formAction(form), location.href);
            return Uv.visit(base.pathname + (params ? '?' + params : ''));
        }
        if (Net.state === 'offline') {
            return networkProblem({ network: true }, 'saving this form').then(function (again) { if (again) { return submitForm(form, submitter, key); } });
        }

        key = key || uuid();
        clearErrors(form);
        var fd = buildFormData(form, submitter);
        setBusy(form, submitter, true);

        return Uv.request(formAction(form), { method: 'POST', body: fd, key: key })
            .then(function (out) { setBusy(form, submitter, false); return handleResponse(form, out); })
            .catch(function (err) {
                setBusy(form, submitter, false);
                if (err && (err.timeout || err.network || err.aborted)) {
                    return networkProblem(err, 'saving this form').then(function (again) { if (again) { return submitForm(form, submitter, key); } });
                }
                window.console && console.error(err);
                Uv.alert('error', 'Something went wrong', 'The page hit an unexpected problem. Reload it and try again.');
            });
    }

    // A form that does a normal full-page submit (downloads, payment redirects, sign out) would land on the browser's
    // own error page while offline: hold it and send it once the connection is back and the user says Continue.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement) || e.defaultPrevented || formEligible(form)) { return; }
        if (Net.state !== 'offline' && navigator.onLine !== false) { return; }
        e.preventDefault();
        var submitter = e.submitter || null;
        Uv.whenOnline('sending this form').then(function (again) {
            if (!again) { return; }
            if (form.requestSubmit) { form.requestSubmit(submitter || undefined); } else { form.submit(); }
        });
    });

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement) || e.defaultPrevented || !formEligible(form)) { return; }
        e.preventDefault();
        var submitter = e.submitter || null;
        var msg = (submitter && submitter.getAttribute('data-confirm')) || form.getAttribute('data-confirm');
        if (msg) {
            Uv.confirm(msg, { title: (submitter && submitter.getAttribute('data-confirm-title')) || form.getAttribute('data-confirm-title') || undefined })
                .then(function (ok) { if (ok) { submitForm(form, submitter); } });
        } else {
            submitForm(form, submitter);
        }
    });

    /* ------------------------------------------------------------- navigation */

    var navToken = 0;

    function closeModals() {
        document.querySelectorAll('.modal.show').forEach(function (m) { if (window.bootstrap) { var i = bootstrap.Modal.getInstance(m); if (i) { i.hide(); } } });
        document.querySelectorAll('.modal-backdrop').forEach(function (b) { b.remove(); });
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    }

    function runScripts(container) {
        container.querySelectorAll('script').forEach(function (old) {
            if (old.src) {
                if (!document.querySelector('script[src="' + old.getAttribute('src') + '"]')) {
                    var s = document.createElement('script'); s.src = old.src; document.body.appendChild(s);
                }
                return;
            }
            try { (new Function(old.textContent))(); } catch (e) { window.console && console.error('Page script failed', e); }
        });
    }

    function swap(doc, root, finalUrl, opts) {
        closeModals();
        var cur = document.getElementById('ajax-root'), y = window.scrollY;
        cur.innerHTML = root.innerHTML;
        document.title = doc.title;
        var token = doc.querySelector('meta[name="csrf-token"]');
        if (token) { var mine = document.querySelector('meta[name="csrf-token"]'); if (mine) { mine.setAttribute('content', token.getAttribute('content')); } }

        var holder = document.getElementById('uv-page-scripts'), fresh = doc.getElementById('uv-page-scripts');
        if (holder) { holder.innerHTML = ''; }
        runScripts(cur);
        if (fresh) { runScripts(fresh); }

        if (opts.push !== false && finalUrl !== location.href) { history.pushState({ uv: 1 }, '', finalUrl); }
        else if (opts.replace) { history.replaceState({ uv: 1 }, '', finalUrl); }
        window.scrollTo(0, opts.keepScroll ? y : 0);
        upgrade(cur);
        document.dispatchEvent(new CustomEvent('uv:page', { detail: { url: finalUrl } }));
    }

    /** Load a page and swap it in without a full reload. */
    Uv.visit = function (url, opts) {
        opts = opts || {};
        var token = ++navToken;
        progress(true);
        return Uv.request(url, { html: true })
            .then(function (out) {
                if (token !== navToken) { return; }
                var res = out.res, finalUrl = res.url || url, ct = res.headers.get('content-type') || '';
                if (res.redirected && /\/(login|logout)(\?|$|\/)/.test(new URL(finalUrl).pathname + '/')) { location.href = finalUrl; return; }
                if (res.status === 403) { return Uv.alert('error', 'Not allowed', 'You do not have permission to open that page.'); }
                if (res.status === 404) { return Uv.alert('error', 'Not found', 'That page does not exist or was removed.'); }
                if (res.status === 419 || res.status === 401) { location.href = '/login'; return; }
                if (!res.ok || ct.indexOf('text/html') === -1) { location.href = url; return; }
                return res.text().then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html'), root = doc.getElementById('ajax-root');
                    var layout = doc.querySelector('meta[name="uv-layout"]'), off = doc.querySelector('meta[name="uv-ajax"]');
                    if (!root || !layout || layout.getAttribute('content') !== meta('uv-layout') || (off && off.getAttribute('content') === 'off')) { location.href = finalUrl; return; }
                    swap(doc, root, finalUrl, opts);
                });
            })
            .catch(function (err) {
                if (err && err.aborted) { return; }
                if (err && (err.timeout || err.network)) {
                    return networkProblem(err, 'opening that page').then(function (again) { if (again) { return Uv.visit(url, opts); } });
                }
                window.console && console.error(err);
                location.href = url;
            })
            .finally(function () { if (token === navToken) { progress(false); } });
    };

    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || !ajaxOn()) { return; }
        var a = e.target.closest && e.target.closest('a[href]');
        if (!a || !a.closest('#ajax-root') || a.closest('[data-no-ajax]')) { return; }
        var href = a.getAttribute('href');
        if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) { return; }
        if ((a.target && a.target !== '_self') || a.hasAttribute('download') || a.hasAttribute('data-no-ajax') || a.hasAttribute('data-bs-toggle')) { return; }
        var url = new URL(a.href, location.href);
        if (url.origin !== location.origin) { return; }
        if (url.pathname === location.pathname && url.search === location.search && url.hash) { return; }
        e.preventDefault();
        Uv.visit(url.href);
    });

    window.addEventListener('popstate', function () { if (ajaxOn()) { Uv.visit(location.href, { push: false, keepScroll: false }); } });

    /* ------------------------------------------------- tidy up old inline code */

    // `onsubmit="return confirm('...')"` and `this.form.submit()` come from older pages.
    // Turn them into SweetAlert confirmations and submit events the async layer can see.
    var CONFIRM_RE = /^\s*return\s+confirm\(\s*(['"])((?:\\.|(?!\1).)*)\1\s*\)\s*;?\s*$/;
    function upgrade(root) {
        (root || document).querySelectorAll('[onsubmit*="confirm("], [onclick*="confirm("]').forEach(function (el) {
            ['onsubmit', 'onclick'].forEach(function (attr) {
                var m = (el.getAttribute(attr) || '').match(CONFIRM_RE);
                if (m) { el.setAttribute('data-confirm', m[2].replace(/\\(['"\\])/g, '$1')); el.removeAttribute(attr); }
            });
        });
        (root || document).querySelectorAll('[onchange*="this.form.submit()"]').forEach(function (el) {
            el.removeAttribute('onchange');
            el.addEventListener('change', function () { if (el.form.requestSubmit) { el.form.requestSubmit(); } else { el.form.submit(); } });
        });
    }

    /* ------------------------------------------------------- table animation */

    /** Rows of every table in the page body slide in. Printed documents and tables marked .uv-no-anim are left still. */
    function animateTables(root) {
        (root || document).querySelectorAll('.page-content table:not(.uv-no-anim) > tbody').forEach(function (body) {
            if (body.closest('.uv-print, .uv-document, .modal') || body.closest('table').classList.contains('uv-scores')) { return; }
            var i = 0;
            Array.prototype.forEach.call(body.rows, function (tr) {
                if (tr.dataset.uvAnim) { return; }
                tr.dataset.uvAnim = '1';
                tr.style.setProperty('--uv-i', i++);
                tr.classList.add('uv-anim-row');
            });
        });
    }
    Uv.animateTables = animateTables;
    document.addEventListener('uv:page', function () { animateTables(document); });

    /* ------------------------------------------------------- last changed by */

    /** The ⓘ in a "Last changed by" cell opens the staff member's details and the record's history. */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.uv-who');
        if (!btn) { return; }
        e.preventDefault();
        var modal = document.getElementById('uv-who-modal');
        if (!modal) {
            modal = document.createElement('div');
            modal.className = 'modal fade'; modal.id = 'uv-who-modal'; modal.tabIndex = -1;
            modal.innerHTML = '<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">'
                + '<div class="modal-header"><h5 class="modal-title">Who changed this</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
                + '<div class="modal-body"></div></div></div>';
            document.body.appendChild(modal);
        }
        var body = modal.querySelector('.modal-body');
        body.innerHTML = '<div class="text-center text-muted py-4"><span class="uv-spin"></span>Loading…</div>';
        if (window.bootstrap) { bootstrap.Modal.getOrCreateInstance(modal).show(); }
        fetch(btn.dataset.url, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' }, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.text(); })
            .then(function (html) { body.innerHTML = html; })
            .catch(function () { body.innerHTML = '<p class="text-danger mb-0">Could not load the details. Please try again.</p>'; });
    });

    /* ------------------------------------------------------------------ start */

    function start() {
        history.replaceState({ uv: 1 }, '', location.href);
        upgrade(document);
        animateTables(document);
        ensureIndicator();
        paint();
        probe();
        schedule();
        document.dispatchEvent(new CustomEvent('uv:ready'));
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
