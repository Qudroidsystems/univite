@extends('layouts.master')

@section('content')
@php $editing = $leave->exists; @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => $editing ? 'Correct ' . $leave->reference : 'Apply for leave', 'icon' => 'ri-suitcase-line', 'back' => [route('leave.index'), 'My leave']])
    @include('academics.partials.alerts')
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    @if ($editing && $leave->decision_note)<div class="alert alert-info"><strong>Why it was returned:</strong> {{ $leave->decision_note }}</div>@endif

    <form method="POST" action="{{ $editing ? route('leave.update', $leave) : route('leave.store') }}" enctype="multipart/form-data" id="leave-form" data-no-ajax>
        @csrf @if ($editing) @method('PUT') @endif
        <div class="row">
            <div class="col-lg-8">
                <div class="card"><div class="card-body row g-3">
                    <div class="col-12"><label class="form-label">Leave type <span class="text-danger">*</span></label>
                        <select name="leave_type_id" id="lv-type" class="form-select" required>
                            <option value="">Choose…</option>
                            @foreach ($types as $t)
                                <option value="{{ $t->id }}" @selected(old('leave_type_id', $leave->leave_type_id) == $t->id) data-doc="{{ $t->needs_document ? ($t->document_label ?: 'Supporting document') : '' }}" data-acting="{{ $t->needs_acting_officer ? 1 : 0 }}" data-desc="{{ $t->description }}" data-conf="{{ $t->confidential ? 1 : 0 }}">{{ $t->name }}{{ $t->deducts_balance ? ' · ' . ($balances[$t->id] + 0) . ' day(s) left' : '' }}</option>
                            @endforeach
                        </select>
                        <div class="form-text" id="lv-desc"></div></div>
                    <div class="col-md-6"><label class="form-label">First day <span class="text-danger">*</span></label><input type="date" name="start_date" id="lv-start" class="form-control" value="{{ old('start_date', $leave->start_date?->toDateString()) }}" required></div>
                    <div class="col-md-6"><label class="form-label">Last day <span class="text-danger">*</span></label><input type="date" name="end_date" id="lv-end" class="form-control" value="{{ old('end_date', $leave->end_date?->toDateString()) }}" required></div>
                    <div class="col-12"><label class="form-label">Reason</label><textarea name="reason" rows="3" class="form-control">{{ old('reason', $leave->reason) }}</textarea></div>
                    <div class="col-md-7"><label class="form-label">Address while on leave</label><input name="address_during_leave" class="form-control" value="{{ old('address_during_leave', $leave->address_during_leave) }}"></div>
                    <div class="col-md-5"><label class="form-label">Phone while on leave</label><input name="phone_during_leave" class="form-control" value="{{ old('phone_during_leave', $leave->phone_during_leave ?? auth()->user()->phone_number) }}"></div>
                    <div class="col-12"><label class="form-label">Acting officer <span class="text-danger d-none" id="lv-acting-req">*</span></label>
                        <select name="acting_officer_id" id="lv-acting" class="form-select"><option value="">None</option>@foreach ($staff as $id => $name)<option value="{{ $id }}" @selected(old('acting_officer_id', $leave->acting_officer_id) == $id)>{{ $name }}</option>@endforeach</select>
                        <div class="form-text">They are asked to accept, and reminded before your leave starts.</div></div>
                    <div class="col-12" id="lv-doc-wrap"><label class="form-label"><span id="lv-doc-label">Supporting documents</span></label>
                        <input type="file" name="documents[]" id="lv-docs" class="form-control" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                        <div class="form-text">PDF, image or Word, up to 5 MB each.@if ($editing && $leave->documents->isNotEmpty()) Already attached: {{ $leave->documents->pluck('original_name')->implode(', ') }}.@endif <span id="lv-conf" class="d-none text-success"><i class="ri-lock-line"></i> Kept confidential: only staff allowed to see medical documents can open it.</span></div></div>
                </div></div>
            </div>
            <div class="col-lg-4">
                <div class="card position-sticky" style="top:80px"><div class="card-body">
                    <h6 class="mb-3">Before you submit</h6>
                    <div id="lv-summary" class="text-muted fs-13">Choose a leave type and dates.</div>
                    <ul id="lv-errors" class="text-danger fs-13 ps-3 mt-2 mb-0"></ul>
                    <ul id="lv-warnings" class="text-warning fs-13 ps-3 mt-2 mb-0"></ul>
                    <button class="btn btn-primary w-100 mt-3">{{ $editing ? 'Resubmit' : 'Submit application' }}</button>
                </div></div>
            </div>
        </div>
    </form>
</div></div></div>
<script>
(function () {
    const f = document.getElementById('leave-form'); if (!f) return;
    const type = f.querySelector('#lv-type'), start = f.querySelector('#lv-start'), end = f.querySelector('#lv-end'), acting = f.querySelector('#lv-acting'), docs = f.querySelector('#lv-docs');
    const out = f.querySelector('#lv-summary'), errs = f.querySelector('#lv-errors'), warns = f.querySelector('#lv-warnings');
    const li = (ul, items) => { ul.innerHTML = ''; (items || []).forEach(t => { const l = document.createElement('li'); l.textContent = t; ul.appendChild(l); }); };
    function describe() {
        const o = type.selectedOptions[0];
        f.querySelector('#lv-desc').textContent = o?.dataset.desc || '';
        f.querySelector('#lv-doc-label').textContent = o?.dataset.doc ? o.dataset.doc + ' (required)' : 'Supporting documents';
        f.querySelector('#lv-acting-req').classList.toggle('d-none', o?.dataset.acting !== '1');
        f.querySelector('#lv-conf').classList.toggle('d-none', o?.dataset.conf !== '1');
    }
    let timer;
    function check() {
        describe();
        if (!type.value || !start.value || !end.value) return;
        clearTimeout(timer);
        timer = setTimeout(() => {
            const q = new URLSearchParams({leave_type_id: type.value, start_date: start.value, end_date: end.value, acting_officer_id: acting.value, documents: docs.files.length, existing: '{{ $leave->id }}'});
            fetch('{{ route('leave.check') }}?' + q, {headers: {'Accept': 'application/json'}}).then(r => r.json()).then(d => {
                out.textContent = '';
                const s = document.createElement('div');
                s.innerHTML = '<div class="fs-4 fw-semibold text-body">' + d.days + ' <span class="fs-13 fw-normal text-muted">' + d.unit + '</span></div>'
                    + (d.resume ? '<div>Back at work on <strong class="text-body"></strong></div>' : '')
                    + (d.balance !== null ? '<div>Left before this application: <strong class="text-body">' + d.balance + '</strong></div>' : '');
                if (d.resume) s.querySelector('strong').textContent = d.resume;
                out.appendChild(s);
                li(errs, d.errors); li(warns, d.warnings);
            }).catch(() => {});
        }, 250);
    }
    [type, start, end, acting, docs].forEach(el => el.addEventListener('change', check));
    start.addEventListener('change', () => { if (!end.value || end.value < start.value) end.value = start.value; });
    check();
})();
</script>
@endsection
