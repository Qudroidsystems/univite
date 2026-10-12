@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Clinic statistics', 'icon' => 'ri-bar-chart-2-line', 'subtitle' => $from->format('j M Y') . ' to ' . $to->format('j M Y') . '. Counts only; no names or clinical notes.', 'back' => [route('clinic.index'), 'Health Centre']])
    <form method="GET" class="d-flex gap-2 mb-3" style="max-width:480px"><input type="date" name="from" value="{{ $from->toDateString() }}" class="form-control"><input type="date" name="to" value="{{ $to->toDateString() }}" class="form-control"><button class="btn btn-light">Show</button></form>
    <div class="row">
        @foreach ([['Visits', $stats->visits], ['People seen', $stats->people], ['Students', $stats->students], ['Staff', $stats->staff], ['Days off given', $stats->restDays]] as [$l, $n])
            <div class="col"><div class="card"><div class="card-body text-center"><div class="fs-3 fw-semibold">{{ number_format($n) }}</div><div class="text-muted fs-12">{{ $l }}</div></div></div></div>
        @endforeach
    </div>
    <div class="row">
        <div class="col-md-4"><div class="card"><div class="card-header"><h6 class="card-title mb-0">By reason</h6></div><ul class="list-group list-group-flush">
            @foreach (\App\Models\ClinicVisit::CATEGORIES as $k => $l)<li class="list-group-item d-flex justify-content-between fs-13"><span>{{ $l }}</span><span>{{ $stats->byCategory[$k] ?? 0 }}</span></li>@endforeach
        </ul></div></div>
        <div class="col-md-4"><div class="card"><div class="card-header"><h6 class="card-title mb-0">By outcome</h6></div><ul class="list-group list-group-flush">
            @foreach (\App\Models\ClinicVisit::OUTCOMES as $k => $l)<li class="list-group-item d-flex justify-content-between fs-13"><span>{{ $l }}</span><span>{{ $stats->byOutcome[$k] ?? 0 }}</span></li>@endforeach
        </ul></div></div>
        <div class="col-md-4"><div class="card"><div class="card-header"><h6 class="card-title mb-0">New students' examinations</h6></div><div class="card-body fs-13">
            <p>{{ $stats->newStudentsExamined }} of {{ $stats->newStudents }} students admitted this session have been examined.</p>
            @foreach (\App\Models\HealthRecord::FITNESS as $k => $l)<div class="d-flex justify-content-between"><span>{{ $l }}</span><span>{{ $stats->fitness[$k] ?? 0 }}</span></div>@endforeach
        </div></div></div>
    </div>
    @if ($stats->byWeek)
        <div class="card"><div class="card-header"><h6 class="card-title mb-0">Visits per week</h6></div><div class="card-body">
            @php $max = max($stats->byWeek); @endphp
            @foreach ($stats->byWeek as $week => $n)
                <div class="d-flex align-items-center gap-2 fs-12 mb-1"><span style="width:110px">w/c {{ \Carbon\Carbon::parse($week)->format('j M') }}</span><div class="bg-primary rounded" style="height:14px;width:{{ max(2, round($n / $max * 100)) }}%"></div><span>{{ $n }}</span></div>
            @endforeach
        </div></div>
    @endif
</div></div></div>
@endsection
