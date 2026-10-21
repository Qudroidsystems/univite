{{-- Statistics of one timetable or candidate (App\Timetabling\Analytics::of). $a --}}
@php
    $pct = ($a['required'] ?? 0) ? round($a['placed'] / $a['required'] * 100) : 0;
    $tiles = [
        ['Class meetings placed', $a['placed'] . ' / ' . $a['required'] . " ({$pct}%)", $a['unplaced'] ? 'warning' : 'success'],
        ['Hard rules broken', $a['hard_violations'], $a['hard_violations'] ? 'danger' : 'success'],
        ['Preferences missed', $a['soft_missed'] . ' (' . $a['soft_met'] . ' meetings met all)', $a['soft_missed'] ? 'info' : 'success'],
        ['Preference score', round($a['score'], 1) . ' · lower is better', 'primary'],
        ['Venue occupancy', $a['venues']['occupancy'] . '%', 'secondary'],
        ['Lab occupancy', $a['venues']['labs'] ? $a['venues']['lab_occupancy'] . '%' : 'No labs', 'secondary'],
        ['Lecturer load (periods/week)', $a['lecturers']['min'] . ' – ' . $a['lecturers']['max'] . ' · avg ' . $a['lecturers']['average'], 'secondary'],
        ['Idle gaps for classes', $a['classes']['gaps'] . ' · busiest day ' . $a['classes']['max_per_day'], $a['classes']['gaps'] ? 'warning' : 'success'],
    ];
@endphp
<div class="row g-2 mb-3">
    @foreach ($tiles as [$l, $v, $c])
        <div class="col-6 col-lg-3"><div class="card mb-0 border-{{ $c }}-subtle h-100"><div class="card-body py-2"><div class="fs-5 fw-semibold text-{{ $c }}">{{ $v }}</div><div class="text-muted fs-12">{{ $l }}</div></div></div></div>
    @endforeach
</div>
<div class="text-muted fs-12 mb-3">
    @if (! empty($a['algorithm'])){{ \App\Timetabling\Solver::ALGORITHMS[$a['algorithm']][0] ?? $a['algorithm'] }} · @endif
    @if (isset($a['seconds'])){{ number_format($a['seconds'], 1) }} s · @endif
    {{ $a['locked'] ?? 0 }} locked placement(s)@if (isset($a['manual_changes'])) · {{ $a['manual_changes'] }} change(s) made by hand @endif
</div>

<div class="row g-3">
    <div class="col-xl-5">
        @if (! empty($a['hard']))
            <div class="card border-danger"><div class="card-header bg-danger-subtle"><h6 class="card-title mb-0">Hard rules broken</h6></div>
                <ul class="list-group list-group-flush">@foreach ($a['hard'] as $m)<li class="list-group-item fs-13">{{ $m }}</li>@endforeach</ul></div>
        @endif
        <div class="card"><div class="card-header"><h6 class="card-title mb-0">Preferences not met</h6></div>
            @if (empty($a['soft']))<div class="card-body text-success fs-13">Every placed meeting meets every preference.</div>@else
            <table class="table table-sm mb-0"><thead class="table-light"><tr><th>Preference</th><th class="text-end">Meetings</th><th class="text-end">Cost</th></tr></thead><tbody>
                @foreach (collect($a['soft'])->sortByDesc('cost') as $r)<tr><td class="fs-13">{{ $r['label'] }}</td><td class="text-end">{{ $r['count'] }}</td><td class="text-end">{{ round($r['cost'], 1) }}</td></tr>@endforeach
            </tbody></table>@endif</div>
        @if (! empty($a['soft_worst']))
            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Meetings that miss the most</h6></div>
                <ul class="list-group list-group-flush" style="max-height:360px;overflow:auto">@foreach ($a['soft_worst'] as $w)<li class="list-group-item fs-13"><strong>{{ $w['code'] }}</strong>
                    <ul class="mb-0 ps-3">@foreach ($w['issues'] as $i)<li>{{ $i['text'] }}</li>@endforeach</ul></li>@endforeach</ul></div>
        @endif
    </div>
    <div class="col-xl-7">
        <div class="card"><div class="card-header"><h6 class="card-title mb-0">Lecturers ({{ $a['lecturers']['count'] }})</h6></div>
            <div class="table-responsive" style="max-height:320px"><table class="table table-sm mb-0"><thead class="table-light sticky-top"><tr><th>Lecturer</th><th class="text-end">Periods a week</th><th class="text-end">Days</th><th class="text-end">Busiest day</th></tr></thead><tbody>
                @forelse ($a['lecturers']['rows'] as $r)<tr><td class="fs-13">{{ $r['name'] }}</td><td class="text-end">{{ $r['periods'] }}</td><td class="text-end">{{ $r['days'] }}</td><td class="text-end">{{ $r['busiest'] }}</td></tr>@empty<tr><td colspan="4" class="text-muted">No lecturers assigned.</td></tr>@endforelse
            </tbody></table></div></div>
        <div class="card"><div class="card-header"><h6 class="card-title mb-0">Student classes ({{ $a['classes']['count'] }})</h6><div class="text-muted fs-12">Most idle gaps first.</div></div>
            <div class="table-responsive" style="max-height:320px"><table class="table table-sm mb-0"><thead class="table-light sticky-top"><tr><th>Class</th><th class="text-end">Periods</th><th class="text-end">Days</th><th class="text-end">Busiest day</th><th class="text-end">Gaps</th></tr></thead><tbody>
                @foreach ($a['classes']['rows'] as $r)<tr><td class="fs-13">{{ $r['name'] }}</td><td class="text-end">{{ $r['periods'] }}</td><td class="text-end">{{ $r['days'] }}</td><td class="text-end">{{ $r['max_per_day'] }}</td><td class="text-end {{ $r['gaps'] ? 'text-warning' : '' }}">{{ $r['gaps'] }}</td></tr>@endforeach
            </tbody></table></div></div>
        <div class="card"><div class="card-header"><h6 class="card-title mb-0">Venues ({{ $a['venues']['count'] }})</h6></div>
            <div class="table-responsive" style="max-height:320px"><table class="table table-sm align-middle mb-0"><thead class="table-light sticky-top"><tr><th>Venue</th><th class="text-end">Seats</th><th class="text-end">Periods used</th><th style="width:35%">Occupancy</th></tr></thead><tbody>
                @foreach ($a['venues']['rows'] as $r)<tr><td class="fs-13"><strong>{{ $r['code'] }}</strong> {{ $r['name'] }}@if ($r['lab']) <span class="badge bg-info-subtle text-info">lab</span>@endif</td><td class="text-end">{{ $r['cap'] }}</td><td class="text-end">{{ $r['used'] }}</td>
                    <td><div class="progress" style="height:6px"><div class="progress-bar {{ $r['percent'] > 85 ? 'bg-danger' : '' }}" style="width:{{ min(100, $r['percent']) }}%"></div></div><span class="fs-12 text-muted">{{ $r['percent'] }}%</span></td></tr>@endforeach
            </tbody></table></div></div>
    </div>
</div>
