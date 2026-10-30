@extends('layouts.master')

@section('content')
@php
    $rows = old('items', $scheme->items->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'code' => $i->code, 'component' => $i->component, 'max_score' => $i->maxValue()])->all());
    $action = $scheme->exists ? route('academics.assessments.update', $scheme->id) : route('academics.assessments.store');
@endphp
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0"><i class="ri-survey-line me-1 align-middle"></i>{{ $scheme->exists ? $scheme->name : 'New assessment scheme' }}</h4>
                <a href="{{ route('academics.assessments.index') }}" class="btn btn-light">Back</a>
            </div>
            @include('academics.partials.alerts')
            @if ($locked)
                <div class="alert alert-info"><i class="ri-information-line me-1"></i>Marks have already been entered with this scheme. You can still change it: the marks stay with their assessments. An assessment with marks cannot be removed, and its "out of" cannot go below the highest mark given. Assessments cannot be added, removed or moved between CA and exam while a score sheet on this scheme is submitted, approved or published.</div>
            @endif

            <form method="POST" action="{{ $action }}" id="scheme-form" data-no-ajax>
                @csrf
                @if ($scheme->exists) @method('PUT') @endif
                <div class="card"><div class="card-body row g-3">
                    <div class="col-md-6"><label class="form-label">Name</label><input name="name" class="form-control" required maxlength="120" value="{{ old('name', $scheme->name) }}" placeholder="Science courses (with practicals)"></div>
                    <div class="col-md-6"><label class="form-label">Description</label><input name="description" class="form-control" maxlength="500" value="{{ old('description', $scheme->description) }}" placeholder="Optional"></div>
                    <div class="col-md-3"><label class="form-label">CA total</label><input name="ca_total" type="number" step="0.01" min="0" max="100" class="form-control" value="{{ old('ca_total', $scheme->ca_total !== null ? rtrim(rtrim(number_format($scheme->ca_total, 2, '.', ''), '0'), '.') : '') }}" placeholder="e.g. 40" data-target="ca"></div>
                    <div class="col-md-3"><label class="form-label">Exam total</label><input name="exam_total" type="number" step="0.01" min="0" max="100" class="form-control" value="{{ old('exam_total', $scheme->exam_total !== null ? rtrim(rtrim(number_format($scheme->exam_total, 2, '.', ''), '0'), '.') : '') }}" placeholder="e.g. 60" data-target="exam"></div>
                    <div class="col-md-6 d-flex align-items-end"><p class="text-muted fs-13 mb-2">The CA total and exam total add up to 100. Give each assessment its own mark (CA1 out of 10, …) so they add up to the part's total, or leave “Out of” blank: lecturers may then enter any marks, as long as the CA marks stay within the CA total and the exam marks within the exam total. Leave both totals blank to take them from the assessments.</p></div>
                    <div class="col-12"><div class="form-check form-switch"><input type="hidden" name="is_active" value="0"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" @checked(old('is_active', $scheme->is_active))><label class="form-check-label" for="is_active">On (can be assigned and given to new score sheets)</label></div></div>
                </div></div>

                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="card-title mb-0">Assessments</h6>
                        <span class="fs-13">CA <strong data-sum="ca">0</strong><span data-want="ca" class="text-muted"></span> + Exam <strong data-sum="exam">0</strong><span data-want="exam" class="text-muted"></span> = <strong data-sum="all">0</strong> <span data-sum-note class="ms-1"></span></span>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light"><tr><th>Assessment</th><th style="width:140px">Short code</th><th style="width:200px">Counts towards</th><th style="width:130px">Out of</th><th style="width:60px"></th></tr></thead>
                            <tbody data-items></tbody>
                        </table>
                    </div>
                    <div class="card-footer d-flex justify-content-between">
                        <button type="button" class="btn btn-soft-primary" data-add><i class="ri-add-line me-1"></i>Add assessment</button>
                        <button class="btn btn-primary" type="submit"><i class="ri-save-line me-1"></i>Save scheme</button>
                    </div>
                </div>
                <p class="text-muted fs-13">The short code is the column heading in score uploads (for example T1, ASG, PRAC, EXAM). Leave it blank to make one from the name.</p>
            </form>

            <template id="item-row">
                <tr>
                    <td><input class="form-control" data-f="name" maxlength="80" placeholder="Test 1"></td>
                    <td><input class="form-control text-uppercase" data-f="code" maxlength="20" placeholder="T1"></td>
                    <td><select class="form-select" data-f="component"><option value="ca">Continuous assessment</option><option value="exam">Examination</option></select></td>
                    <td><input class="form-control" type="number" step="0.01" min="0.01" max="100" data-f="max_score" placeholder="any"></td>
                    <td><input type="hidden" data-f="id"><button type="button" class="btn btn-sm btn-soft-danger" data-remove title="Remove"><i class="ri-delete-bin-line"></i></button></td>
                </tr>
            </template>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var rows = @json(array_values($rows));
    var body = document.querySelector('[data-items]');
    var tpl = document.getElementById('item-row');

    function renumber() {
        body.querySelectorAll('tr').forEach(function (tr, n) {
            tr.querySelectorAll('[data-f]').forEach(function (el) { el.name = 'items[' + n + '][' + el.getAttribute('data-f') + ']'; });
        });
        sum();
    }
    function add(r) {
        var tr = tpl.content.firstElementChild.cloneNode(true);
        r = r || {component: 'ca'};
        tr.querySelectorAll('[data-f]').forEach(function (el) {
            var f = el.getAttribute('data-f');
            if (r[f] !== undefined && r[f] !== null) el.value = r[f];
        });
        body.appendChild(tr);
        renumber();
    }
    function sum() {
        var t = {ca: 0, exam: 0}, open = {ca: 0, exam: 0};
        body.querySelectorAll('tr').forEach(function (tr) {
            var c = tr.querySelector('[data-f=component]').value, raw = tr.querySelector('[data-f=max_score]').value;
            if (raw === '') { open[c]++; } else { t[c] += parseFloat(raw) || 0; }
        });
        var anyOpen = open.ca + open.exam > 0;
        var all = Math.round((t.ca + t.exam) * 100) / 100;
        document.querySelector('[data-sum=ca]').textContent = Math.round(t.ca * 100) / 100;
        document.querySelector('[data-sum=exam]').textContent = Math.round(t.exam * 100) / 100;
        document.querySelector('[data-sum=all]').textContent = all;
        var note = document.querySelector('[data-sum-note]'), ok = all === 100, msg = 'Adds up to 100';
        var want = {};
        ['ca', 'exam'].forEach(function (c) {
            var v = document.querySelector('[data-target=' + c + ']').value;
            want[c] = v === '' ? null : parseFloat(v);
        });
        if (want.ca !== null || want.exam !== null) {
            if (want.ca === null) { want.ca = 100 - want.exam; }
            if (want.exam === null) { want.exam = 100 - want.ca; }
        }
        ['ca', 'exam'].forEach(function (c) {
            var el = document.querySelector('[data-want=' + c + ']'), have = Math.round(t[c] * 100) / 100;
            var good = want[c] === null || (open[c] ? have <= want[c] : have === want[c]);
            el.textContent = want[c] === null ? '' : ' of ' + want[c] + (open[c] ? ' (' + open[c] + ' open)' : '');
            el.className = want[c] === null ? 'text-muted' : (good ? 'text-success' : 'text-danger');
            if (!good) { ok = false; msg = (c === 'ca' ? 'CA' : 'Exam') + (open[c] ? ' marks go over ' : ' assessments must add up to ') + want[c]; }
        });
        if (anyOpen) {
            if (want.ca === null) { ok = false; msg = 'Set the CA and exam totals'; }
            else if (Math.round((want.ca + want.exam) * 100) / 100 !== 100) { ok = false; msg = 'Totals must add up to 100'; }
            else if (ok) { msg = 'CA ' + want.ca + ' + Exam ' + want.exam + ' = 100'; }
            document.querySelector('[data-sum=all]').textContent = Math.round((want.ca || 0) * 100 + (want.exam || 0) * 100) / 100;
        } else if (all !== 100) { ok = false; if (msg === 'Adds up to 100') { msg = 'Must add up to 100'; } }
        note.className = 'ms-1 badge ' + (ok ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger');
        note.textContent = msg;
    }
    document.querySelectorAll('[data-target]').forEach(function (el) {
        el.addEventListener('input', function () {
            var other = document.querySelector('[data-target=' + (el.getAttribute('data-target') === 'ca' ? 'exam' : 'ca') + ']');
            var v = parseFloat(el.value);
            if (!isNaN(v) && v >= 0 && v <= 100) { other.value = Math.round((100 - v) * 100) / 100; }
            sum();
        });
    });
    body.addEventListener('input', sum);
    body.addEventListener('change', sum);
    body.addEventListener('click', function (e) {
        var b = e.target.closest('[data-remove]');
        if (b) { b.closest('tr').remove(); renumber(); }
    });
    var addBtn = document.querySelector('[data-add]');
    if (addBtn) addBtn.addEventListener('click', function () { add(); });
    (rows.length ? rows : [{}]).forEach(add);
})();
</script>
@endpush
