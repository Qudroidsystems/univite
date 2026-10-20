@extends('layouts.master')

@section('content')
@php
    use App\Data\Senate\SenateReason as R;
    use App\Data\Senate\SenateStudent as S;
    use App\Models\SenateDecision as D;
    $params = $q->params();
    $tab = fn ($v) => route('senate.index', $params + ['view' => $v]);
    $exp = fn ($f) => route('senate.export', ['format' => $f] + $params + ['view' => $view]);
    $groupBadge = ['A' => 'bg-primary-subtle text-primary', 'B' => 'bg-warning-subtle text-warning', 'C' => 'bg-info-subtle text-info', 'D' => 'bg-secondary-subtle text-secondary'];
    $codesFor = $view === 'courses' ? collect(R::CODES)->filter(fn ($c) => $c[0] === 'D') : collect(R::CODES)->reject(fn ($c) => $c[0] === 'D');
@endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    <div class="page-title-box d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <div><h4 class="mb-0"><i class="ri-government-line me-1"></i>Senate result approval</h4>
            <div class="text-muted fs-13">{{ $q->title() }} · the levels Senate sits on for each programme (Academic Rules, Levels) · includes results still going through approval</div></div>
        <div class="d-flex gap-2">
            <a href="{{ $exp('print') }}" target="_blank" class="btn btn-sm btn-light"><i class="ri-printer-line me-1"></i>Print</a>
            <a href="{{ $exp('pdf') }}" target="_blank" class="btn btn-sm btn-light"><i class="ri-file-pdf-2-line me-1"></i>PDF</a>
            <a href="{{ $exp('excel') }}" class="btn btn-sm btn-light"><i class="ri-file-excel-2-line me-1"></i>Excel</a>
        </div>
    </div>
    @include('academics.partials.alerts')

    <form method="GET" class="card uv-senate-filters"><div class="card-body">
        <input type="hidden" name="view" value="{{ $view }}">
        <div class="row g-2 align-items-end">
            <div class="col-md-2"><label class="form-label mb-1">Session</label><select name="session_id" class="form-select form-select-sm">@foreach ($sessions as $v => $l)<option value="{{ $v }}" @selected($q->session?->id === $v)>{{ $l }}</option>@endforeach</select></div>
            <div class="col-md-2"><label class="form-label mb-1">Semester</label><select name="semester_type_id" class="form-select form-select-sm"><option value="">Whole session</option>@foreach ($semesters as $v => $l)<option value="{{ $v }}" @selected($q->semester?->id === $v)>{{ $l }}</option>@endforeach</select></div>
            <div class="col-md-2"><label class="form-label mb-1">Faculty / school</label><select name="faculty_id" class="form-select form-select-sm"><option value="">All</option>@foreach ($faculties as $v => $l)<option value="{{ $v }}" @selected($q->facultyId === $v)>{{ $l }}</option>@endforeach</select></div>
            <div class="col-md-2"><label class="form-label mb-1">Department</label><select name="department_id" class="form-select form-select-sm"><option value="">All</option>@foreach ($departments as $v => $l)<option value="{{ $v }}" @selected($q->departmentId === $v)>{{ $l }}</option>@endforeach</select></div>
            <div class="col-md-2"><label class="form-label mb-1">Programme</label><select name="programme_id" class="form-select form-select-sm"><option value="">All</option>@foreach ($programmes as $v => $l)<option value="{{ $v }}" @selected($q->programmeId === $v)>{{ $l }}</option>@endforeach</select></div>
            <div class="col-md-2"><label class="form-label mb-1">Level</label><select name="level" class="form-select form-select-sm"><option value="">Every Senate level</option>@foreach ($levels as $v => $l)<option value="{{ $v }}" @selected($q->level === $v)>{{ $l }}</option>@endforeach</select></div>
            @if ($view !== 'summary')
                <div class="col-md-2"><label class="form-label mb-1">Status</label><select name="status" class="form-select form-select-sm"><option value="">Any</option>@foreach ($view === 'courses' ? ['eligible' => 'Nothing to note', 'needs_decision' => 'Needs Senate decision'] : S::STATUSES as $v => $l)<option value="{{ $v }}" @selected($q->status === $v)>{{ $l }}</option>@endforeach</select></div>
                <div class="col-md-3"><label class="form-label mb-1">Finding</label><select name="flag" class="form-select form-select-sm"><option value="">Any</option>
                    @foreach ($codesFor->groupBy(fn ($c) => $c[0], true) as $g => $codes)<optgroup label="{{ $g }}. {{ R::GROUPS[$g] }}">@foreach ($codes as $code => [, $label])<option value="{{ $code }}" @selected($q->flag === $code)>{{ $label }}</option>@endforeach</optgroup>@endforeach
                </select></div>
                <div class="col-md-2"><label class="form-label mb-1">Senate decision</label><select name="decision" class="form-select form-select-sm"><option value="">Any</option><option value="none" @selected($q->decision === 'none')>Not yet decided</option>@foreach (D::DECISIONS as $v => $l)<option value="{{ $v }}" @selected($q->decision === $v)>{{ $l }}</option>@endforeach</select></div>
                <div class="col-md-3"><label class="form-label mb-1">{{ $view === 'courses' ? 'Course code or title' : 'Student' }}</label>
                    <input name="{{ $view === 'courses' ? 'course' : 'q' }}" value="{{ $view === 'courses' ? $q->course : $q->search }}" class="form-control form-control-sm" placeholder="{{ $view === 'courses' ? 'e.g. CSC 401' : 'Name or matric number' }}"></div>
            @endif
            <div class="col-md-2"><div class="form-check"><input class="form-check-input" type="checkbox" name="final_only" value="1" id="fo" @checked($q->finalOnly)><label class="form-check-label fs-13" for="fo">Graduating level only</label></div></div>
            <div class="col-auto"><button class="btn btn-primary btn-sm"><i class="ri-filter-3-line me-1"></i>Show</button> <a href="{{ route('senate.index', ['view' => $view]) }}" class="btn btn-light btn-sm">Clear</a></div>
        </div>
    </div></form>

    <ul class="nav nav-tabs nav-tabs-custom mb-3">
        @foreach (\App\Http\Controllers\Senate\SenateController::VIEWS as $v => $l)
            <li class="nav-item"><a class="nav-link {{ $view === $v ? 'active' : '' }}" href="{{ $tab($v) }}">{{ $l }}</a></li>
        @endforeach
    </ul>

    @if ($view === 'students')
        @php $counts = $students->countBy(fn ($s) => $s->status()); @endphp
        <div class="row">
            @foreach ([['Candidates', $students->count(), 'ri-group-line', 'primary', null], ['Eligible', $counts['eligible'] ?? 0, 'ri-checkbox-circle-line', 'success', 'eligible'], ['Not eligible', $counts['not_eligible'] ?? 0, 'ri-close-circle-line', 'danger', 'not_eligible'], ['Needs Senate decision', $counts['needs_decision'] ?? 0, 'ri-question-line', 'warning', 'needs_decision'], ['Decided', $students->filter(fn ($s) => $s->decision)->count(), 'ri-government-line', 'info', null]] as [$l, $n, $i, $c, $st])
                <div class="col"><a class="card card-animate text-decoration-none" href="{{ $st ? route('senate.index', ['status' => $st] + $params + ['view' => 'students']) : '#' }}"><div class="card-body d-flex justify-content-between align-items-center">
                    <div><div class="text-muted fs-12 text-uppercase fw-semibold">{{ $l }}</div><div class="fs-3 fw-semibold text-body">{{ $n }}</div></div>
                    <span class="avatar-sm"><span class="avatar-title bg-{{ $c }}-subtle text-{{ $c }} rounded fs-3"><i class="{{ $i }}"></i></span></span></div></a></div>
            @endforeach
        </div>
        @if ($canDecide && ($counts['eligible'] ?? 0) > 0)
            <form method="POST" action="{{ route('senate.approve-eligible', $params) }}" class="alert alert-success d-flex flex-wrap justify-content-between align-items-center gap-2">
                @csrf
                <span><i class="ri-check-double-line me-1"></i>{{ $counts['eligible'] }} eligible {{ \Illuminate\Support\Str::plural('student', $counts['eligible']) }} in this view.</span>
                <span class="d-flex gap-2"><input name="note" class="form-control form-control-sm" style="width:260px" placeholder="Note (optional), e.g. Senate meeting 12 Nov"><button class="btn btn-success btn-sm text-nowrap" data-confirm="Approve every eligible student in this view?">Approve all eligible</button></span>
            </form>
        @endif

        <div class="card"><div class="table-responsive"><table class="table align-middle mb-0 uv-senate">
            <thead class="table-light"><tr><th>Student</th><th class="text-center">CGPA</th><th>Class · units</th><th>Status</th><th style="width:38%">Findings</th><th>Senate</th></tr></thead>
            <tbody>
            @forelse ($students as $s)
                @php $st = $s->status(); $p = $s->student->programme; @endphp
                <tr>
                    <td><span class="fw-semibold">{{ $s->student->full_name }}</span><div class="fs-12 text-muted">{{ $s->student->matric_number ?? '—' }} · {{ $p->code }} · {{ $p->levelLabel($s->level) }}@if ($s->final) <span class="badge bg-dark-subtle text-dark">Final</span>@endif</div>
                        <div class="fs-11 text-muted">{{ $p->department?->name }} · {{ $p->department?->faculty?->name }}</div></td>
                    <td class="text-center"><div class="fs-5 fw-semibold">{{ number_format($s->cgpa, 2) }}</div><div class="fs-11 text-muted">GPA {{ number_format($s->gpa, 2) }}@if ($s->previousGpa !== null) (was {{ number_format($s->previousGpa, 2) }})@endif</div></td>
                    <td class="fs-13">{{ $s->class ?? '—' }}<div class="fs-12 text-muted">{{ $s->unitsPassed }}{{ $s->unitsNeeded ? ' / ' . $s->unitsNeeded : '' }} units passed</div></td>
                    <td><span class="badge {{ S::BADGES[$st] }}">{{ S::STATUSES[$st] }}</span></td>
                    <td class="fs-12">
                        @forelse ($s->grouped() as $g => $rs)
                            @foreach ($rs as $r)
                                <div class="d-flex gap-1 mb-1 {{ $r->info ? 'text-muted' : '' }}"><span class="badge {{ $groupBadge[$g] }} flex-shrink-0" title="{{ R::GROUPS[$g] }}">{{ $g }}</span>@if ($r->blocks)<i class="ri-close-circle-fill text-danger flex-shrink-0" title="Blocks"></i>@endif<span>{{ $r->text }}</span></div>
                            @endforeach
                        @empty <span class="text-success"><i class="ri-check-line"></i> Meets every check</span>
                        @endforelse
                    </td>
                    <td class="fs-12">
                        @if ($s->decision)<span class="badge {{ D::BADGES[$s->decision->decision] }}">{{ D::DECISIONS[$s->decision->decision] }}</span><div class="text-muted">{{ $s->decision->decider?->name }}, {{ $s->decision->created_at->format('j M') }}</div>@if ($s->decision->note)<div>{{ \Illuminate\Support\Str::limit($s->decision->note, 80) }}</div>@endif @endif
                        @if ($canDecide)<button class="btn btn-sm btn-soft-primary mt-1" type="button" data-bs-toggle="collapse" data-bs-target="#d{{ $s->student->id }}">{{ $s->decision ? 'Change' : 'Decide' }}</button>@endif
                    </td>
                </tr>
                @if ($canDecide)
                    <tr class="collapse" id="d{{ $s->student->id }}"><td colspan="6" class="bg-light">
                        @include('senate.partials.decide', ['type' => 'student', 'id' => $s->student->id, 'sem' => null, 'suggest' => $st === 'eligible' ? 'approved' : ($st === 'not_eligible' ? 'referred' : 'deferred')])
                    </td></tr>
                @endif
            @empty
                <tr><td colspan="6" class="text-center text-muted py-5">No student at a Senate level matches. Senate levels are set per programme under Academic Rules (Levels); by default the graduating level.</td></tr>
            @endforelse
            </tbody>
        </table></div></div>
    @elseif ($view === 'courses')
        <div class="card"><div class="table-responsive"><table class="table align-middle mb-0 uv-senate">
            <thead class="table-light"><tr><th>Course</th><th class="text-center">Students</th><th class="text-center">Pass rate</th><th class="text-center">Average</th><th style="width:16%">Grades</th><th>Sheet &amp; approval trail</th><th style="width:24%">Findings</th><th>Senate</th></tr></thead>
            <tbody>
            @forelse ($courses as $i => $c)
                @php $max = max(1, max($c->grades ?: [0])); @endphp
                <tr>
                    <td><span class="fw-semibold">{{ $c->course->code }}</span> <span class="fs-13">{{ $c->course->title }}</span><div class="fs-12 text-muted">{{ $c->semester }} · {{ $c->course->credit_units }} units · {{ $c->course->department?->name }}</div>
                        <div class="fs-11 text-muted">{{ $c->lecturers ? 'Lecturer: ' . implode(', ', $c->lecturers) : 'No lecturer defined' }}</div></td>
                    <td class="text-center">{{ $c->withResult }}<span class="text-muted">/{{ $c->registered }}</span></td>
                    <td class="text-center fw-semibold {{ $c->has('pass_low') ? 'text-danger' : ($c->has('pass_high') ? 'text-warning' : '') }}">{{ $c->passRate() !== null ? $c->passRate() . '%' : '—' }}</td>
                    <td class="text-center">{{ $c->average ?? '—' }}<div class="fs-11 text-muted">dept {{ $c->departmentAverage ?? '—' }}</div></td>
                    <td><div class="d-flex align-items-end gap-1" style="height:38px">@foreach ($c->grades as $g => $n)<div class="text-center" style="flex:1"><div class="bg-primary rounded-top" style="height:{{ round($n / $max * 26) }}px;opacity:.75" title="{{ $g }}: {{ $n }}"></div><div class="fs-10 text-muted">{{ $g }}</div></div>@endforeach</div></td>
                    <td class="fs-12">{{ \App\Models\ResultSheet::STATUSES[$c->sheetStatus] ?? 'Not opened' }}@if ($c->stage) · at {{ $c->stage }}@endif @if ($c->submittedAt)<div class="text-muted">Submitted {{ $c->submittedAt }}</div>@endif
                        @if ($c->trail->isNotEmpty())<details><summary class="text-primary">Trail ({{ $c->trail->count() }})</summary><ul class="ps-3 mb-0">@foreach ($c->trail as $e)<li>{{ $e->created_at?->format('j M') }} · {{ ucfirst($e->action) }}@if ($e->stage) ({{ $e->stage }})@endif · {{ $e->user?->name }}@if ($e->note) — {{ \Illuminate\Support\Str::limit($e->note, 90) }}@endif</li>@endforeach</ul></details>@endif
                        @if ($c->sheetId)<a href="{{ route('results.sheets.show', $c->sheetId) }}" class="fs-11">Open sheet</a>@endif</td>
                    <td class="fs-12">@forelse ($c->reasons as $r)<div class="mb-1"><i class="ri-error-warning-line text-warning"></i> {{ $r->text }}</div>@empty<span class="text-success"><i class="ri-check-line"></i> Nothing unusual</span>@endforelse</td>
                    <td class="fs-12">@if ($c->decision)<span class="badge {{ D::BADGES[$c->decision->decision] }}">{{ D::DECISIONS[$c->decision->decision] }}</span><div class="text-muted">{{ $c->decision->decider?->name }}</div>@endif
                        @if ($canDecide)<button class="btn btn-sm btn-soft-primary mt-1" type="button" data-bs-toggle="collapse" data-bs-target="#c{{ $i }}">{{ $c->decision ? 'Change' : 'Decide' }}</button>@endif</td>
                </tr>
                @if ($canDecide)
                    <tr class="collapse" id="c{{ $i }}"><td colspan="8" class="bg-light">@include('senate.partials.decide', ['type' => 'course', 'id' => $c->course->id, 'sem' => \App\Models\SemesterType::where('name', $c->semester)->value('id'), 'suggest' => $c->status() === 'eligible' ? 'approved' : 'deferred'])</td></tr>
                @endif
            @empty
                <tr><td colspan="8" class="text-center text-muted py-5">No courses taken by students at the Senate levels match.</td></tr>
            @endforelse
            </tbody>
        </table></div></div>
    @else
        @include('senate.partials.summary', ['summary' => $summary])
    @endif

    @if ($canSettings)
        <details class="card"><summary class="card-header"><span class="card-title mb-0 fw-semibold">What Senate's checks look for</span></summary>
            <form method="POST" action="{{ route('senate.settings') }}" class="card-body">@csrf @method('PUT')
                <div class="row g-3">
                    @foreach ([['boundary_margin', 'Class boundary margin (CGPA)', '0.01'], ['gpa_swing', 'GPA change to flag', '0.01'], ['pass_high', 'Pass rate above (%)', '1'], ['pass_low', 'Pass rate below (%)', '1'], ['a_share', 'Share of top grades (%)', '1'], ['f_share', 'Share of fails (%)', '1'], ['avg_gap', 'Average away from department\'s by', '0.1'], ['min_takers', 'Grade checks need at least (students)', '1'], ['late_days', 'Sheet late after semester end (days)', '1']] as [$k, $l, $step])
                        <div class="col-md-3 col-6"><label class="form-label mb-1 fs-13">{{ $l }}</label><input type="number" step="{{ $step }}" name="{{ $k }}" value="{{ $settings[$k] }}" class="form-control form-control-sm"></div>
                    @endforeach
                    <div class="col-md-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="check_fees" value="1" id="cf" @checked($settings['check_fees'] === '1')><label class="form-check-label fs-13" for="cf">Check fees and school loans</label></div>
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="check_library" value="1" id="cl2" @checked($settings['check_library'] === '1')><label class="form-check-label fs-13" for="cl2">Check library (final year)</label></div>
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="check_hostel" value="1" id="ch" @checked($settings['check_hostel'] === '1')><label class="form-check-label fs-13" for="ch">Check hostel (final year)</label></div></div>
                </div>
                <div class="text-end mt-2"><button class="btn btn-primary btn-sm">Save</button></div>
            </form>
        </details>
    @endif
</div></div></div>
@endsection
