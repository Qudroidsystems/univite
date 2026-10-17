@extends('layouts.master')

@section('content')
@php
    use App\Services\Results\MasterSheets;
    $params = $q->params();
    $person = function ($u) {
        if (! $u) return '<span class="text-muted">—</span>';
        $img = $u->photoUrl() ? '<img src="' . e($u->photoUrl()) . '" class="rounded-circle object-fit-cover me-2" style="width:26px;height:26px" alt="">'
            : '<span class="avatar-xs me-2 d-inline-block"><span class="avatar-title rounded-circle bg-primary-subtle text-primary fs-11">' . e(mb_strtoupper(mb_substr($u->name, 0, 1))) . '</span></span>';
        return '<div class="d-flex align-items-center">' . $img . '<div class="lh-sm"><div class="fw-medium">' . e($u->name) . '</div><div class="fs-11 text-muted">' . e($u->staffProfile?->staff_number ?? '') . '</div></div></div>';
    };
    $when = fn ($t) => $t ? '<div class="lh-sm"><div>' . e($t->format('l')) . '</div><div class="fs-12 text-muted">' . e($t->format('j M Y, g:i a')) . '</div></div>' : '<span class="text-muted">—</span>';
@endphp
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0"><i class="ri-calculator-line me-1 align-middle"></i>Compute Results</h4>
                @can('View grade bands')
                    <div class="page-title-right"><a href="{{ route('academics.grade-bands.index') }}" class="btn btn-sm btn-soft-secondary me-1">Key to Grade</a>
                        @can('View result keys')<a href="{{ route('academics.result-keys.index') }}" class="btn btn-sm btn-soft-secondary">Key to Result</a>@endcan</div>
                @endcan
            </div>
            @include('academics.partials.alerts')
            <p class="text-muted">When the lecturers have submitted their scores, choose the department, student category, level, session and semester (and a course to look at one course's scores), check who submitted what and when, then click <strong>Compute</strong>. Computing grades the submitted scores by the key to grade, works out every student's GPA and CGPA, and gives the remarks from the key to result. Then download the master marks sheet.</p>

            <form method="GET" class="card card-body mb-3">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3"><label class="form-label">Department</label>
                        <select name="department_id" class="form-select" onchange="this.form.submit()" required>
                            <option value="">Choose…</option>
                            @foreach ($departments as $d)<option value="{{ $d->id }}" @selected($q->department?->id === $d->id)>{{ $d->name }}</option>@endforeach
                        </select></div>
                    <div class="col-md-2"><label class="form-label">Student category</label>
                        <select name="study_category_id" class="form-select"><option value="">All categories</option>
                            @foreach ($categories as $c)<option value="{{ $c->id }}" @selected($q->category?->id === $c->id)>{{ $c->name }}</option>@endforeach
                        </select></div>
                    <div class="col-md-2"><label class="form-label">Course</label>
                        <select name="course_id" class="form-select"><option value="">All courses</option>
                            @foreach ($courses as $c)<option value="{{ $c->id }}" @selected($q->course?->id === $c->id)>{{ $c->code }} {{ $c->title }}</option>@endforeach
                        </select></div>
                    <div class="col-md-1"><label class="form-label">Level</label>
                        <select name="level" class="form-select" required><option value="">…</option>
                            @foreach ($levels as $v => $l)<option value="{{ $v }}" @selected($q->level === (int) $v)>{{ $v }}</option>@endforeach
                        </select></div>
                    <div class="col-md-2"><label class="form-label">Session</label>
                        <select name="session_id" class="form-select">@foreach ($sessions as $s)<option value="{{ $s->id }}" @selected($q->session?->id === $s->id)>{{ $s->name }}</option>@endforeach</select></div>
                    <div class="col-md-1"><label class="form-label">Semester</label>
                        <select name="semester_type_id" class="form-select">@foreach ($types as $t)<option value="{{ $t->id }}" @selected($q->semesterType?->id === $t->id)>{{ $t->name }}</option>@endforeach</select></div>
                    <div class="col-md-1"><button class="btn btn-primary w-100"><i class="ri-search-line me-1"></i>Show</button></div>
                </div>
            </form>

            @if ($overview)
                @php($last = $overview->lastComputed())
                <div class="card">
                    <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
                        <div>
                            <h5 class="card-title mb-0">{{ $q->title() }}</h5>
                            <div class="text-muted fs-13">{{ $overview->students->count() }} students · {{ $overview->courses->count() }} courses
                                @if ($last) · last computed {{ $last->computed_at->format('l j M Y, g:i a') }} by {{ $last->computedBy?->name }} @else · not computed yet @endif</div>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('results.compute.run') }}" class="d-inline"
                                  onsubmit="return confirm('Compute results for {{ e($q->title()) }}? Submitted scores are graded by the key to grade and every student\'s GPA, CGPA and remarks are worked out again.')">
                                @csrf
                                @foreach ($params as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                                <button class="btn btn-success"><i class="ri-calculator-line me-1"></i>Compute</button>
                            </form>
                            @if ($last)
                                <a href="{{ route('results.compute.sheet', ['format' => 'excel'] + $params) }}" class="btn btn-soft-success" data-no-ajax><i class="ri-file-excel-2-line me-1"></i>Master sheet (Excel)</a>
                                @if ($pdf)<a href="{{ route('results.compute.sheet', ['format' => 'pdf'] + $params) }}" class="btn btn-soft-danger" data-no-ajax><i class="ri-file-pdf-line me-1"></i>PDF</a>@endif
                                <a href="{{ route('results.compute.sheet', ['format' => 'print'] + $params) }}" class="btn btn-soft-secondary" target="_blank" data-no-ajax><i class="ri-printer-line me-1"></i>Print</a>
                            @endif
                        </div>
                    </div>
                    @if ($overview->pending()->isNotEmpty())
                        <div class="alert alert-warning m-3 mb-0">Not submitted yet: {{ $overview->pending()->map(fn ($c) => $c->course->code)->implode(', ') }}. You can compute now; students missing those scores show INC until you compute again after they are submitted.</div>
                    @endif

                    <div class="card-body p-0">
                        <h6 class="px-3 pt-3 text-uppercase text-muted fs-12">Courses and who submitted them</h6>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light"><tr><th>Course</th><th>Lecturer</th><th>Status</th><th>Submitted by</th><th>Submitted on</th><th class="text-end">Students</th><th class="text-end">Scored</th><th class="text-end">Passed</th><th class="text-end">Failed</th><th class="text-end">Average</th></tr></thead>
                                <tbody>
                                    @forelse ($overview->courses as $c)
                                        <tr @class(['table-warning' => ! $c->ready()])>
                                            <td><span class="fw-semibold">{{ $c->course->code }}</span><div class="fs-12 text-muted">{{ $c->course->title }} · {{ $c->course->credit_units }} units</div><x-course-tag :course="$c->course" /></td>
                                            <td>{!! $c->lecturers->isEmpty() ? '<span class="text-muted">Not defined</span>' : $c->lecturers->map($person)->implode('') !!}</td>
                                            <td>@if ($c->sheet)<span class="badge {{ \App\Models\ResultSheet::BADGES[$c->sheet->status] ?? 'bg-light text-body' }}">{{ $c->statusLabel() }}</span>@else<span class="text-muted">{{ $c->statusLabel() }}</span>@endif</td>
                                            <td>{!! $person($c->submittedBy) !!}</td>
                                            <td>{!! $when($c->sheet?->submitted_at) !!}</td>
                                            <td class="text-end">{{ $c->students }}</td><td class="text-end">{{ $c->scored }}</td>
                                            <td class="text-end text-success">{{ $c->passed }}</td><td class="text-end text-danger">{{ $c->failed }}</td>
                                            <td class="text-end">{{ $c->average ?? '—' }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="10" class="text-center text-muted py-4">No approved course registrations for this choice.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        <h6 class="px-3 pt-4 text-uppercase text-muted fs-12">Students' scores {{ $q->course ? 'in ' . $q->course->code : '' }}</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="table-light"><tr><th>Matric number</th><th>Student</th><th>Course</th><th class="text-end">CA</th><th class="text-end">Exam</th><th class="text-end">Total</th><th class="text-center">Grade</th><th>Status</th><th>Last changed</th></tr></thead>
                                <tbody>
                                    @forelse ($overview->results as $r)
                                        <tr>
                                            <td class="fw-medium">{{ $r->student->matric_number }}</td>
                                            <td>{{ $r->student->full_name }}</td>
                                            <td>{{ $r->course->code }}</td>
                                            <td class="text-end">{{ $r->ca === null ? '—' : MasterSheets::score($r->ca) }}</td>
                                            <td class="text-end">{{ $r->exam === null ? '—' : MasterSheets::score($r->exam) }}</td>
                                            <td class="text-end fw-semibold">{{ $r->total === null ? '—' : MasterSheets::score($r->total) }}</td>
                                            <td class="text-center"><span @class(['badge', 'bg-success-subtle text-success' => $r->is_pass, 'bg-danger-subtle text-danger' => $r->is_pass === false, 'bg-light text-body' => $r->is_pass === null])>{{ $r->grade ?? '—' }}</span></td>
                                            <td><span class="badge {{ \App\Models\ResultSheet::BADGES[$r->status] ?? 'bg-light text-body' }}">{{ \App\Models\ResultSheet::STATUSES[$r->status] ?? ucfirst($r->status) }}</span></td>
                                            <td><x-last-change :record="$r" :among="$overview->results" /></td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="9" class="text-center text-muted py-4">No scores yet.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        @if ($overview->computed->isNotEmpty())
                            <h6 class="px-3 pt-4 text-uppercase text-muted fs-12">Computed results</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover align-middle mb-0">
                                    <thead class="table-light"><tr><th>Matric number</th><th>Student</th><th class="text-center">Sem rmk</th><th>Outstanding</th><th class="text-end">TCP</th><th class="text-end">TNU</th><th class="text-end">GPA</th><th class="text-end">CTCP</th><th class="text-end">CTNU</th><th class="text-end">CGPA</th><th class="text-center">Cum rmk</th><th>Rank / action</th></tr></thead>
                                    <tbody>
                                        @foreach ($overview->students as $s)
                                            @php($c = $overview->computed->get($s->id))
                                            @continue(! $c)
                                            <tr>
                                                <td class="fw-medium">{{ $s->matric_number }}</td><td>{{ $s->full_name }}</td>
                                                <td class="text-center"><span @class(['badge', 'bg-success' => $c->sem_remark === 'PASS', 'bg-danger' => in_array($c->sem_remark, ['REP', 'INC']), 'bg-warning' => $c->sem_remark === 'NRI'])>{{ $c->sem_remark }}</span></td>
                                                <td>{{ collect($c->outstanding)->pluck('code')->implode(', ') ?: '—' }}</td>
                                                <td class="text-end">{{ MasterSheets::score($c->present_tcp) }}</td><td class="text-end">{{ $c->present_tnu }}</td><td class="text-end">{{ number_format($c->present_gpa, 2) }}</td>
                                                <td class="text-end">{{ MasterSheets::score($c->cumulative_tcp) }}</td><td class="text-end">{{ $c->cumulative_tnu }}</td><td class="text-end fw-semibold">{{ number_format($c->cgpa, 2) }}</td>
                                                <td class="text-center fw-semibold">{{ $c->cum_remark ?? '—' }}</td>
                                                <td class="fs-12">{{ $c->rank ?? '—' }}@if ($c->resultKey)<div class="text-muted">{{ $c->resultKey->actionLabel() }}</div>@endif</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            @elseif ($departments->isEmpty())
                <div class="alert alert-info">You are not set to compute results for any department.</div>
            @endif
        </div>
    </div>
</div>
@endsection
