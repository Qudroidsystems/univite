@extends('layouts.master')

@section('content')
@php $days = $entries->groupBy('day'); @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid" style="max-width:1100px">
    @include('partials.page-title', ['title' => 'My timetable', 'icon' => 'ri-calendar-2-line', 'subtitle' => $semester ? $semester->session?->name . ' · ' . $semester->name : null])
    @if ($timetables->isEmpty())
        <div class="card"><div class="card-body text-muted">The lecture timetable for this semester has not been published yet. You will be notified when it is.</div></div>
    @elseif ($entries->isEmpty())
        <div class="card"><div class="card-body text-muted">None of your courses are on the published timetable yet. Register your courses, or check with your department.</div></div>
    @else
        <div class="row">
            @foreach (\App\Models\Timetable::DAYS as $d => $name)
                @if ($days->has($d))
                    <div class="col-md-6 col-xl-4"><div class="card"><div class="card-header py-2"><strong>{{ $name }}</strong></div>
                        <ul class="list-group list-group-flush">@foreach ($days[$d] as $e)
                            <li class="list-group-item"><div class="d-flex justify-content-between"><strong>{{ $e->item?->label }}</strong><span class="text-muted">{{ substr($e->starts_at, 0, 5) }}–{{ substr($e->ends_at, 0, 5) }}</span></div>
                                <div class="fs-13">{{ $e->course?->title }}</div><div class="text-muted fs-12"><i class="ri-map-pin-line"></i> {{ $e->venue?->name }}@if ($e->venue?->building) · {{ $e->venue->building }}@endif</div></li>
                        @endforeach</ul></div></div>
                @endif
            @endforeach
        </div>
    @endif
</div></div></div>
@endsection
