@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="page-title-box"><h4 class="mb-0">Registration settings</h4></div>
            @include('academics.partials.alerts')
            <form method="POST" action="{{ route('registrations.settings.update') }}" class="card">
                @csrf @method('PUT')
                <div class="card-body row g-3">
                    <div class="col-12"><div class="form-check form-switch form-switch-lg"><input type="hidden" name="open" value="0"><input class="form-check-input" type="checkbox" name="open" value="1" id="s-open" @checked($s['open'] === '1')><label class="form-check-label" for="s-open">Registration is open</label></div></div>
                    <div class="col-12 text-muted fs-13">These are the university's defaults. A faculty, department or programme can set its own credit load (also per level and semester) under <a href="{{ route('academics.rules.index') }}">Academic Rules</a>.</div>
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
        </div>
    </div>
</div>
@endsection
