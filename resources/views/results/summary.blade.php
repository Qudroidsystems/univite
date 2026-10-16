@extends('layouts.master')

@section('content')
@php
    $buckets = ['pass' => ['Passed all courses', 'success'], 'carryover' => ['With carry-overs', 'warning'], 'probation' => ['On probation', 'danger'], 'withdraw' => ['Advised to withdraw', 'danger'], 'pending' => ['Results not yet published', 'secondary']];
@endphp
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="page-title-box d-print-none"><h4 class="mb-0">Result summary</h4></div>
            @include('academics.partials.alerts')
            <form method="GET" class="card d-print-none"><div class="card-body d-flex flex-wrap gap-2 align-items-end">
                <div><label class="form-label mb-1">Department</label><select name="department_id" class="form-select" required><option value="">Select…</option>@foreach ($departments as $id => $n)<option value="{{ $id }}" @selected($dept?->id === $id)>{{ $n }}</option>@endforeach</select></div>
                <div><label class="form-label mb-1">Level</label><select name="level" class="form-select"><option value="">All levels</option>@foreach ($levels as $v => $l)<option value="{{ $v }}" @selected($f['level'] === $v)>{{ $l }}</option>@endforeach</select></div>
                <div><label class="form-label mb-1">Session</label><select name="session_id" data-lookup="sessions" class="form-select">@foreach ($sessions as $id => $n)<option value="{{ $id }}" @selected($f['session'] === $id)>{{ $n }}</option>@endforeach</select></div>
                <div><label class="form-label mb-1">Semester</label><select name="semester_type_id" data-lookup="semester-types" class="form-select">@foreach ($types as $id => $n)<option value="{{ $id }}" @selected($f['type'] === $id)>{{ $n }}</option>@endforeach</select></div>
                <button class="btn btn-primary" type="submit">Show</button>
                @if ($groups)<button type="button" class="btn btn-light" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print</button>@endif
                <a href="{{ route('results.waivers.index') }}" class="btn btn-light ms-auto"><i class="ri-file-shield-2-line me-1"></i>Carry-over waivers</a>
            </div></form>

            @if ($groups !== null)
                @include('partials.letterhead', ['subtitle' => ($dept->name) . ' · summary of results · ' . ($sessions[$f['session']] ?? '') . ' ' . ($types[$f['type']] ?? '')])
                @forelse ($groups as $g)
                    <div class="card"><div class="card-header d-flex flex-wrap justify-content-between gap-2">
                        <h6 class="card-title mb-0">{{ $g['programme']->name }} · {{ $g['programme']->levelLabel($g['level']) }}</h6>
                        <div class="fs-12">{{ $g['rows']->count() }} registered @foreach ($buckets as $k => [$l, $c]) · <span class="text-{{ $c }}">{{ $l }}: {{ $g['rows']->where('bucket', $k)->count() }}</span>@endforeach</div>
                    </div>
                    @foreach ($buckets as $k => [$l, $c])
                        @php $rows = $g['rows']->where('bucket', $k); @endphp
                        @continue($rows->isEmpty())
                        <div class="px-3 pt-3 fw-semibold text-{{ $c }}">{{ $l }} ({{ $rows->count() }})</div>
                        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                            <thead><tr><th style="width:4%">#</th><th style="width:16%">Matric</th><th>Name</th><th class="text-end" style="width:8%">GPA</th><th class="text-end" style="width:8%">CGPA</th><th style="width:34%">{{ $k === 'pending' ? 'Courses awaiting results' : 'Outstanding courses' }}</th></tr></thead>
                            <tbody>
                            @foreach ($rows->values() as $i => $r)
                                <tr><td>{{ $i + 1 }}</td><td>{{ $r['student']->matric_label }}</td><td>{{ $r['student']->full_name }}</td>
                                    <td class="text-end">{{ $k === 'pending' ? '—' : number_format($r['gpa'], 2) }}</td><td class="text-end">{{ number_format($r['cgpa'], 2) }}</td>
                                    <td class="fs-13">
                                        @if ($k === 'pending'){{ $r['pending'] }}
                                        @else
                                            @php $mayWaive = $canWaive($r['student']); @endphp
                                            @forelse ($r['outstanding_courses'] as $course)
                                                @if ($mayWaive)
                                                    <details class="d-inline-block me-1 align-top"><summary class="badge bg-warning-subtle text-warning" style="cursor:pointer" title="Waive this carry-over">{{ $course->code }}</summary>
                                                        <form method="POST" action="{{ route('results.waivers.store') }}" class="d-flex gap-1 mt-1 d-print-none" style="min-width:320px">@csrf
                                                            <input type="hidden" name="student_id" value="{{ $r['student']->id }}"><input type="hidden" name="course_id" value="{{ $course->id }}">
                                                            <input name="reason" class="form-control form-control-sm" placeholder="Reason, e.g. course no longer offered" required minlength="5">
                                                            <button class="btn btn-sm btn-soft-warning text-nowrap" type="submit" data-confirm="Waive {{ $course->code }} for {{ $r['student']->full_name }}? It stays on the transcript but no longer has to be passed.">Waive</button></form>
                                                    </details>
                                                @else<span class="me-1">{{ $course->code }}</span>@endif
                                            @empty
                                                @if ($r['waived']->isEmpty())—@endif
                                            @endforelse
                                            @foreach ($r['waived'] as $w)
                                                <details class="d-inline-block me-1 align-top"><summary class="badge bg-light text-muted text-decoration-line-through" style="cursor:pointer" title="Waived: {{ $w->reason }}">{{ $w->course->code }} waived</summary>
                                                    <div class="fs-12 text-muted mt-1">{{ $w->reason }}</div>
                                                    @if ($mayWaive)
                                                        <form method="POST" action="{{ route('results.waivers.withdraw', $w->id) }}" class="d-flex gap-1 mt-1 d-print-none" style="min-width:320px">@csrf
                                                            <input name="reason" class="form-control form-control-sm" placeholder="Why withdraw the waiver?" required>
                                                            <button class="btn btn-sm btn-soft-danger text-nowrap" type="submit">Withdraw</button></form>
                                                    @endif
                                                </details>
                                            @endforeach
                                        @endif
                                        @if ($k === 'withdraw' && $current)
                                            @if ($waivers->has($r['student']->id))
                                                <div class="text-success fs-12 d-print-none">Allowed to register in {{ $current->name }}</div>
                                            @elseif (\App\Http\Controllers\Academics\AcademicRuleController::canEdit(auth()->user(), 'programme', $r['student']->programme_id))
                                                <form method="POST" action="{{ route('results.summary.waive', $r['student']->id) }}" class="d-flex gap-1 mt-1 d-print-none">@csrf
                                                    <input name="note" class="form-control form-control-sm" placeholder="Reason (e.g. Senate approved)" required>
                                                    <button class="btn btn-sm btn-soft-primary text-nowrap" type="submit">Allow to register in {{ $current->name }}</button></form>
                                            @endif
                                        @endif
                                    </td></tr>
                            @endforeach
                            </tbody>
                        </table></div>
                    @endforeach
                    </div>
                @empty
                    <p class="text-center text-muted py-5">No student of this department has an approved registration for this semester.</p>
                @endforelse
            @endif
        </div>
    </div>
</div>
@endsection
