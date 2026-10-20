@extends('layouts.master')

@section('content')
@php
    $r = $req ?? null;
    $rows = old('subjects', $r ? $r->subjects->map(fn ($s) => ['subject' => $s->subject, 'min_grade' => $s->min_grade, 'kind' => $s->kind])->all()
        : [['subject' => 'English Language', 'min_grade' => 'C6', 'kind' => 'required'], ['subject' => 'Mathematics', 'min_grade' => 'C6', 'kind' => 'required']]);
    $rows = array_pad($rows, max(10, count($rows) + 3), ['subject' => '', 'min_grade' => 'C6', 'kind' => 'required']);
    $name = $programme ? $programme->name : $department->name;
@endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'O Level requirements', 'icon' => 'ri-list-settings-line', 'subtitle' => 'Subjects and grades a department needs before its students may register courses', 'back' => [route('olevel.index'), 'Verification']])
    @include('academics.partials.alerts')

    <div class="row">
        <div class="col-xl-3">
            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Departments</h6></div>
                <div class="list-group list-group-flush" style="max-height:560px;overflow:auto">
                    @foreach ($departments as $d)
                        @php $has = $all->first(fn ($x) => $x->scope_type === 'department' && $x->scope_id === $d->id); @endphp
                        <a href="{{ route('olevel.requirements', ['scope' => 'department', 'id' => $d->id]) }}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center {{ ! $programme && $department->id === $d->id ? 'active' : '' }}">
                            <span class="fs-13">{{ $d->name }}<div class="fs-11 {{ ! $programme && $department->id === $d->id ? '' : 'text-muted' }}">{{ $d->faculty?->name }}</div></span>
                            @if ($has)<i class="ri-checkbox-circle-fill {{ $has->is_active ? 'text-success' : 'text-muted' }}"></i>@endif
                        </a>
                    @endforeach
                </div></div>
            @if ($canSettings)
                <form method="POST" action="{{ route('olevel.settings') }}" class="card">@csrf @method('PUT')
                    <div class="card-header"><h6 class="card-title mb-0">For every department</h6></div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="ai_reading" value="1" id="s1" @checked($settings['ai_reading'])><label class="form-check-label fs-13" for="s1">Read uploaded results with the AI service</label><div class="fs-12 text-muted">Uses the service set up under Mobile App settings. Staff still confirm every sitting.</div></div>
                        <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="require_document" value="1" id="s2" @checked($settings['require_document'])><label class="form-check-label fs-13" for="s2">Students must upload the result to submit</label></div>
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="block_registration" value="1" id="s3" @checked($settings['block_registration'])><label class="form-check-label fs-13" for="s3">Course registration waits for confirmed results that meet the requirement</label></div>
                    </div>
                    <div class="card-footer text-end"><button class="btn btn-sm btn-primary">Save</button></div>
                </form>
            @endif
        </div>

        <div class="col-xl-9">
            <div class="d-flex flex-wrap gap-1 mb-2">
                <a href="{{ route('olevel.requirements', ['scope' => 'department', 'id' => $department->id]) }}" class="btn btn-sm {{ $programme ? 'btn-light' : 'btn-primary' }}">{{ $department->name }} (department)</a>
                @foreach ($programmes as $p)
                    <a href="{{ route('olevel.requirements', ['scope' => 'programme', 'id' => $p->id]) }}" class="btn btn-sm {{ $programme?->id === $p->id ? 'btn-primary' : 'btn-light' }}">{{ $p->name }}@if ($all->first(fn ($x) => $x->scope_type === 'programme' && $x->scope_id === $p->id)) <i class="ri-checkbox-circle-fill"></i>@endif</a>
                @endforeach
            </div>
            @if ($programme && ! $r)
                <div class="alert alert-info fs-13">{{ $programme->name }} follows {{ $department->name }}'s requirement{{ $inherited ? ': ' . $inherited->summary() : ' (none set yet)' }}. Save below to give the programme its own.</div>
            @endif

            <form method="POST" action="{{ route('olevel.requirements.save') }}" class="card">
                @csrf @method('PUT')
                <input type="hidden" name="scope" value="{{ $type }}"><input type="hidden" name="id" value="{{ $id }}">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div><h6 class="card-title mb-0">{{ $name }}</h6>@if ($r)<div class="fs-12 text-muted">{{ $r->summary() }} · last changed by {{ $r->editor?->name ?? '—' }} {{ $r->updated_at?->diffForHumans() }}</div>@endif</div>
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="act" @checked(old('is_active', $r?->is_active ?? true)) @disabled(! $canEdit)><label class="form-check-label" for="act">In force</label></div>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-sm-3"><label class="form-label mb-1">Credit passes needed</label><input type="number" name="min_credits" min="0" max="9" value="{{ old('min_credits', $r?->min_credits ?? 5) }}" class="form-control form-control-sm" @disabled(! $canEdit)><div class="fs-11 text-muted">C6 or better, any subjects</div></div>
                        <div class="col-sm-3"><label class="form-label mb-1">Sittings that may be combined</label><select name="max_sittings" class="form-select form-select-sm" @disabled(! $canEdit)>@foreach ([1, 2, 3] as $n)<option value="{{ $n }}" @selected((int) old('max_sittings', $r?->max_sittings ?? 2) === $n)>{{ $n }}</option>@endforeach</select></div>
                        <div class="col-sm-3"><label class="form-label mb-1">"One of" subjects needed</label><input type="number" name="optional_needed" min="0" max="9" value="{{ old('optional_needed', $r?->optional_needed ?? 0) }}" class="form-control form-control-sm @error('optional_needed') is-invalid @enderror" @disabled(! $canEdit)><div class="fs-11 text-muted">From the subjects marked "One of"</div></div>
                        <div class="col-sm-3"><label class="form-label mb-1">Checked</label><select name="applies_to" class="form-select form-select-sm" @disabled(! $canEdit)>@foreach (\App\Models\OlevelRequirement::APPLIES as $v => $l)<option value="{{ $v }}" @selected(old('applies_to', $r?->applies_to ?? 'entry') === $v)>{{ $l }}</option>@endforeach</select></div>
                    </div>
                    <datalist id="olevel-subjects">@foreach ($subjects as $s)<option value="{{ $s }}">@endforeach</datalist>
                    <table class="table table-sm align-middle">
                        <thead class="table-light"><tr><th>Subject</th><th style="width:18%">Lowest grade</th><th style="width:30%">Kind</th></tr></thead>
                        <tbody>
                        @foreach ($rows as $i => $row)
                            <tr><td><input name="subjects[{{ $i }}][subject]" value="{{ $row['subject'] }}" list="olevel-subjects" class="form-control form-control-sm" @disabled(! $canEdit)></td>
                                <td><select name="subjects[{{ $i }}][min_grade]" class="form-select form-select-sm" @disabled(! $canEdit)>@foreach ($grades as $g)<option @selected(($row['min_grade'] ?? 'C6') === $g)>{{ $g }}</option>@endforeach</select></td>
                                <td><select name="subjects[{{ $i }}][kind]" class="form-select form-select-sm" @disabled(! $canEdit)>@foreach (\App\Models\OlevelRequirementSubject::KINDS as $v => $l)<option value="{{ $v }}" @selected(($row['kind'] ?? 'required') === $v)>{{ $l }}</option>@endforeach</select></td></tr>
                        @endforeach
                        </tbody>
                    </table>
                    <textarea name="note" rows="2" class="form-control form-control-sm" placeholder="Note shown to students (optional)" @disabled(! $canEdit)>{{ old('note', $r?->note) }}</textarea>
                </div>
                @if ($canEdit)
                    <div class="card-footer d-flex justify-content-between">
                        @if ($r)<button class="btn btn-sm btn-link text-danger" type="submit" name="remove" value="1" data-confirm="Remove this requirement?">{{ $programme ? 'Follow the department instead' : 'Remove requirement' }}</button>@else<span></span>@endif
                        <button class="btn btn-primary btn-sm" type="submit"><i class="ri-save-line me-1"></i>Save requirement</button>
                    </div>
                @endif
            </form>
        </div>
    </div>
</div></div></div>
@endsection
