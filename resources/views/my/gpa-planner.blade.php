@extends('layouts.master')

@section('content')
@php $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.'); @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'GPA planner', 'icon' => 'ri-focus-3-line', 'subtitle' => 'Set a target and see the grades you need in each course this semester' . ($ctx['term'] ? ' · ' . $ctx['term'] : '')])
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    <div class="row">
        <div class="col-lg-4">
            <div class="card"><div class="card-body">
                <div class="d-flex justify-content-between text-center mb-3">
                    <div><div class="fs-3 fw-semibold text-primary">{{ number_format($ctx['cgpa'], 2) }}</div><div class="text-muted fs-12">CGPA now</div></div>
                    <div><div class="fs-3 fw-semibold">{{ $ctx['units'] }}</div><div class="text-muted fs-12">Units so far</div></div>
                    <div><div class="fs-3 fw-semibold">{{ collect($ctx['courses'])->sum('units') }}</div><div class="text-muted fs-12">Units this semester</div></div>
                </div>
                @if (empty($ctx['courses']))
                    <div class="alert alert-info mb-0">Register your courses for this semester first; the planner works out grades over the courses you take.</div>
                @else
                <form method="GET" action="{{ route('my.gpa-planner') }}">
                    <label class="form-label">I am aiming for</label>
                    <div class="btn-group w-100 mb-3" role="group">
                        <input type="radio" class="btn-check" name="kind" value="gpa" id="k-gpa" @checked(request('kind', 'gpa') === 'gpa')><label class="btn btn-outline-primary" for="k-gpa">GPA this semester</label>
                        <input type="radio" class="btn-check" name="kind" value="cgpa" id="k-cgpa" @checked(request('kind') === 'cgpa')><label class="btn btn-outline-primary" for="k-cgpa">CGPA overall</label>
                    </div>
                    <label class="form-label">Target (out of {{ $fmt($ctx['max_points']) }})</label>
                    <input type="number" step="0.01" min="0.01" max="{{ $ctx['max_points'] }}" name="target" value="{{ request('target', number_format(min($ctx['max_points'], max(1, $ctx['cgpa'] + 0.5)), 2)) }}" class="form-control form-control-lg mb-3" required>
                    <details class="mb-3"><summary class="text-primary">My strengths (optional)</summary>
                        <div class="text-muted fs-12 my-2">1 = weak, 5 = strong. The planner can give your strongest courses the higher grades.</div>
                        @foreach ($ctx['courses'] as $c)
                            <div class="d-flex align-items-center gap-2 mb-1"><span class="flex-grow-1 fs-13">{{ $c['code'] }} <span class="text-muted">· {{ $c['units'] }} u</span></span>
                                <select name="strengths[{{ $c['id'] }}]" class="form-select form-select-sm w-auto"><option value="">–</option>@foreach (range(1, 5) as $n)<option value="{{ $n }}" @selected((int) request("strengths.{$c['id']}") === $n)>{{ $n }}</option>@endforeach</select></div>
                        @endforeach
                    </details>
                    <button class="btn btn-primary w-100"><i class="ri-magic-line me-1"></i>Show the grades I need</button>
                </form>
                @endif
            </div></div>
            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Grading scale</h6></div><table class="table table-sm mb-0"><tbody>
                @foreach ($ctx['scale'] as $g)<tr><td class="fw-semibold">{{ $g['letter'] }}</td><td>{{ $fmt($g['min']) }}–{{ $fmt($g['max']) }}</td><td>{{ $fmt($g['points']) }} points</td></tr>@endforeach
            </tbody></table></div>
        </div>
        <div class="col-lg-8">
            @if ($plan)
                <div class="alert {{ $plan['feasible'] ? 'alert-success' : 'alert-danger' }}"><i class="{{ $plan['feasible'] ? 'ri-checkbox-circle-line' : 'ri-error-warning-line' }} me-1"></i>{{ $plan['message'] }}</div>
                <div class="row">
                @foreach ($plan['scenarios'] as $s)
                    <div class="col-md-6"><div class="card"><div class="card-header"><h6 class="card-title mb-0">{{ $s['label'] }}</h6><div class="text-muted fs-12">{{ $s['note'] }}</div></div>
                        <table class="table align-middle mb-0"><tbody>
                            @foreach ($s['courses'] as $c)
                                <tr><td><strong>{{ $c['code'] }}</strong><div class="text-muted fs-12">{{ $c['units'] }} units</div></td>
                                    <td><span class="badge bg-primary-subtle text-primary fs-13">{{ $c['grade'] }}</span></td>
                                    <td class="fs-12 text-muted">@if ($c['exam_needed'] !== null)exam {{ $fmt($c['exam_needed']) }} / {{ $fmt($c['exam_max']) }}@else score {{ $fmt($c['min_score']) }}+@endif</td></tr>
                            @endforeach
                        </tbody></table>
                        <div class="card-footer fw-semibold">GPA {{ number_format($s['gpa'], 2) }}@if ($s['cgpa'] !== null) · CGPA {{ number_format($s['cgpa'], 2) }}@endif</div>
                    </div></div>
                @endforeach
                </div>
                <p class="text-muted fs-12">Worked out with the university's grading scale, your courses' credit units and the CGPA rule. Aim a little higher to be safe. The UniVite app can also turn a plan into a weekly reading timetable with reminders.</p>
            @else
                <div class="card"><div class="card-body text-muted"><i class="ri-lightbulb-line me-1"></i>Enter a target GPA for this semester, or the CGPA you want to reach, and the planner shows grade combinations over your registered courses that get you there.</div></div>
            @endif
        </div>
    </div>
</div></div></div>
@endsection
