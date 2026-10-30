@extends('layouts.master')

@section('content')
@php
    $sel = fn ($name, $opts, $blank = null) => '<select name="' . $name . '" class="form-select">' . ($blank ? '<option value="">' . e($blank) . '</option>' : '')
        . collect($opts)->map(fn ($l, $v) => '<option value="' . e($v) . '"' . ((string) ($input[$name] ?? '') === (string) $v ? ' selected' : '') . '>' . e($l) . '</option>')->implode('') . '</select>';
    $canMove = auth()->user()->can('Change student semester and level');
@endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Move to next semester', 'icon' => 'ri-arrow-right-up-line', 'subtitle' => 'Move a class, or one student, on to the next semester. After the last semester of a level they go up a level, unless their computed result says to repeat. Students advised to withdraw, eligible to graduate or at the final level stay where they are.'])
    @include('academics.partials.alerts')
    @if ($error)<div class="alert alert-warning">{{ $error }}</div>@endif
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    <form method="GET" action="{{ route('tracks.index') }}" class="card"><div class="card-body row g-3">
        <input type="hidden" name="preview" value="1">
        <div class="col-md-3"><label class="form-label">From session</label>{!! $sel('from_session_id', $sessions) !!}</div>
        <div class="col-md-3"><label class="form-label">From semester</label>{!! $sel('from_semester_type_id', $types) !!}</div>
        <div class="col-md-3"><label class="form-label">To session</label>{!! $sel('to_session_id', $sessions) !!}</div>
        <div class="col-md-3"><label class="form-label">To semester</label>{!! $sel('to_semester_type_id', $types) !!}</div>
        <div class="col-md-3"><label class="form-label">Faculty</label>{!! $sel('faculty_id', $faculties, 'All') !!}</div>
        <div class="col-md-3"><label class="form-label">Department</label>{!! $sel('department_id', $departments, 'All') !!}</div>
        <div class="col-md-3"><label class="form-label">Category</label>{!! $sel('study_category_id', $categories, 'All') !!}</div>
        <div class="col-md-3"><label class="form-label">Programme</label>{!! $sel('programme_id', $programmes, 'All') !!}</div>
        <div class="col-md-3"><label class="form-label">Level</label>{!! $sel('level', \App\Support\Levels::options(), 'All') !!}</div>
        <div class="col-md-3"><label class="form-label">One student (matric or JAMB no.)</label><input name="matric" value="{{ $input['matric'] ?? '' }}" class="form-control"></div>
        <div class="col-md-6 d-flex align-items-end"><div class="form-check"><input type="checkbox" class="form-check-input" name="need_results" value="1" id="nr" @checked(! empty($input['need_results']))><label class="form-check-label" for="nr">Only move students whose results for the semester have been computed</label></div></div>
        <div class="col-12"><button class="btn btn-soft-primary"><i class="ri-eye-line me-1"></i>Preview</button></div>
    </div></form>

    @if ($plan)
        @php $moving = $plan->filter->moves(); $held = $plan->reject->moves(); @endphp
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h6 class="card-title mb-0">{{ $moving->count() }} will move · {{ $held->count() }} held back</h6>
                @if ($canMove && $moving->isNotEmpty())
                    <form method="POST" action="{{ route('tracks.store') }}" onsubmit="return confirm('Move {{ $moving->count() }} students now? You can undo it below.')">@csrf
                        @foreach (['from_session_id', 'from_semester_type_id', 'to_session_id', 'to_semester_type_id', 'faculty_id', 'department_id', 'study_category_id', 'programme_id', 'level', 'matric', 'need_results'] as $k)
                            @if (($input[$k] ?? '') !== '')<input type="hidden" name="{{ $k }}" value="{{ $input[$k] }}">@endif
                        @endforeach
                        <button class="btn btn-primary"><i class="ri-arrow-right-up-line me-1"></i>Move {{ $moving->count() }} students</button>
                    </form>
                @endif
            </div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Student</th><th>Programme</th><th>Level now</th><th>Goes to</th><th>Semester result</th></tr></thead>
                <tbody>
                @forelse ($plan as $m)
                    <tr class="{{ $m->moves() ? '' : 'table-warning' }}">
                        <td><a href="{{ route('students.show', $m->studentId) }}">{{ $m->name }}</a><div class="text-muted fs-12">{{ $m->matric }}</div></td>
                        <td class="fs-13">{{ $m->programme }}</td>
                        <td>{{ $m->fromLevel }}</td>
                        <td>@if ($m->moves()){{ $m->toLevel }}@if ($m->toLevel > $m->fromLevel) <span class="badge bg-success-subtle text-success">Up a level</span>@endif @else<span class="text-warning fw-semibold">Stays · {{ $m->held }}</span>@endif</td>
                        <td class="fs-13">{{ $m->remark ?? '—' }}@if ($m->action) <span class="text-muted">({{ \App\Models\ResultKey::ACTIONS[$m->action] ?? $m->action }})</span>@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No enrolled student in that class is in the "from" semester.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </div>
    @endif

    <div class="card">
        <div class="card-header"><h6 class="card-title mb-0">Moves made</h6></div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Reference</th><th>From</th><th>To</th><th>Moved · held</th><th>By</th><th></th></tr></thead>
            <tbody>
            @forelse ($runs as $r)
                <tr>
                    <td class="fw-semibold">{{ $r->key }}</td>
                    <td>{{ $r->fromSession?->name }} {{ $r->fromSemester?->name }}</td>
                    <td>{{ $r->toSession?->name }} {{ $r->toSemester?->name }}</td>
                    <td>{{ $r->moved }} · {{ $r->held }}</td>
                    <td class="fs-13">{{ $r->creator?->name }}<div class="text-muted fs-12">{{ $r->created_at->format('d M Y, H:i') }}</div></td>
                    <td class="text-end">@if ($r->undone_at)<span class="text-muted fs-12">Undone</span>@elseif ($canMove)<form method="POST" action="{{ route('tracks.undo', $r) }}" onsubmit="return confirm('Put these students back where they were?')">@csrf<button class="btn btn-sm btn-link text-danger">Undo</button></form>@endif</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-3">No moves yet.</td></tr>
            @endforelse
            </tbody>
        </table></div>
    </div>
</div></div></div>
@endsection
