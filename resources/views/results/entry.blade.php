@extends('layouts.master')

@section('content')
@php
    $params = $q->params();
    $badge = $sheet ? \App\Models\ResultSheet::BADGES[$sheet->status] : '';
@endphp
<style>
    .uv-entry-tabs .nav-link { border-radius: 2rem; padding: .45rem 1.1rem; transition: background .2s ease, color .2s ease; }
    .uv-entry-tabs .nav-link.active { box-shadow: 0 4px 14px rgba(64,81,137,.25); }
    .uv-entry-hero { animation: uvHeroIn .35s ease both; }
    @keyframes uvHeroIn { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: none; } }
</style>
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="page-title-box d-flex flex-wrap gap-2 align-items-center justify-content-between">
                <h4 class="mb-0"><i class="ri-edit-box-line me-1 align-middle"></i>Enter Scores</h4>
                <ul class="nav nav-pills uv-entry-tabs">
                    @foreach (\App\Data\Results\ScoreEntryQuery::MODES as $mode => $label)
                        <li class="nav-item"><a class="nav-link {{ $q->mode === $mode ? 'active' : '' }}" href="{{ route('results.entry', $q->params(['mode' => $mode])) }}"><i class="{{ $mode === 'student' ? 'ri-group-line' : 'ri-book-2-line' }} me-1"></i>{{ $label }}</a></li>
                    @endforeach
                </ul>
            </div>
            @include('academics.partials.alerts')
            <p class="text-muted fs-13">
                @if ($q->byStudent())
                    Choose a class (department, student category and level) and one of its courses: the table lists that class's students on the course.
                @else
                    Choose a course: the table lists every student taking it, whatever their department or level. Department, category and level only shorten the course list.
                @endif
            </p>

            <form method="GET" action="{{ route('results.entry') }}" class="card">
                <input type="hidden" name="mode" value="{{ $q->mode }}">
                <div class="card-body row g-2 align-items-end">
                    <div class="col-sm-6 col-lg-3"><label class="form-label mb-1">Department</label>
                        <select name="department_id" class="form-select" data-lookup="departments" @required($q->byStudent())><option value="">{{ $q->byStudent() ? 'Select…' : 'Any department' }}</option>@foreach ($departments as $id => $n)<option value="{{ $id }}" @selected($q->department?->id === $id)>{{ $n }}</option>@endforeach</select></div>
                    <div class="col-sm-6 col-lg-2"><label class="form-label mb-1">Student category</label>
                        <select name="study_category_id" class="form-select" data-lookup="study-categories" @required($q->byStudent())><option value="">{{ $q->byStudent() ? 'Select…' : 'Any category' }}</option>@foreach ($categories as $id => $n)<option value="{{ $id }}" @selected($q->category?->id === $id)>{{ $n }}</option>@endforeach</select></div>
                    <div class="col-sm-4 col-lg-1"><label class="form-label mb-1">Level</label>
                        <select name="level" class="form-select" @required($q->byStudent())><option value="">{{ $q->byStudent() ? 'Select…' : 'Any' }}</option>@foreach ($levels as $v => $l)<option value="{{ $v }}" @selected($q->level === (int) $v)>{{ $l }}</option>@endforeach</select></div>
                    <div class="col-sm-4 col-lg-2"><label class="form-label mb-1">Session</label>
                        <select name="session_id" class="form-select" data-lookup="sessions">@foreach ($sessions as $id => $n)<option value="{{ $id }}" @selected($q->session?->id === $id)>{{ $n }}</option>@endforeach</select></div>
                    <div class="col-sm-4 col-lg-2"><label class="form-label mb-1">Semester</label>
                        <select name="semester_type_id" class="form-select" data-lookup="semester-types">@foreach ($types as $id => $n)<option value="{{ $id }}" @selected($q->semesterType?->id === $id)>{{ $n }}</option>@endforeach</select></div>
                    <div class="col-lg-2 d-grid"><button class="btn btn-soft-primary" type="submit"><i class="ri-filter-3-line me-1"></i>Find courses</button></div>
                    <div class="col-lg-10"><label class="form-label mb-1">Course</label>
                        <select name="course_id" class="form-select" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
                            <option value="">{{ $courses->isEmpty() ? 'No course matches. Change the choices above.' : 'Select a course (' . $courses->count() . ')…' }}</option>
                            @foreach ($courses as $c)<option value="{{ $c->id }}" @selected($q->course?->id === $c->id)>{{ $c->code }} · {{ $c->title }} ({{ $c->credit_units }} units)</option>@endforeach
                        </select></div>
                    <div class="col-lg-2 d-grid"><button class="btn btn-primary" type="submit"><i class="ri-table-line me-1"></i>Show students</button></div>
                </div>
            </form>

            @if ($sheet)
                <div class="card uv-entry-hero border-0 bg-primary-subtle"><div class="card-body d-flex flex-wrap gap-3 align-items-center justify-content-between">
                    <div>
                        <h5 class="mb-1">{{ $sheet->course->code }} · {{ $sheet->course->title }}
                            <span class="badge {{ $badge }} fs-11 align-middle">{{ \App\Models\ResultSheet::STATUSES[$sheet->status] }}{{ $sheet->stage ? ' · ' . $sheet->stage->name : '' }}</span></h5>
                        <div class="fs-13 text-muted">{{ $q->session->name }} · {{ $q->semesterType->name }}
                            · {{ $rows->count() }} {{ Str::plural('student', $rows->count()) }}{{ $q->byStudent() ? ' in ' . $q->department->name . ', ' . $q->category->name . ', ' . $q->level . 'L' : ' on the course' }}
                            @if ($scheme) · {{ $scheme->name }}: {{ $scheme->summary() }}@endif</div>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        @include('results.partials.download-menu', ['sheet' => $sheet, 'label' => 'Download'])
                        <a href="{{ route('results.sheets.show', $sheet->id) }}" class="btn btn-light"><i class="ri-external-link-line me-1"></i>Open the full sheet</a>
                    </div>
                </div></div>

                @unless ($edit)
                    <div class="alert alert-info fs-13"><i class="ri-lock-line me-1"></i>This sheet has been submitted, so its scores can no longer be changed here.</div>
                @endunless

                <form method="POST" action="{{ route('results.sheets.save', $sheet->id) }}">
                    @csrf
                    @include('results.partials.score-table', ['rows' => $rows, 'scheme' => $scheme, 'edit' => $edit, 'caMax' => $caMax, 'examMax' => $examMax, 'showClass' => ! $q->byStudent(), 'saveLabel' => 'Save ' . $rows->count() . ' ' . Str::plural('student', $rows->count())])
                </form>

                @if ($edit && $rows->isNotEmpty())
                    <form method="POST" action="{{ route('results.sheets.submit', $sheet->id) }}" class="d-flex flex-wrap gap-2 align-items-center mb-3" data-confirm="Submit the whole score sheet for {{ $sheet->course->code }} for approval? You will not be able to change it unless it is returned to you.">
                        @csrf<button class="btn btn-outline-primary" type="submit"><i class="ri-send-plane-line me-1"></i>Submit {{ $sheet->course->code }} for approval</button>
                        <span class="text-muted fs-13">{{ $missing ? $missing . ' student(s) on the whole course still need scores.' : 'Every student on the course has scores.' }}</span>
                    </form>
                @endif
            @elseif ($q->course)
                <div class="text-center text-muted py-5"><i class="ri-error-warning-line fs-2 d-block mb-1"></i>
                    @if ($q->missing())Choose the {{ implode(', ', $q->missing()) }} as well.@else{{ $q->course->code }} is not one of your courses for {{ $q->session?->name }} {{ $q->semesterType?->name }}, or it does not match the choices above.@endif
                </div>
            @else
                <div class="text-center text-muted py-5"><i class="ri-cursor-line fs-2 d-block mb-1"></i>Choose a course to start entering scores.</div>
            @endif
        </div>
    </div>
</div>
@endsection
