@extends('layouts.master')

@section('content')
@php
    // A page guarded by one permission (rather than View/Create/Edit/Delete {noun}) passes $abilities.
    $canCreate = in_array('create', $actions) && ($abilities['create'] ?? auth()->user()->can('Create ' . $permission));
    $canUpdate = in_array('edit', $actions) && ($abilities['edit'] ?? auth()->user()->can('Edit ' . $permission));
    $canDelete = in_array('delete', $actions) && ($abilities['delete'] ?? auth()->user()->can('Delete ' . $permission));
    $hasOld    = old('_form_mode') !== null;
    $colspan   = count($columns) + 2;
@endphp

<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">

            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                        <h4 class="mb-sm-0"><i class="{{ $icon }} me-1 align-middle"></i>{{ $title }}</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                                <li class="breadcrumb-item active">{{ $title }}</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            @include('academics.partials.alerts')

            @if ($intro || $snapshotSections || $copyCategories)
                <div class="d-flex flex-wrap gap-2 align-items-start justify-content-between mb-3">
                    <p class="text-muted mb-0 flex-grow-1" style="max-width: 900px">{{ $intro }}</p>
                    @include('setup.partials.links', ['sections' => $snapshotSections, 'copy' => $copyCategories, 'session' => request('session') ?: request('academic_session_id')])
                </div>
            @endif

            @includeWhen($extraView, $extraView ?? 'academics.partials.alerts')

            <div class="card">
                <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
                    <form method="GET" class="d-flex flex-wrap gap-2 align-items-center" id="filter-form">
                        @if ($canSearch)
                            <input type="search" name="q" value="{{ request('q') }}" class="form-control" style="width:220px" placeholder="Search…">
                        @endif
                        @foreach ($filters as [$name, $label, $options])
                            <select name="{{ $name }}" class="form-select" style="width:auto;max-width:220px" onchange="this.form.submit()">
                                <option value="">All {{ Str::plural(strtolower($label)) }}</option>
                                @foreach ($options as $value => $text)
                                    <option value="{{ $value }}" @selected((string) request($name) === (string) $value)>{{ $text }}</option>
                                @endforeach
                            </select>
                        @endforeach
                        @if ($canSearch)
                            <button class="btn btn-soft-primary" type="submit"><i class="ri-search-line"></i></button>
                        @endif
                        @if (request()->query())
                            <a href="{{ url()->current() }}" class="btn btn-light">Clear</a>
                        @endif
                    </form>

                    <div class="d-flex gap-2">
                        @isset($headerButtons)
                            {{ $headerButtons }}
                        @endisset
                        @if ($canCreate)
                            <button type="button" class="btn btn-primary" id="btn-add"><i class="ri-add-line align-bottom me-1"></i>Add {{ $singular }}</button>
                        @endif
                    </div>
                </div>

                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    @foreach ($columns as [$label])
                                        <th>{{ $label }}</th>
                                    @endforeach
                                    <th style="width:1%" class="text-nowrap">Last changed by</th>
                                    <th class="text-end" style="width:1%">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($rows as $row)
                                    <tr>
                                        @foreach ($columns as [, $value])
                                            <td>{{ $value($row) }}</td>
                                        @endforeach
                                        <td><x-last-change :record="$row" :among="$rows" /></td>
                                        <td class="text-end text-nowrap">
                                            @foreach ($rowActions as [$aLabel, $aRoute, $aPerm, $aIcon, $aVisible])
                                                @if (auth()->user()->can($aPerm) && $aVisible($row))
                                                    <form method="POST" action="{{ route($aRoute, $row->id) }}" class="d-inline">
                                                        @csrf
                                                        <button type="submit" class="btn btn-sm btn-soft-success" title="{{ $aLabel }}"><i class="{{ $aIcon }} me-1"></i>{{ $aLabel }}</button>
                                                    </form>
                                                @endif
                                            @endforeach
                                            @if ($canUpdate)
                                                <button type="button" class="btn btn-sm btn-soft-primary btn-edit" title="Edit"
                                                        data-record="{{ json_encode($recordData($row)) }}"><i class="ri-pencil-line"></i></button>
                                            @endif
                                            @if ($canDelete)
                                                <form method="POST" action="{{ route($routeBase . '.destroy', $row->id) }}" class="d-inline"
                                                      onsubmit="return confirm('Delete this {{ strtolower($singular) }}? This cannot be undone.')">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-soft-danger" title="Delete"><i class="ri-delete-bin-line"></i></button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ $colspan }}" class="text-center text-muted py-5">
                                            <i class="{{ $icon }} fs-1 d-block mb-2"></i>
                                            No {{ strtolower($title) }} found.
                                            @if ($canCreate) <div class="mt-2">Click <strong>Add {{ $singular }}</strong> to create the first one.</div> @endif
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @if ($rows->hasPages())
                    <div class="card-footer">{{ $rows->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</div>

@if ($canCreate || $canUpdate)
<div class="modal fade" id="crud-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" action="{{ $canCreate ? route($routeBase . '.store') : '#' }}" class="modal-content" id="crud-form" autocomplete="off" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="_method" value="POST" id="crud-method">
            <input type="hidden" name="_form_mode" value="{{ old('_form_mode', 'create') }}" id="crud-mode">
            <input type="hidden" name="_form_id" value="{{ old('_form_id') }}" id="crud-id">

            <div class="modal-header" id="crud-head">
                <h5 class="modal-title"><i class="{{ $icon }} me-2"></i><span id="crud-title">Add {{ $singular }}</span>
                    <span class="uv-modal-sub" id="crud-sub">Fill in the details below. Fields marked * are required.</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="row g-3">
                    @php $lastSection = null; @endphp
                    @foreach ($fields as $f)
                        @if (! empty($f['custom']) && ($f['section'] ?? null) !== $lastSection)
                            @php $lastSection = $f['section'] ?? null; @endphp
                            @if ($lastSection)<div class="col-12"><h6 class="text-uppercase text-muted fs-12 border-bottom pb-1 mb-0 mt-2">{{ $lastSection }}</h6></div>@endif
                        @endif
                        @php
                            $name    = $f['name'];
                            $type    = $f['type'] ?? 'text';
                            $default = $f['default'] ?? ($type === 'switch' ? false : '');
                            $value   = old($name, $default);
                        @endphp
                        <div class="col-md-{{ $f['col'] ?? 12 }}" @if (isset($f['only'])) data-only="{{ $f['only'] }}" @endif @if (isset($f['showWhen'])) data-show-when="{{ $f['showWhen'][0] }}={{ $f['showWhen'][1] }}" @endif>
                            @if ($type === 'switch')
                                <div class="form-check form-switch form-switch-lg">
                                    <input type="hidden" name="{{ $name }}" value="0">
                                    <input class="form-check-input" type="checkbox" role="switch" id="f-{{ $name }}" name="{{ $name }}" value="1"
                                           data-default="{{ $default ? 1 : 0 }}" @checked($hasOld ? (bool) old($name) : (bool) $default)>
                                    <label class="form-check-label" for="f-{{ $name }}">{{ $f['label'] }}</label>
                                </div>
                            @else
                                <label class="form-label" for="f-{{ $name }}">{{ $f['label'] }}@if (! empty($f['required'])) <span class="text-danger">*</span>@endif</label>
                                @if ($type === 'select')
                                    <select class="form-select @error($name) is-invalid @enderror" id="f-{{ $name }}" name="{{ $name }}" data-default="{{ $f['default'] ?? '' }}" @required(! empty($f['required']))
                                        {!! empty($f['lookup']) ? '' : 'data-lookup="' . e($f['lookup']) . '"' . (empty($f['lookup_parent']) ? '' : ' data-lookup-parent="' . e($f['lookup_parent']) . '"') . (empty($f['lookup_active']) ? '' : ' data-lookup-active="1"') !!}>
                                        <option value="">{{ $f['blank'] ?? 'Select…' }}</option>
                                        @foreach ($f['options'] as $optValue => $optLabel)
                                            @if (is_array($optLabel))
                                                <optgroup label="{{ $optValue }}">
                                                    @foreach ($optLabel as $v => $l)
                                                        <option value="{{ $v }}" @selected((string) $value === (string) $v)>{{ $l }}</option>
                                                    @endforeach
                                                </optgroup>
                                            @else
                                                <option value="{{ $optValue }}" @selected((string) $value === (string) $optValue)>{{ $optLabel }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                @elseif ($type === 'multiselect')
                                    @php $chosen = array_map('strval', (array) old($name, $f['default'] ?? [])); @endphp
                                    <select class="form-select @error($name) is-invalid @enderror" id="f-{{ $name }}" name="{{ $name }}[]" multiple data-multi="1"
                                            @if (! empty($f['exclude_self'])) data-exclude-self="1" @endif data-placeholder="{{ $f['placeholder'] ?? 'Search and tick…' }}">
                                        @foreach ($f['options'] as $optValue => $optLabel)
                                            @if (is_array($optLabel))
                                                <optgroup label="{{ $optValue }}">
                                                    @foreach ($optLabel as $v => $l)
                                                        <option value="{{ $v }}" @selected(in_array((string) $v, $chosen, true))>{{ $l }}</option>
                                                    @endforeach
                                                </optgroup>
                                            @else
                                                <option value="{{ $optValue }}" @selected(in_array((string) $optValue, $chosen, true))>{{ $optLabel }}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                    <div class="mt-2 d-flex flex-wrap gap-1" data-chosen-for="{{ $name }}"></div>
                                @elseif ($type === 'file')
                                    <input type="file" class="form-control @error($name) is-invalid @enderror" id="f-{{ $name }}" name="{{ $name }}" @if (! empty($f['accept'])) accept="{{ $f['accept'] }}" @endif>
                                    <div class="form-text text-success d-none" data-file-for="{{ $name }}"></div>
                                @elseif ($type === 'textarea')
                                    <textarea class="form-control @error($name) is-invalid @enderror" id="f-{{ $name }}" name="{{ $name }}" rows="3">{{ $value }}</textarea>
                                @else
                                    <input type="{{ $type }}" class="form-control @error($name) is-invalid @enderror" id="f-{{ $name }}" name="{{ $name }}"
                                           value="{{ $value }}" data-default="{{ $f['default'] ?? '' }}"
                                           @if ($type === 'number') step="{{ $f['step'] ?? 1 }}" min="0" @endif
                                           @if (isset($f['placeholder'])) placeholder="{{ $f['placeholder'] }}" @endif
                                           @required(! empty($f['required']))>
                                @endif
                                @error($name) <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                @if (! empty($f['help'])) <div class="form-text">{{ $f['help'] }}</div> @endif
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="crud-submit"><i class="ri-save-line me-1"></i>Save</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const modalEl = document.getElementById('crud-modal');
    const form    = document.getElementById('crud-form');
    const modal   = new bootstrap.Modal(modalEl);
    const updateUrl = @json(route($routeBase . '.update', '__ID__'));
    const storeUrl  = @json($canCreate ? route($routeBase . '.store') : '#');
    const singular  = @json($singular);

    function setMode(mode, id) {
        const editing = mode === 'edit';
        form.action = editing ? updateUrl.replace('__ID__', id) : storeUrl;
        document.getElementById('crud-method').value = editing ? 'PUT' : 'POST';
        document.getElementById('crud-mode').value   = mode;
        document.getElementById('crud-id').value     = id || '';
        document.getElementById('crud-title').textContent = (editing ? 'Edit ' : 'Add ') + singular;
        document.getElementById('crud-head').classList.toggle('is-edit', editing);
        document.getElementById('crud-sub').textContent = editing ? 'Change what you need and save. Fields marked * are required.' : 'Fill in the details below. Fields marked * are required.';
        form.querySelectorAll('[data-only]').forEach(el => {
            const show = el.dataset.only === (editing ? 'edit' : 'create');
            el.style.display = show ? '' : 'none';
            el.querySelectorAll('input, select, textarea').forEach(i => { i.disabled = ! show; });
        });
    }

    function resetForm() {
        form.querySelectorAll('[data-file-for]').forEach(el => { el.textContent = ''; el.classList.add('d-none'); });
        form.querySelectorAll('input:not([type=hidden]):not([type=checkbox]), select, textarea').forEach(el => {
            if (el.type === 'file') { el.value = ''; el.classList.remove('is-invalid'); return; }
            el.value = el.dataset.default ?? '';
            el.classList.remove('is-invalid');
        });
        form.querySelectorAll('input[type=checkbox]').forEach(el => { el.checked = el.dataset.default === '1'; });
        form.querySelectorAll('select[data-multi]').forEach(el => {
            Array.from(el.options).forEach(o => { o.selected = false; o.disabled = false; });
            el.dispatchEvent(new Event('change', { bubbles: true }));
        });
        form.querySelectorAll('.invalid-feedback').forEach(el => el.remove());
    }

    // Under a multi-select (prerequisites), list what is ticked so it can be checked at a glance and removed.
    function showChosen(sel) {
        const box = form.querySelector('[data-chosen-for="' + sel.name.replace('[]', '') + '"]');
        if (!box) return;
        const picked = Array.from(sel.selectedOptions);
        box.innerHTML = picked.length ? '' : '<span class="text-muted fs-12">None ticked.</span>';
        picked.forEach(o => {
            const chip = document.createElement('span');
            chip.className = 'badge bg-primary-subtle text-primary fs-12 d-inline-flex align-items-center gap-1';
            chip.textContent = o.textContent;
            const x = document.createElement('button');
            x.type = 'button'; x.className = 'btn-close btn-close-sm ms-1'; x.style.fontSize = '.55rem'; x.title = 'Remove';
            x.addEventListener('click', () => { o.selected = false; sel.dispatchEvent(new Event('change', { bubbles: true })); });
            chip.appendChild(x);
            box.appendChild(chip);
        });
        if (window.UvSelect) window.UvSelect.refresh(sel);
    }
    form.querySelectorAll('select[data-multi]').forEach(sel => {
        sel.addEventListener('change', () => showChosen(sel));
        showChosen(sel);
    });

    function applyShowWhen() {
        form.querySelectorAll('[data-show-when]').forEach(el => {
            const [dep, want] = el.dataset.showWhen.split('=');
            const src = form.querySelector('[name="' + dep + '"]');
            el.style.display = (src && src.value === want) ? '' : 'none';
        });
    }
    form.addEventListener('change', applyShowWhen);

    document.getElementById('btn-add')?.addEventListener('click', () => {
        touched.clear(); clearLive(); resetForm(); setMode('create'); applyShowWhen(); modal.show();
    });

    document.querySelectorAll('.btn-edit').forEach(btn => btn.addEventListener('click', () => {
        const rec = JSON.parse(btn.dataset.record);
        touched.clear(); clearLive(); resetForm(); setMode('edit', rec.id);
        Object.entries(rec).forEach(([key, val]) => {
            if (key.endsWith('__file')) {
                const note = form.querySelector('[data-file-for="' + key.slice(0, -6) + '"]');
                if (note && val) { note.textContent = 'Current file: ' + val + ' (choose a new file to replace it)'; note.classList.remove('d-none'); }
                return;
            }
            const multi = form.querySelector('select[data-multi][name="' + key + '[]"]');
            if (multi) {
                const want = (Array.isArray(val) ? val : []).map(String);
                Array.from(multi.options).forEach(o => {
                    o.selected = want.includes(o.value);
                    o.disabled = multi.dataset.excludeSelf === '1' && String(o.value) === String(rec.id);   // a course is not its own prerequisite
                });
                multi.dispatchEvent(new Event('change', { bubbles: true }));
                return;
            }
            const input = form.querySelector('[name="' + key + '"]:not([type=hidden])');
            if (!input) return;
            if (input.type === 'file') return;
            if (input.type === 'checkbox') input.checked = !!val;
            else input.value = val ?? '';
        });
        applyShowWhen(); modal.show();
    }));

    // Check the form as it is filled in: the server runs the same rules a save would (duplicates included) and
    // nothing is saved. A field shows its problem once it has a value or has been touched.
    const touched = new Set();
    const submitBtn = document.getElementById('crud-submit');
    let timer = null, seq = 0;
    function fieldOf(name) { return form.querySelector('[name="' + name + '"]:not([type=hidden])') || form.querySelector('[name="' + name + '[]"]') || form.querySelector('[name="' + name + '"]'); }
    function clearLive() {
        form.querySelectorAll('.uv-live-error').forEach(el => el.remove());
        form.querySelectorAll('.is-invalid[data-live]').forEach(el => { el.classList.remove('is-invalid'); el.removeAttribute('data-live'); el.dispatchEvent(new Event('uv:invalid-changed')); });
        submitBtn.disabled = false; submitBtn.removeAttribute('title');
    }
    function showLive(errors) {
        clearLive();
        let shown = 0;
        Object.entries(errors).forEach(([name, msgs]) => {
            const input = fieldOf(name.split('.')[0]);
            if (!input || input.disabled || input.type === 'file') return;
            const filled = input.type === 'checkbox' ? true : String(input.value ?? '').trim() !== '';
            if (!filled && !touched.has(input.name)) return;
            input.classList.add('is-invalid'); input.setAttribute('data-live', '1');
            const box = input.closest('[class*="col-"]');
            if (box && !box.querySelector('.invalid-feedback:not(.uv-live-error)')) {
                const div = document.createElement('div');
                div.className = 'invalid-feedback d-block uv-live-error';
                div.innerHTML = '<i class="ri-error-warning-line me-1"></i>' + String(msgs[0]).replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
                box.appendChild(div);
            }
            shown++;
        });
        if (shown) { submitBtn.disabled = true; submitBtn.title = 'Fix the highlighted fields first'; }
        if (window.UvSelect) { form.querySelectorAll('select').forEach(el => window.UvSelect.refresh(el)); }
    }
    function check() {
        const data = new FormData(form);
        form.querySelectorAll('input[type=file]').forEach(f => data.delete(f.name));
        const mine = ++seq;
        fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin',
            headers: { 'X-Uv-Check': '1', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.status === 422 ? r.json() : (r.ok ? { errors: {} } : null))
            .then(out => { if (out && mine === seq && modalEl.classList.contains('show')) { showLive(out.errors || {}); } })
            .catch(() => {});
    }
    function soon(delay) { clearTimeout(timer); timer = setTimeout(check, delay); }
    form.addEventListener('change', e => { if (e.target.name) touched.add(e.target.name); soon(150); });
    form.addEventListener('input', e => { if (e.target.name && e.target.tagName !== 'SELECT') { touched.add(e.target.name); soon(450); } });
    modalEl.addEventListener('shown.bs.modal', () => { if (document.getElementById('crud-mode').value === 'edit') soon(0); });
    modalEl.addEventListener('hidden.bs.modal', () => { touched.clear(); seq++; clearLive(); });

    // Re-open the modal after a failed validation.
    @if ($errors->any() && $hasOld)
        setMode(@json(old('_form_mode')), @json(old('_form_id')));
        applyShowWhen(); modal.show();
    @else
        setMode('create'); applyShowWhen();
    @endif
})();
</script>
@endpush
@endif
@endsection
