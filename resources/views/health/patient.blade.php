@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => $label, 'icon' => 'ri-heart-pulse-line', 'subtitle' => $patient->student ? $patient->student->programme?->name . ' · ' . $patient->student->current_level . ' level' : ($patient->staffProfile?->workplace() ?? 'Staff'), 'back' => [route('clinic.index'), 'Health Centre']])
    @include('academics.partials.alerts')
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <div class="row">
        <div class="col-xl-5">
            <div class="card"><div class="card-header d-flex justify-content-between"><h6 class="card-title mb-0">Summary</h6>
                @if ($record->fitness)<span class="badge {{ \App\Models\HealthRecord::FITNESS_BADGES[$record->fitness] }}">{{ \App\Models\HealthRecord::FITNESS[$record->fitness] }}</span>@else<span class="badge bg-light text-body">Not examined</span>@endif</div>
                <div class="card-body fs-13">
                    @if ($record->allergies)<div class="alert alert-danger py-2 mb-2"><strong>Allergies:</strong> {{ $record->allergies }}</div>@endif
                    <dl class="row mb-0">
                        <dt class="col-5">Blood group / genotype</dt><dd class="col-7">{{ $record->blood_group ?? '—' }} / {{ $record->genotype ?? '—' }}</dd>
                        <dt class="col-5">Height / weight</dt><dd class="col-7">{{ $record->height_cm ? $record->height_cm . ' cm' : '—' }} / {{ $record->weight_kg ? $record->weight_kg . ' kg' : '—' }}{{ $record->bmi() ? ' (BMI ' . $record->bmi() . ')' : '' }}</dd>
                        <dt class="col-5">Conditions</dt><dd class="col-7">{{ $record->conditions ?? '—' }}</dd>
                        <dt class="col-5">Medication</dt><dd class="col-7">{{ $record->medications ?? '—' }}</dd>
                        <dt class="col-5">Disabilities</dt><dd class="col-7">{{ $record->disabilities ?? '—' }}</dd>
                        <dt class="col-5">Emergency contact</dt><dd class="col-7">{{ $record->emergency_name ? $record->emergency_name . ' (' . $record->emergency_relationship . ') ' . $record->emergency_phone : '—' }}</dd>
                        @if ($record->examined_at)<dt class="col-5">Examined</dt><dd class="col-7">{{ $record->examined_at->format('j M Y') }} by {{ $record->examiner?->name }}@if ($record->fitness_note)<div>{{ $record->fitness_note }}</div>@endif</dd>@endif
                        @if ($record->self_updated_at)<dt class="col-5">Updated by the person</dt><dd class="col-7">{{ $record->self_updated_at->format('j M Y') }}</dd>@endif
                    </dl>
                </div>
            </div>
            @canany(['Record clinic visits', 'Record medical examinations'])
            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Edit details</h6></div>
                <form method="POST" action="{{ route('clinic.details', $patient) }}">@csrf @method('PUT')<div class="card-body">@include('health._details-form', ['record' => $record])</div><div class="card-footer text-end"><button class="btn btn-light">Save details</button></div></form>
            </div>
            @endcanany
            @can('Record medical examinations')
            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Medical examination</h6></div>
                <form method="POST" action="{{ route('clinic.examine', $patient) }}">@csrf<div class="card-body row g-2">
                    <div class="col-md-4"><label class="form-label">Height (cm)</label><input name="height_cm" type="number" step="0.1" value="{{ $record->height_cm }}" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">Weight (kg)</label><input name="weight_kg" type="number" step="0.1" value="{{ $record->weight_kg }}" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">Result</label><select name="fitness" class="form-select" required>@foreach (\App\Models\HealthRecord::FITNESS as $k => $l)<option value="{{ $k }}" @selected($record->fitness === $k)>{{ $l }}</option>@endforeach</select></div>
                    <div class="col-md-6"><label class="form-label">Blood group</label><select name="blood_group" class="form-select"><option value="">Unchanged</option>@foreach (\App\Support\Lookups::options('blood_group') as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <div class="col-md-6"><label class="form-label">Genotype</label><select name="genotype" class="form-select"><option value="">Unchanged</option>@foreach (\App\Support\Lookups::options('genotype') as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <div class="col-12"><label class="form-label">Notes (needed unless fit)</label><textarea name="fitness_note" rows="2" class="form-control"></textarea></div>
                </div><div class="card-footer text-end"><button class="btn btn-primary">Record examination</button></div></form>
            </div>
            @endcan
        </div>
        <div class="col-xl-7">
            @can('Record clinic visits')
            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Record a visit</h6></div>
                <form method="POST" action="{{ route('clinic.visits.store', $patient) }}">@csrf<div class="card-body row g-2">
                    <div class="col-md-4"><label class="form-label">When</label><input name="seen_at" type="datetime-local" value="{{ now()->format('Y-m-d\TH:i') }}" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">Reason</label><select name="category" class="form-select">@foreach (\App\Models\ClinicVisit::CATEGORIES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <div class="col-md-4"><label class="form-label">Outcome</label><select name="outcome" class="form-select">@foreach (\App\Models\ClinicVisit::OUTCOMES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <div class="col-md-6"><label class="form-label">Complaint</label><textarea name="complaint" rows="2" class="form-control"></textarea></div>
                    <div class="col-md-6"><label class="form-label">Findings</label><textarea name="findings" rows="2" class="form-control"></textarea></div>
                    <div class="col-md-6"><label class="form-label">Diagnosis</label><textarea name="diagnosis" rows="2" class="form-control"></textarea></div>
                    <div class="col-md-6"><label class="form-label">Treatment</label><textarea name="treatment" rows="2" class="form-control"></textarea></div>
                    <div class="col-md-8"><label class="form-label">Referred to (if referred)</label><input name="referred_to" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">Days off (excuse note)</label><input name="rest_days" type="number" min="0" max="60" value="0" class="form-control"></div>
                </div><div class="card-footer text-end"><button class="btn btn-primary">Save visit</button></div></form>
            </div>
            @endcan
            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Visits</h6></div>
                <ul class="list-group list-group-flush">
                    @forelse ($visits as $v)
                        <li class="list-group-item fs-13">
                            <div class="d-flex justify-content-between"><strong>{{ $v->seen_at->format('j M Y, g:ia') }} · {{ \App\Models\ClinicVisit::CATEGORIES[$v->category] }}</strong>
                                <span>{{ \App\Models\ClinicVisit::OUTCOMES[$v->outcome] }}{{ $v->referred_to ? ': ' . $v->referred_to : '' }}</span></div>
                            @foreach (['complaint' => 'Complaint', 'findings' => 'Findings', 'diagnosis' => 'Diagnosis', 'treatment' => 'Treatment'] as $f => $l)@if ($v->$f)<div><span class="text-muted">{{ $l }}:</span> {{ $v->$f }}</div>@endif @endforeach
                            <div class="text-muted">Seen by {{ $v->clinician?->name }}@if ($v->hasExcuse()) · excused {{ $v->excused_from->format('j M') }}–{{ $v->excused_to->format('j M') }} <a href="{{ route('clinic.excuse', $v) }}" target="_blank">Excuse note</a>@endif</div>
                        </li>
                    @empty
                        <li class="list-group-item text-muted">No visits.</li>
                    @endforelse
                </ul>
            </div>
            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Who opened this record</h6></div>
                <ul class="list-group list-group-flush">@foreach ($log as $a)<li class="list-group-item fs-12 d-flex justify-content-between"><span>{{ $a->viewer?->name ?? 'Unknown' }}: {{ str_replace('_', ' ', $a->action) }}{{ $a->detail ? ' (' . $a->detail . ')' : '' }}</span><span class="text-muted">{{ $a->created_at->format('j M Y, g:ia') }}</span></li>@endforeach</ul>
            </div>
        </div>
    </div>
</div></div></div>
@endsection
