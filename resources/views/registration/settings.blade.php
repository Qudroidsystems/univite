@extends('layouts.master')

@section('content')
@php
    $show = fn ($name, $v) => match (\App\Services\RegistrationSettings::PERIOD[$name][2]) {
        'bool' => $v === '1' ? 'On' : 'Off',
        'fee' => $feeRules[$v] ?? $v,
        default => $v . ' day(s)',
    };
    $periodLabel = $session ? $session->name . ($type ? ' · ' . $type->name : '') : 'University default';
    $canEdit = auth()->user()->can('Edit registration settings');
@endphp
<style>
    .uv-rule { display: grid; grid-template-columns: minmax(220px, 1.4fr) auto minmax(220px, 1fr); gap: 14px; align-items: center; padding: 14px 18px; border-bottom: 1px solid var(--tb-border-color, #e9ebec); transition: background .2s; }
    .uv-rule:last-child { border-bottom: 0; }
    .uv-rule.is-own { background: rgba(37, 99, 235, .05); }
    .uv-rule .uv-from { font-size: 12px; color: var(--tb-secondary-color, #878a99); }
    .uv-rule.is-own .uv-from { color: var(--tb-primary, #2563eb); }
    .uv-rule .uv-ctl { transition: opacity .2s; }
    .uv-rule:not(.is-own) .uv-ctl { opacity: .45; pointer-events: none; }
    @media (max-width: 767px) { .uv-rule { grid-template-columns: 1fr; } }
</style>
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="page-title-box d-flex flex-wrap gap-2 align-items-center justify-content-between">
                <h4 class="mb-0">Registration settings</h4>
                <div class="d-flex flex-wrap gap-2">
                    @can('Copy session setup')<a href="{{ route('sessions.clone', array_filter(['source' => $session?->id, 'categories' => ['registration', 'windows'], 'mode' => $type ? 'semester' : 'session', 'source_type' => $type?->id])) }}" class="btn btn-soft-primary"><i class="ri-file-copy-line me-1"></i>Copy to another {{ $type ? 'semester' : 'session' }}</a>@endcan
                    @can('Manage setup snapshots')<a href="{{ route('setup.snapshots.index', array_filter(['session' => $session?->id, 'sections' => ['registration', 'windows']])) }}" class="btn btn-soft-secondary"><i class="ri-camera-line me-1"></i>Snapshots</a>@endcan
                </div>
            </div>
            @include('academics.partials.alerts')

            <form method="GET" action="{{ route('registrations.settings') }}" class="uv-period-bar mb-3 d-flex flex-wrap gap-3 align-items-end">
                <div class="flex-grow-1" style="min-width:240px">
                    <div class="text-white-75 fs-12 text-uppercase fw-semibold mb-1">Settings for</div>
                    <h5><i class="ri-calendar-2-line me-1"></i>{{ $periodLabel }}</h5>
                    <div class="text-white-75 fs-13 mt-1">{{ $session ? 'Anything not set here follows ' . ($type ? $session->name : 'the university default') . '.' : 'Every session and semester follows these unless it sets its own.' }}</div>
                </div>
                <div><label class="text-white-75 fs-12 d-block mb-1">Session</label>
                    <select name="session" class="form-select" onchange="this.form.semester && (this.form.semester.value = ''); this.form.submit()">
                        <option value="">University default</option>
                        @foreach ($sessions as $x)<option value="{{ $x->id }}" @selected($session?->id === $x->id)>{{ $x->name }} ({{ ucfirst($x->status) }})</option>@endforeach
                    </select></div>
                @if ($session)
                    <div><label class="text-white-75 fs-12 d-block mb-1">Semester</label>
                        <select name="semester" class="form-select" onchange="this.form.submit()">
                            <option value="">Whole session</option>
                            @foreach ($types as $t)<option value="{{ $t->id }}" @selected($type?->id === $t->id)>{{ $t->name }}</option>@endforeach
                        </select></div>
                @endif
            </form>

            @if (! $session)
                <form method="POST" action="{{ route('registrations.settings.update') }}" class="card">
                    @csrf @method('PUT')
                    <div class="card-body row g-3">
                        <div class="col-12"><div class="form-check form-switch form-switch-lg"><input type="hidden" name="open" value="0"><input class="form-check-input" type="checkbox" name="open" value="1" id="s-open" @checked($s['open'] === '1')><label class="form-check-label" for="s-open">Registration is open</label></div></div>
                        <div class="col-12 text-muted fs-13">These are the university's defaults. A session or semester can set its own (choose it above), and a faculty, department or programme can set its own credit load (also per level and semester) under <a href="{{ route('academics.rules.index') }}">Academic Rules</a>.</div>
                        <div class="col-md-6"><label class="form-label">Minimum units per semester</label><input type="number" name="min_units" value="{{ old('min_units', $s['min_units']) }}" class="form-control @error('min_units') is-invalid @enderror" min="0" required>@error('min_units')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-md-6"><label class="form-label">Maximum units per semester</label><input type="number" name="max_units" value="{{ old('max_units', $s['max_units']) }}" class="form-control @error('max_units') is-invalid @enderror" min="1" required>@error('max_units')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                        <div class="col-12"><label class="form-label">Check school fees before registering</label>
                            <select name="fee_rule" class="form-select">@foreach ($feeRules as $k => $l)<option value="{{ $k }}" @selected(old('fee_rule', $s['fee_rule']) === $k)>{{ $l }}</option>@endforeach</select>
                            <div class="form-text">Uses the student's fees invoice for the current session and semester. A student with nothing billed is not blocked.</div></div>
                        @foreach ([
                            'require_approval' => ['An adviser/HOD must approve each form', 'Off = a submitted form is approved straight away.'],
                            'require_clearance' => ['New students must be cleared first', 'Students still in admission clearance cannot register.'],
                            'force_carryovers' => ['Failed courses must be registered again', 'The form cannot be submitted without the carry-overs.'],
                            'check_prerequisites' => ['Check prerequisites', 'A course is locked until its prerequisites are passed.'],
                            'carryover_exempt' => ['Carry-overs do not count against the maximum', ''],
                        ] as $k => [$l, $h])
                            <div class="col-12"><div class="form-check form-switch"><input type="hidden" name="{{ $k }}" value="0"><input class="form-check-input" type="checkbox" name="{{ $k }}" value="1" id="s-{{ $k }}" @checked($s[$k] === '1')><label class="form-check-label" for="s-{{ $k }}">{{ $l }}</label></div>@if ($h)<div class="form-text">{{ $h }}</div>@endif</div>
                        @endforeach
                    </div>
                    @can('Edit registration settings')<div class="card-footer text-end"><button class="btn btn-primary" type="submit"><i class="ri-save-line me-1"></i>Save settings</button></div>@endcan
                </form>

                <div class="card">
                    <div class="card-header"><h6 class="card-title mb-0"><i class="ri-git-branch-line me-1"></i>Sessions and semesters with their own settings</h6></div>
                    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>Period</th><th>Own settings</th><th>Last changed</th><th></th></tr></thead>
                        <tbody>
                        @forelse ($overridden as $p)
                            <tr><td class="fw-semibold">{{ $p->label() }}</td>
                                <td class="fs-13">@foreach ($p->values as $k => $v)<span class="badge bg-primary-subtle text-primary me-1">{{ \App\Services\RegistrationSettings::PERIOD[$k][0] ?? $k }}: {{ $show($k, (string) $v) }}</span>@endforeach</td>
                                <td class="text-nowrap fs-13 text-muted">{{ $p->updated_at?->diffForHumans() }}</td>
                                <td class="text-end"><a class="btn btn-sm btn-soft-primary" href="{{ route('registrations.settings', array_filter(['session' => $p->academic_session_id, 'semester' => $p->semester_type_id])) }}">Open</a></td></tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-4">Every session follows the university default. Choose a session above to give it its own settings.</td></tr>
                        @endforelse
                        </tbody></table></div>
                </div>
            @else
                <form method="POST" action="{{ route('registrations.settings.update') }}" class="card" id="period-form">
                    @csrf @method('PUT')
                    <input type="hidden" name="session" value="{{ $session->id }}">
                    @if ($type)<input type="hidden" name="semester" value="{{ $type->id }}">@endif
                    <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
                        <h6 class="card-title mb-0">Course registration in {{ $periodLabel }}</h6>
                        <span class="fs-12 text-muted">Tick <strong>Set here</strong> to give this {{ $type ? 'semester' : 'session' }} its own value.</span>
                    </div>
                    <div>
                        @foreach (\App\Services\RegistrationSettings::PERIOD as $name => [$label, $help, $kind])
                            @php $own = array_key_exists($name, $period['own']); $value = $period['values'][$name]; @endphp
                            <div class="uv-rule {{ $own ? 'is-own' : '' }}" data-rule>
                                <div><div class="fw-semibold">{{ $label }}</div>@if ($help)<div class="fs-12 text-muted">{{ $help }}</div>@endif
                                    <div class="uv-from mt-1" data-from data-inherited="{{ $own ? ($parent ? 'Inherited from ' . $parent['from'][$name] . ': ' . $show($name, $parent['values'][$name]) : 'Inherited from University default: ' . $show($name, \App\Services\RegistrationSettings::base($name))) : 'Inherited from ' . $period['from'][$name] . ': ' . $show($name, $value) }}">
                                        @if ($own)<i class="ri-pushpin-2-line me-1"></i>Set for {{ $periodLabel }}@else<i class="ri-arrow-down-line me-1"></i>Inherited from {{ $period['from'][$name] }}: {{ $show($name, $value) }}@endif</div></div>
                                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="own[{{ $name }}]" value="1" id="own-{{ $name }}" data-own @checked($own) @disabled(! $canEdit)><label class="form-check-label fs-13" for="own-{{ $name }}">Set here</label></div>
                                <div class="uv-ctl">
                                    @if ($kind === 'bool')
                                        <div class="form-check form-switch form-switch-lg mb-0"><input class="form-check-input" type="checkbox" name="{{ $name }}" value="1" id="v-{{ $name }}" @checked($value === '1')><label class="form-check-label" for="v-{{ $name }}">{{ $value === '1' ? 'On' : 'Off' }}</label></div>
                                    @elseif ($kind === 'fee')
                                        <select name="fee_rule" class="form-select">@foreach ($feeRules as $k => $l)<option value="{{ $k }}" @selected($value === $k)>{{ $l }}</option>@endforeach</select>
                                    @else
                                        <div class="input-group" style="max-width:200px"><input type="number" name="{{ $name }}" value="{{ $value }}" min="1" max="120" class="form-control"><span class="input-group-text">days</span></div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="card-header border-top"><h6 class="card-title mb-0"><i class="ri-time-line me-1"></i>Registration window{{ $type ? '' : 's' }}</h6></div>
                    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>Semester</th><th>Opens</th><th>Closes</th><th>Status</th></tr></thead>
                        <tbody>
                        @forelse ($semesters as $sem)
                            @php
                                $o = $sem->registration_opens_at; $c = $sem->registration_closes_at;
                                $state = ! $o && ! $c ? ['No dates', 'secondary'] : (($o && now()->lt($o)) ? ['Not open yet', 'info'] : (($c && now()->gt($c)) ? ['Closed', 'secondary'] : ['Open now', 'success']));
                            @endphp
                            <tr><td class="fw-semibold">{{ $sem->name }}</td>
                                <td><input type="datetime-local" name="windows[{{ $sem->id }}][opens]" value="{{ $o?->format('Y-m-d\TH:i') }}" class="form-control form-control-sm" style="max-width:220px"></td>
                                <td><input type="datetime-local" name="windows[{{ $sem->id }}][closes]" value="{{ $c?->format('Y-m-d\TH:i') }}" class="form-control form-control-sm @error('windows.' . $sem->id . '.closes') is-invalid @enderror" style="max-width:220px">@error('windows.' . $sem->id . '.closes')<div class="invalid-feedback">{{ $message }}</div>@enderror</td>
                                <td><span class="badge bg-{{ $state[1] }}-subtle text-{{ $state[1] }}">{{ $state[0] }}</span></td></tr>
                        @empty
                            <tr><td colspan="4" class="text-muted text-center py-3">{{ $session->name }} has no {{ $type ? $type->name : 'semesters' }} yet. Add it under Academic Setup, Semesters.</td></tr>
                        @endforelse
                        </tbody></table></div>

                    @if ($canEdit)
                        <div class="card-footer d-flex flex-wrap gap-2 justify-content-between align-items-center">
                            <span class="text-muted fs-13">Untick every <strong>Set here</strong> to make {{ $periodLabel }} follow the settings above it again.</span>
                            <button class="btn btn-primary" type="submit"><i class="ri-save-line me-1"></i>Save for {{ $periodLabel }}</button>
                        </div>
                    @endif
                </form>
            @endif
        </div>
    </div>
</div>
@push('scripts')
<script>
(function () {
    const form = document.getElementById('period-form');
    if (!form) return;
    form.querySelectorAll('[data-rule]').forEach(function (row) {
        const own = row.querySelector('[data-own]'), from = row.querySelector('[data-from]');
        const label = @json('Set for ' . $periodLabel);
        own.addEventListener('change', function () {
            row.classList.toggle('is-own', own.checked);
            from.innerHTML = own.checked ? '<i class="ri-pushpin-2-line me-1"></i>' + label : '<i class="ri-arrow-down-line me-1"></i>' + from.dataset.inherited;
        });
        const sw = row.querySelector('.uv-ctl input[type=checkbox]');
        if (sw) sw.addEventListener('change', function () { sw.nextElementSibling.textContent = sw.checked ? 'On' : 'Off'; });
    });
})();
</script>
@endpush
@endsection
