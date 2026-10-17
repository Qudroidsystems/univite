@extends('layouts.master')

@section('content')
@php
    $params = $q->params();
    $score = fn ($t) => \App\Services\Results\MasterSheets::score($t);
    $badges = ['published' => 'bg-success-subtle text-success', 'approved' => 'bg-info-subtle text-info', 'submitted' => 'bg-warning-subtle text-warning', 'draft' => 'bg-secondary-subtle text-secondary'];
@endphp
<style>
    @media print { @page { size: A4 landscape; margin: 8mm; } .uv-master { font-size: 9px; } .uv-master th, .uv-master td { padding: 2px 3px !important; } }
    .uv-master th, .uv-master td { white-space: nowrap; }
    .uv-master .uv-group { border-left: 2px solid var(--tb-border-color-translucent, #ccc) !important; }
</style>
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="page-title-box d-print-none"><h4 class="mb-0">Master sheet</h4></div>
            @include('academics.partials.alerts')

            <form method="GET" action="{{ route('results.broadsheet') }}" class="card d-print-none"><div class="card-body d-flex flex-wrap gap-2 align-items-end">
                <div><label class="form-label mb-1">Programme</label><select name="programme_id" class="form-select" required><option value="">Select…</option>@foreach ($programmes as $id => $n)<option value="{{ $id }}" @selected($q->programme?->id === $id)>{{ $n }}</option>@endforeach</select></div>
                <div><label class="form-label mb-1">Level</label><select name="level" class="form-select" required><option value="">Select…</option>@foreach ($levels as $v => $l)<option value="{{ $v }}" @selected($q->level === (int) $v)>{{ $l }}</option>@endforeach</select></div>
                <div><label class="form-label mb-1">Session</label><select name="session_id" data-lookup="sessions" class="form-select">@foreach ($sessions as $id => $n)<option value="{{ $id }}" @selected($q->session?->id === $id)>{{ $n }}</option>@endforeach</select></div>
                <div><label class="form-label mb-1">Semester</label><select name="semester_type_id" data-lookup="semester-types" class="form-select">@foreach ($types as $id => $n)<option value="{{ $id }}" @selected($q->semesterType?->id === $id)>{{ $n }}</option>@endforeach</select></div>
                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="published_only" value="1" id="pubOnly" @checked(! $q->provisional)><label class="form-check-label" for="pubOnly">Published results only</label></div>
                <button class="btn btn-primary" type="submit">Show</button>
                @if ($sheet)
                    <a class="btn btn-light" href="{{ route('results.broadsheet.excel', $params) }}"><i class="ri-file-excel-2-line me-1"></i>Excel</a>
                    <a class="btn btn-light" href="{{ route('results.broadsheet.print', $params) }}" target="_blank"><i class="ri-printer-line me-1"></i>Print / PDF</a>
                @endif
            </div></form>

            @if ($sheet)
                @if (! $print && ($approvable->isNotEmpty() || $publishable->isNotEmpty()))
                    <div class="card border-primary d-print-none"><div class="card-body">
                        @if ($approvable->isNotEmpty())
                            <form method="POST" action="{{ route('results.broadsheet.decide', $params) }}" class="mb-2">@csrf
                                <input type="hidden" name="decision" value="approve">
                                <div class="fw-semibold mb-1">{{ $approvable->count() }} score {{ Str::plural('sheet', $approvable->count()) }} on this master sheet {{ $approvable->count() === 1 ? 'is' : 'are' }} waiting for you at: {{ $approvable->map(fn ($s) => $s->stage?->name)->unique()->implode(', ') }}</div>
                                <div class="text-muted fs-12 mb-2">{{ $approvable->map(fn ($s) => $s->course->code)->sort()->implode(', ') }}. To send one back to its lecturer instead, open it from the course list below.</div>
                                <div class="d-flex flex-wrap gap-2">
                                    <input name="note" class="form-control" style="max-width:420px" placeholder="Note for the record (optional), e.g. Senate meeting of 12 March">
                                    <button class="btn btn-success" type="submit" data-confirm="Approve all {{ $approvable->count() }} score sheets at your step?"><i class="ri-check-double-line me-1"></i>Approve all {{ $approvable->count() }}</button>
                                </div>
                            </form>
                        @endif
                        @if ($publishable->isNotEmpty())
                            <form method="POST" action="{{ route('results.broadsheet.decide', $params) }}">@csrf
                                <input type="hidden" name="decision" value="publish">
                                <div class="fw-semibold mb-1">{{ $publishable->count() }} approved score {{ Str::plural('sheet', $publishable->count()) }} can be published: {{ $publishable->map(fn ($s) => $s->course->code)->sort()->implode(', ') }}</div>
                                <button class="btn btn-primary" type="submit" data-confirm="Publish {{ $publishable->count() }} score sheets? Students will see these results and be notified."><i class="ri-send-plane-line me-1"></i>Publish all {{ $publishable->count() }}</button>
                            </form>
                        @endif
                        @if ($shared->isNotEmpty())
                            <div class="alert alert-warning mb-0 mt-2 py-2 fs-13">{{ $shared->implode(', ') }} {{ $shared->count() === 1 ? 'is' : 'are' }} also taken by students of other programmes or levels. Approving or publishing {{ $shared->count() === 1 ? 'it' : 'them' }} here does so for every student on the course.</div>
                        @endif
                    </div></div>
                @endif

                <div class="card"><div class="card-body">
                    @include('partials.letterhead', ['subtitle' => 'Master sheet of results · ' . $sheet->title()])
                    @if ($sheet->provisional && ! $sheet->allApproved())
                        <div class="alert alert-info py-2 fs-13">Provisional: some courses are still going through approval. Students only see published results.</div>
                    @endif

                    <div class="table-responsive mb-3"><table class="table table-sm table-bordered align-middle mb-0 fs-12">
                        <thead class="table-light"><tr><th>Course</th><th>Title</th><th>Type and department</th><th class="text-center">Units</th><th>Where it is</th><th class="text-end">Registered</th><th class="text-end">Passed</th><th class="text-end">Failed</th><th class="text-end">Average</th></tr></thead>
                        <tbody>
                        @foreach ($sheet->courses as $c)
                            <tr><td class="fw-semibold">@if ($c->sheet && ! $print)<a href="{{ route('results.sheets.show', $c->sheet->id) }}">{{ $c->course->code }}</a>@else{{ $c->course->code }}@endif</td>
                                <td>{{ $c->course->title }}</td><td><x-course-tag :course="$c->course" :type="$c->type" full /></td><td class="text-center">{{ $c->course->credit_units }}</td>
                                <td><span class="badge {{ $badges[$c->sheet?->status] ?? 'bg-light text-body' }}">{{ $c->statusLabel() }}</span></td>
                                <td class="text-end">{{ $c->registered }}</td><td class="text-end">{{ $c->passed }}</td><td class="text-end">{{ $c->failed }}</td><td class="text-end">{{ $c->average ?? '—' }}</td></tr>
                        @endforeach
                        </tbody>
                    </table></div>

                    <div class="table-responsive"><table class="table table-sm table-bordered align-middle mb-0 text-center uv-master">
                        <thead class="table-light">
                            <tr><th rowspan="2">S/N</th><th rowspan="2" class="text-start">Matric no.</th><th rowspan="2" class="text-start">Name</th>
                                @if ($sheet->courses->isNotEmpty())<th colspan="{{ $sheet->courses->count() }}" class="uv-group">Courses (score and grade)</th>@endif
                                <th colspan="3" class="uv-group">Present</th><th colspan="3" class="uv-group">Previous</th><th colspan="3" class="uv-group">Cumulative</th><th rowspan="2" class="uv-group text-start">Outstanding results</th><th rowspan="2" class="uv-group text-start">Remark</th></tr>
                            <tr>
                                @foreach ($sheet->courses as $i => $c)<th class="{{ $i === 0 ? 'uv-group' : '' }}" title="{{ $c->course->title }}">{{ $c->course->code }}<div class="fw-normal">{{ $c->course->credit_units }}u · <span title="{{ \App\Support\CourseType::label($c->type) }}">{{ \App\Support\CourseType::short($c->type) }}</span></div></th>@endforeach
                                <th class="uv-group">TCP</th><th>TNU</th><th>GPA</th>
                                <th class="uv-group">TCP</th><th>TNU</th><th>CGPA</th>
                                <th class="uv-group">TCP</th><th>TNU</th><th>CGPA</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse ($sheet->rows->values() as $n => $r)
                            <tr><td>{{ $n + 1 }}</td><td class="text-start">{{ $r->student->matric_label }}</td><td class="text-start">{{ $r->student->full_name }}</td>
                                @foreach ($sheet->courses as $i => $c)
                                    @php $x = $r->results->get($c->course->id); @endphp
                                    <td class="{{ $i === 0 ? 'uv-group' : '' }} {{ $x && ! $x->is_pass ? 'text-danger fw-semibold' : '' }}">{{ $x ? $score($x->total) . ' ' . $x->grade : '' }}</td>
                                @endforeach
                                <td class="uv-group">{{ $score($r->tcp) }}</td><td>{{ $r->tnu }}</td><td class="fw-semibold">{{ number_format($r->gpa, 2) }}</td>
                                <td class="uv-group">{{ $score($r->previousPoints) }}</td><td>{{ $r->previousUnits }}</td><td>{{ number_format($r->previousCgpa, 2) }}</td>
                                <td class="uv-group">{{ $score($r->cumulativePoints) }}</td><td>{{ $r->cumulativeUnits }}</td><td class="fw-semibold">{{ number_format($r->cgpa, 2) }}</td>
                                <td class="uv-group text-start text-warning" style="min-width:120px">{{ $r->outstanding->implode(', ') ?: '—' }}</td>
                                <td class="uv-group text-start {{ in_array($r->bucket, ['withdraw', 'probation'], true) ? 'text-danger' : ($r->bucket === 'carryover' ? 'text-warning' : '') }}">{{ $r->remark }}@if ($r->waived->isNotEmpty())<div class="text-muted">Waived: {{ $r->waived->implode(', ') }}</div>@endif</td></tr>
                        @empty
                            <tr><td colspan="{{ 14 + $sheet->courses->count() }}" class="text-muted py-4">No student of this programme and level has an approved registration for this semester.</td></tr>
                        @endforelse
                        </tbody>
                    </table></div>

                    <div class="d-flex flex-wrap gap-3 mt-3 fs-12">
                        <div><strong>{{ $sheet->rows->count() }}</strong> students</div>
                        @foreach (\App\Data\Results\MasterSheet::BUCKETS as $k => $label)<div>{{ $label }}: <strong>{{ $sheet->counts()[$k] }}</strong></div>@endforeach
                    </div>
                    <div class="text-muted fs-11 mt-1">TCP: total credit points (grade point × units). TNU: total number of units. GPA = TCP ÷ TNU. Previous: before this semester. Cumulative: including this semester.</div>

                    <div class="row mt-5 text-center fs-12">
                        @foreach (['Head of Department', 'Dean of Faculty', 'Chairman, Senate'] as $who)
                            <div class="col-4"><div class="border-top mx-4 pt-1">{{ $who }}<div class="text-muted">Name, signature and date</div></div></div>
                        @endforeach
                    </div>
                </div></div>
            @elseif ($q->programme || $q->level)
                <p class="text-center text-muted py-5 d-print-none">Choose a programme, level, session and semester.</p>
            @endif
        </div>
    </div>
</div>
@if ($print && $sheet)
    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
@endif
@endsection
