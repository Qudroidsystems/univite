/*
 * Live notices: keeps the bell current without reloading the page.
 *
 *  - Asks the portal for new notices every 30 seconds while the page is in view, and at once when it comes back into
 *    view (another tab, a sleeping laptop, a phone unlocked).
 *  - A new notice updates the count, joins the top of the bell's list and shows as a toast.
 *  - When a notice points at the page being looked at (a timetable just published, results out, a fee paid), a bar
 *    offers to reload it, so nobody works from an old timetable.
 *  - Other scripts can listen for  document.addEventListener('uv:notice', e => e.detail)  to refresh themselves.
 */
(function () {
    'use strict';

    var list = document.getElementById('pn-list');
    if (!list || !window.fetch) { return; }
    var url = list.getAttribute('data-live');
    var since = list.getAttribute('data-since');
    var badge = document.getElementById('pn-count');
    var every = 30000, timer = null, busy = false;

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    function setCount(n) {
        if (!badge) { return; }
        badge.textContent = n > 99 ? '99+' : String(n);
        badge.style.display = n > 0 ? '' : 'none';
    }

    function add(item) {
        var empty = list.querySelector('[data-empty]');
        if (empty) { empty.remove(); }
        var a = document.createElement('a');
        a.href = item.open;
        a.className = 'd-flex gap-2 px-3 py-2 text-decoration-none border-bottom uv-pn-item uv-pn-new';
        a.innerHTML = '<i class="ri-notification-badge-line fs-18 text-primary"></i><span class="flex-grow-1"><span class="d-block fw-semibold fs-13">' + esc(item.title)
            + '</span><span class="d-block text-muted fs-12">' + esc(item.body) + '</span><span class="text-muted fs-11">' + esc(item.ago) + '</span></span>';
        list.insertBefore(a, list.firstChild);
    }

    function samePage(link) {
        if (!link) { return false; }
        try {
            var u = new URL(link, location.href);
            return u.origin === location.origin && u.pathname.replace(/\/$/, '') === location.pathname.replace(/\/$/, '');
        } catch (e) { return false; }
    }

    function offerReload(item) {
        if (document.getElementById('uv-live-bar')) { return; }
        var bar = document.createElement('div');
        bar.id = 'uv-live-bar';
        bar.setAttribute('role', 'status');
        bar.style.cssText = 'position:fixed;left:50%;bottom:20px;transform:translateX(-50%);z-index:1090;max-width:92vw;';
        bar.className = 'alert alert-primary shadow d-flex align-items-center gap-3 mb-0';
        bar.innerHTML = '<i class="ri-refresh-line fs-18"></i><span class="fs-13"><strong>' + esc(item.title) + '</strong><br>This page has changed since you opened it.</span>'
            + '<button type="button" class="btn btn-sm btn-primary" data-reload>Reload</button><button type="button" class="btn-close" aria-label="Dismiss"></button>';
        bar.querySelector('[data-reload]').addEventListener('click', function () { location.reload(); });
        bar.querySelector('.btn-close').addEventListener('click', function () { bar.remove(); });
        document.body.appendChild(bar);
    }

    function check() {
        if (busy || document.hidden) { return; }
        busy = true;
        fetch(url + '?after=' + encodeURIComponent(since), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) { return; }
                since = data.now || since;
                setCount(data.unread || 0);
                var items = (data.items || []).slice().reverse();   // oldest first, so the newest ends on top
                items.forEach(function (item, i) {
                    add(item);
                    if (i >= items.length - 2 && window.Uv && Uv.toast) { Uv.toast(item.level === 'warning' || item.level === 'danger' ? 'warning' : 'info', item.title); }
                    if (samePage(item.url)) { offerReload(item); }
                    document.dispatchEvent(new CustomEvent('uv:notice', { detail: item }));
                });
            })
            .catch(function () { /* offline: the next round tries again */ })
            .then(function () { busy = false; });
    }

    function start() { clearInterval(timer); timer = setInterval(check, every); }
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { check(); start(); } });
    window.addEventListener('focus', check);
    start();
})();
