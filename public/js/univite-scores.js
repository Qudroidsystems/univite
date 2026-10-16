/*
 * Score entry tables (results/partials/score-table): marks add up as they are typed.
 *  - a part's assessments (data-item="ca" | "exam") fill its total (data-total); a running sum shows in grey until
 *    every assessment of the part has a mark
 *  - typing a total directly clears that part's assessments, so the total is saved as it is
 *  - typing the overall total (data-grand) clears every assessment and both part totals; the total is saved as typed
 *  - the total and grade (from the university's grade bands on data-bands) follow, with a short highlight
 *  - the header shows how many students are complete, passed and failed, and the average
 * Works on pages swapped in without a reload (uv:page) as well as on a full load.
 */
(function () {
    'use strict';
    if (window.UvScores) { return; }
    window.UvScores = true;

    function num(v) { if (v === '' || v === null || v === undefined) { return null; } var n = parseFloat(v); return isNaN(n) ? null : n; }
    function fmt(n) { return n === null ? '' : String(Math.round(n * 100) / 100); }
    function flash(el) { if (!el) { return; } el.classList.remove('uv-flash'); void el.offsetWidth; el.classList.add('uv-flash'); }

    function bandsOf(table) {
        if (!table._bands) { try { table._bands = JSON.parse(table.getAttribute('data-bands') || '[]'); } catch (e) { table._bands = []; } }
        return table._bands;
    }
    function grade(table, total) {
        if (total === null) { return null; }
        var t = Math.round(total * 100) / 100, bands = bandsOf(table);
        for (var i = 0; i < bands.length; i++) { if (t >= bands[i].min && t <= bands[i].max) { return bands[i]; } }
        return null;
    }

    function checkRange(input) {
        var v = num(input.value), max = num(input.getAttribute('max'));
        input.classList.toggle('is-over', v !== null && (v < 0 || (max !== null && v > max)));
    }

    /** Recompute one row. source: the input just typed in, if any. */
    function row(table, tr, source) {
        var parts = {}, complete = true, any = false;
        var grand = tr.querySelector('[data-grand]');
        if (grand && source === grand) {
            // The overall total typed directly: the assessments and the CA and exam totals no longer apply.
            tr.querySelectorAll('[data-item], [data-total]').forEach(function (i) { if (i.value !== '') { i.value = ''; i.classList.remove('is-partial'); flash(i.parentNode); } });
            checkRange(grand);
            show(table, tr, num(grand.value), source);
            return;
        }
        ['ca', 'exam'].forEach(function (part) {
            var items = tr.querySelectorAll('[data-item="' + part + '"]');
            var totalIn = tr.querySelector('[data-total="' + part + '"]');
            var totalText = tr.querySelector('[data-total-text="' + part + '"]');
            var value = null;
            if (source && source === totalIn && items.length) {
                // The lecturer typed the total itself: the assessments of this part no longer apply.
                items.forEach(function (i) { if (i.value !== '') { i.value = ''; flash(i.parentNode); } });
            }
            if (items.length) {
                var sum = 0, filled = 0;
                items.forEach(function (i) { var v = num(i.value); if (v !== null) { sum += v; filled++; } });
                if (filled > 0) {
                    any = true;
                    value = filled === items.length ? sum : null;
                    if (totalIn) {
                        totalIn.value = fmt(sum);
                        totalIn.classList.toggle('is-partial', filled < items.length);
                        totalIn.title = filled < items.length ? filled + ' of ' + items.length + ' assessments entered' : 'Added up from the assessments';
                        if (source && source.getAttribute('data-item') === part) { flash(totalIn.parentNode); }
                    }
                } else if (totalIn) {
                    totalIn.classList.remove('is-partial');
                    value = num(totalIn.value);
                    if (source && source.getAttribute('data-item') === part && totalIn.value !== '' && source.value === '') { totalIn.value = ''; value = null; }
                }
            } else if (totalIn) {
                value = num(totalIn.value);
            } else if (totalText) {
                value = num(totalText.textContent);
            }
            if (totalIn) { checkRange(totalIn); }
            if (totalIn && totalIn.value !== '') { any = true; }
            parts[part] = value;
            if (value === null) { complete = false; }
        });

        var total = complete ? parts.ca + parts.exam : null;
        if (grand && !source && !any && total === null) {
            // On load, a row with nothing but an overall total (saved that way): keep it.
            total = num(grand.value);
        }
        show(table, tr, total, source);
    }

    function show(table, tr, total, source) {
        var g = grade(table, total);
        var totalOut = tr.querySelector('[data-out="total"]'), gradeOut = tr.querySelector('[data-out="grade"]');
        var isInput = totalOut.tagName === 'INPUT';
        var before = isInput ? totalOut.value : totalOut.textContent;
        if (!(isInput && source === totalOut)) {
            if (isInput) { totalOut.value = fmt(total); } else { totalOut.textContent = fmt(total); }
            if (source && before !== fmt(total)) { flash(totalOut.parentNode); }
        }
        var letter = g ? g.grade : '';
        if (gradeOut.textContent !== letter) {
            gradeOut.textContent = letter;
            gradeOut.className = 'uv-grade badge ' + (g ? (g.pass ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger') : '');
            if (source && g) { gradeOut.classList.add('uv-pop'); setTimeout(function () { gradeOut.classList.remove('uv-pop'); }, 220); }
        }
        tr._state = { total: total, pass: g ? !!g.pass : null };
    }

    function summary(table) {
        var rows = table.querySelectorAll('[data-row]'), done = 0, pass = 0, fail = 0, sum = 0;
        rows.forEach(function (tr) {
            var s = tr._state || {};
            if (s.total !== null && s.total !== undefined) { done++; sum += s.total; if (s.pass) { pass++; } else { fail++; } }
        });
        var bar = table.querySelector('[data-progress]'), label = table.querySelector('[data-progress-label]');
        if (bar) { bar.style.width = (rows.length ? Math.round(done * 100 / rows.length) : 0) + '%'; }
        if (label) { label.textContent = done + ' of ' + rows.length + ' students complete'; }
        var set = function (k, v) { var el = table.querySelector('[data-count="' + k + '"]'); if (el) { el.textContent = v; } };
        set('pass', pass); set('fail', fail); set('avg', done ? fmt(sum / done) : '–');
    }

    function init(root) {
        (root || document).querySelectorAll('[data-score-table]').forEach(function (table) {
            table.querySelectorAll('[data-row]').forEach(function (tr) { row(table, tr, null); });
            summary(table);
        });
    }

    document.addEventListener('input', function (e) {
        var input = e.target;
        if (!input.matches || !input.matches('[data-item], [data-total], [data-grand]')) { return; }
        var table = input.closest('[data-score-table]'), tr = input.closest('[data-row]');
        if (!table || !tr) { return; }
        checkRange(input);
        row(table, tr, input);
        summary(table);
    });

    // Enter moves down the column, like a spreadsheet, instead of submitting the form.
    document.addEventListener('keydown', function (e) {
        var input = e.target;
        if (e.key !== 'Enter' || !input.matches || !input.matches('[data-score-table] input')) { return; }
        e.preventDefault();
        var td = input.closest('td'), tr = input.closest('tr'), col = Array.prototype.indexOf.call(tr.children, td);
        var next = e.shiftKey ? tr.previousElementSibling : tr.nextElementSibling;
        var target = next && next.children[col] && next.children[col].querySelector('input');
        if (target) { target.focus(); target.select(); }
    });

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { init(); }); } else { init(); }
    document.addEventListener('uv:page', function () { init(); });
    window.UvScoresInit = init;
})();
