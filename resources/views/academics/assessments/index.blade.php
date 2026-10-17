@extends('layouts.master')

@section('content')
@php
    $u = auth()->user();
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
@endphp
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0"><i class="ri-survey-line me-1 align-middle"></i>Assessment Schemes</h4>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    @include('setup.partials.links', ['sections' => ['assessments'], 'copy' => ['assessments'], 'session' => null])
                    @can('Create assessment schemes')
                        <a href="{{ route('academics.assessments.create') }}" class="btn btn-primary"><i class="ri-add-line me-1"></i>New scheme</a>
                    @endcan
                </div>
            </div>
            @include('academics.partials.alerts')
            <p class="text-muted">A scheme lists the assessments a course is marked on (for example Test 1, Assignment, Practical and the exam), each out of a set mark and counting towards CA or exam, adding up to 100.
                Assign a scheme to the whole university, a faculty, a department or a single course, for every session or one session. The most specific assignment wins.
                Lecturers then enter a mark for each assessment, and the marks add up to the CA and exam that go through approval as before.
                A score sheet keeps its scheme once a mark is entered. Courses with no scheme use the CA / exam split in <a href="{{ route('results.settings') }}">Result Settings</a>.</p>

            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">Schemes</h6></div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light"><tr><th>Scheme</th><th>Assessments</th><th>CA / Exam</th><th>In use</th><th>Status</th><th style="width:1%" class="text-nowrap">Last changed by</th><th class="text-end"></th></tr></thead>
                        <tbody>
                        @forelse ($schemes as $s)
                            <tr>
                                <td><span class="fw-semibold">{{ $s->name }}</span>@if ($s->description)<div class="text-muted fs-12">{{ $s->description }}</div>@endif</td>
                                <td>
                                    @foreach ($s->items as $i)
                                        <span class="badge {{ $i->component === 'exam' ? 'bg-primary-subtle text-primary' : 'bg-info-subtle text-info' }} me-1 mb-1" title="{{ \App\Models\AssessmentItem::COMPONENTS[$i->component] }}">{{ $i->name }} · {{ $i->maxLabel() }}</span>
                                    @endforeach
                                </td>
                                <td class="text-nowrap">{{ $fmt($s->caMax()) }} / {{ $fmt($s->examMax()) }}</td>
                                <td class="text-nowrap fs-13">{{ $s->assignments_count }} assignment(s)<br><span class="text-muted">{{ $s->sheets_count }} score sheet(s)</span></td>
                                <td>{!! $s->is_active ? '<span class="badge bg-success-subtle text-success">On</span>' : '<span class="badge bg-secondary-subtle text-secondary">Off</span>' !!}</td>
                                <td><x-last-change :record="$s" :among="$schemes" /></td>
                                <td class="text-end text-nowrap">
                                    @can('Edit assessment schemes')<a href="{{ route('academics.assessments.edit', $s->id) }}" class="btn btn-sm btn-soft-primary">Edit</a>@endcan
                                    @can('Create assessment schemes')
                                        <form method="POST" action="{{ route('academics.assessments.duplicate', $s->id) }}" class="d-inline">@csrf<button class="btn btn-sm btn-light" type="submit">Copy</button></form>
                                    @endcan
                                    @can('Delete assessment schemes')
                                        <form method="POST" action="{{ route('academics.assessments.destroy', $s->id) }}" class="d-inline" data-confirm="Delete {{ $s->name }}?">@csrf @method('DELETE')<button class="btn btn-sm btn-soft-danger" type="submit">Delete</button></form>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-4">No schemes yet. Every course uses the CA / exam split from Result Settings.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="row">
                <div class="col-xl-8">
                    <div class="card">
                        <div class="card-header"><h6 class="card-title mb-0">Where each scheme applies</h6></div>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="table-light"><tr><th>Applies to</th><th>Session</th><th>Scheme</th><th style="width:1%" class="text-nowrap">Last changed by</th><th class="text-end"></th></tr></thead>
                                <tbody>
                                @forelse ($assignments as $a)
                                    <tr>
                                        <td><span class="badge bg-light text-body me-1">{{ \App\Models\AssessmentAssignment::SCOPES[$a->scope_type] }}</span>{{ $a->scope_type === 'university' ? '' : $a->scopeLabel() }}</td>
                                        <td>{{ $a->session?->name ?? 'Every session' }}</td>
                                        <td>{{ $a->scheme->name }}@unless ($a->scheme->is_active)<span class="badge bg-secondary-subtle text-secondary ms-1">Off, ignored</span>@endunless</td>
                                        <td><x-last-change :record="$a" :among="$assignments" /></td>
                                        <td class="text-end">
                                            @can('Assign assessment schemes')
                                                <form method="POST" action="{{ route('academics.assessments.unassign', $a->id) }}" data-confirm="Remove this assignment?">@csrf @method('DELETE')<button class="btn btn-sm btn-soft-danger" type="submit">Remove</button></form>
                                            @endcan
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted py-4">No scheme is assigned yet.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-xl-4">
                    @can('Assign assessment schemes')
                        <form method="POST" action="{{ route('academics.assessments.assign') }}" class="card" id="assign-form">
                            @csrf
                            <div class="card-header"><h6 class="card-title mb-0">Assign a scheme</h6></div>
                            <div class="card-body">
                                <div class="mb-2"><label class="form-label mb-1">Scheme</label>
                                    <select name="assessment_scheme_id" class="form-select" required>
                                        <option value="">Choose…</option>
                                        @foreach ($schemes->where('is_active', true) as $s)<option value="{{ $s->id }}" @selected((int) old('assessment_scheme_id') === $s->id)>{{ $s->name }}</option>@endforeach
                                    </select></div>
                                <div class="mb-2"><label class="form-label mb-1">Applies to</label>
                                    <select name="scope_type" class="form-select" data-scope-switch>
                                        @foreach (\App\Models\AssessmentAssignment::SCOPES as $v => $l)<option value="{{ $v }}" @selected(old('scope_type', 'university') === $v)>{{ $l }}</option>@endforeach
                                    </select></div>
                                <div class="mb-2" data-scope="faculty"><label class="form-label mb-1">Faculty</label>
                                    <select name="faculty_id" class="form-select" data-lookup="faculties"><option value="">Choose…</option>@foreach ($faculties as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select></div>
                                <div class="mb-2" data-scope="department"><label class="form-label mb-1">Department</label>
                                    <select name="department_id" class="form-select" data-lookup="departments"><option value="">Choose…</option>@foreach ($departments as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select></div>
                                <div class="mb-2" data-scope="course"><label class="form-label mb-1">Course</label>
                                    <select name="course_id" class="form-select" data-lookup="courses"><option value="">Choose…</option>@foreach ($courses as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select></div>
                                <div class="mb-3"><label class="form-label mb-1">Session</label>
                                    <select name="academic_session_id" class="form-select" data-lookup="sessions"><option value="">Every session</option>@foreach ($sessions as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select></div>
                                <button class="btn btn-primary w-100" type="submit"><i class="ri-links-line me-1"></i>Assign</button>
                                <div class="text-muted fs-12 mt-2">Assigning to a place that already has a scheme for the same session replaces it. Score sheets that already have marks keep their scheme.</div>
                            </div>
                        </form>
                    @endcan

                    <form method="GET" class="card">
                        <div class="card-header"><h6 class="card-title mb-0">Which scheme does a course use?</h6></div>
                        <div class="card-body">
                            <div class="mb-2"><select name="course_id" class="form-select" data-lookup="courses" required><option value="">Choose a course…</option>@foreach ($courses as $v => $l)<option value="{{ $v }}" @selected((int) request('course_id') === $v)>{{ $l }}</option>@endforeach</select></div>
                            <div class="mb-2"><select name="session_id" class="form-select"><option value="">Current session</option>@foreach ($sessions as $v => $l)<option value="{{ $v }}" @selected((int) request('session_id') === $v)>{{ $l }}</option>@endforeach</select></div>
                            <button class="btn btn-soft-primary" type="submit">Check</button>
                            @if ($check)
                                <div class="mt-3 fs-13">
                                    <strong>{{ $check['course']->code }}</strong>:
                                    @if ($check['scheme'])
                                        {{ $check['scheme']->name }} ({{ $check['scheme']->summary() }}),
                                        from {{ strtolower(\App\Models\AssessmentAssignment::SCOPES[$check['from']->scope_type]) }}{{ $check['from']->scope_type === 'university' ? '' : ' ' . $check['from']->scopeLabel() }}
                                        · {{ $check['from']->session?->name ?? 'every session' }}.
                                    @else
                                        no scheme applies, so it uses the CA / exam split from Result Settings.
                                    @endif
                                </div>
                            @endif
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var form = document.getElementById('assign-form');
    if (!form) return;
    var sw = form.querySelector('[data-scope-switch]');
    function show() {
        form.querySelectorAll('[data-scope]').forEach(function (el) {
            var on = el.getAttribute('data-scope') === sw.value;
            el.style.display = on ? '' : 'none';
            el.querySelector('select').required = on;
        });
    }
    sw.addEventListener('change', show);
    show();
})();
</script>
@endpush
