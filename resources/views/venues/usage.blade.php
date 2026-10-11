@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Venue usage', 'icon' => 'ri-bar-chart-2-line', 'subtitle' => 'How much of the week (lectures) and of the examination period each venue is used, from the published timetables.', 'back' => [route('venues.index'), 'Venues']])
    <div class="card"><div class="card-body"><form method="GET" class="row g-2 align-items-end">
        <div class="col-md-5"><label class="form-label fs-12">Lecture timetable</label><select name="lecture" class="form-select form-select-sm"><option value="">None</option>@foreach ($lectures as $t)<option value="{{ $t->id }}" @selected($lecture?->id === $t->id)>{{ $t->session?->name }} · {{ $t->title }}</option>@endforeach</select></div>
        <div class="col-md-5"><label class="form-label fs-12">Examination timetable</label><select name="exam" class="form-select form-select-sm"><option value="">None</option>@foreach ($exams as $t)<option value="{{ $t->id }}" @selected($exam?->id === $t->id)>{{ $t->session?->name }} · {{ $t->title }}</option>@endforeach</select></div>
        <div class="col-md-2"><button class="btn btn-sm btn-light w-100">Show</button></div></form></div></div>

    <div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
        <thead class="table-light"><tr><th>Venue</th><th>Lecture periods a week</th><th>Average class fills</th><th>Examination sittings</th><th class="text-end">Bookings (90 days)</th></tr></thead>
        <tbody>
        @foreach ($rows as $r)
            @php $flag = fn ($s) => $s === null ? '' : ($s >= 85 ? 'bg-danger' : ($s < 20 ? 'bg-warning' : 'bg-success')); @endphp
            <tr><td><a href="{{ route('venues.show', $r['venue']) }}">{{ $r['venue']->name }}</a> <span class="text-muted fs-12">{{ $r['venue']->code }}</span></td>
                <td style="min-width:200px">@if ($r['lecture_share'] !== null)<div class="d-flex align-items-center gap-2"><div class="progress flex-grow-1" style="height:6px"><div class="progress-bar {{ $flag($r['lecture_share']) }}" style="width:{{ min(100, $r['lecture_share']) }}%"></div></div><span class="fs-12 text-nowrap">{{ $r['lecture_periods'] }} / {{ $periods }} ({{ $r['lecture_share'] }}%)</span></div>@else<span class="text-muted">—</span>@endif</td>
                <td>{{ $r['fill'] !== null ? $r['fill'] . '% of seats' : '—' }}</td>
                <td style="min-width:200px">@if ($r['exam_share'] !== null)<div class="d-flex align-items-center gap-2"><div class="progress flex-grow-1" style="height:6px"><div class="progress-bar {{ $flag($r['exam_share']) }}" style="width:{{ min(100, $r['exam_share']) }}%"></div></div><span class="fs-12 text-nowrap">{{ $r['exam_sittings'] }} / {{ $sittings }} ({{ $r['exam_share'] }}%)</span></div>@else<span class="text-muted">—</span>@endif</td>
                <td class="text-end">{{ $r['bookings'] }}</td></tr>
        @endforeach
        </tbody></table></div>
        <div class="card-body text-muted fs-12">Red: heavily used (85% or more). Orange: under-used (less than 20%). A class that fills a small share of its room's seats is a sign a smaller room would do.</div></div>
</div></div></div>
@endsection
