@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'My teaching and duties', 'icon' => 'ri-presentation-line', 'subtitle' => $semester ? $semester->session?->name . ' · ' . $semester->name : null])
    <div class="d-flex justify-content-end mb-2"><a href="{{ route('staff-availability.index') }}" class="btn btn-sm btn-soft-secondary"><i class="ri-user-unfollow-line me-1"></i>Times I am not available</a></div>
    <div class="row">
        <div class="col-lg-6"><div class="card"><div class="card-header"><h6 class="card-title mb-0">My classes</h6></div>
            <ul class="list-group list-group-flush">@forelse ($classes as $e)
                <li class="list-group-item d-flex justify-content-between"><div><strong>{{ $e->item?->label }}</strong> <span class="text-muted fs-13">{{ $e->course?->title }}</span><div class="text-muted fs-12">{{ $e->venue?->name }}</div></div><div class="text-end fs-13">{{ \App\Models\Timetable::DAYS[$e->day] ?? '' }}<div>{{ substr($e->starts_at, 0, 5) }}–{{ substr($e->ends_at, 0, 5) }}</div></div></li>
            @empty<li class="list-group-item text-muted">No published classes this semester.</li>@endforelse</ul></div>
            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Papers of my courses</h6></div>
            <ul class="list-group list-group-flush">@forelse ($papers as $rows)@php $e = $rows->first(); @endphp
                <li class="list-group-item d-flex justify-content-between"><div><strong>{{ $e->course?->code }}</strong><div class="text-muted fs-12">{{ $rows->map(fn ($r) => $r->venue?->name . ' (' . $r->seats . ')')->implode(', ') }}</div></div><div class="text-end fs-13">{{ $e->date?->format('D j M') }}<div>{{ substr($e->starts_at, 0, 5) }}–{{ substr($e->ends_at, 0, 5) }}</div></div></li>
            @empty<li class="list-group-item text-muted">None published.</li>@endforelse</ul></div></div>
        <div class="col-lg-6"><div class="card"><div class="card-header"><h6 class="card-title mb-0">My invigilation duties</h6></div>
            <ul class="list-group list-group-flush">@forelse ($duties as $d)
                <li class="list-group-item d-flex justify-content-between"><div><strong>{{ $d->venue?->name }}</strong> @if ($d->role === 'chief')<span class="badge bg-primary-subtle text-primary">Chief invigilator</span>@endif</div><div class="text-end fs-13">{{ $d->date->format('D j M Y') }}<div>{{ substr($d->starts_at, 0, 5) }}–{{ substr($d->ends_at, 0, 5) }}</div></div></li>
            @empty<li class="list-group-item text-muted">No upcoming duties.</li>@endforelse</ul></div></div>
    </div>
</div></div></div>
@endsection
