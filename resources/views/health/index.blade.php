@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Health Centre', 'icon' => 'ri-heart-pulse-line'])
    @include('academics.partials.alerts')
    <div class="row">
        <div class="col-lg-6"><div class="card"><div class="card-body">
            <form method="GET" class="d-flex gap-2"><input name="patient" class="form-control" placeholder="Matric number, staff number or email" required autofocus><button class="btn btn-primary">Open record</button></form>
            <div class="form-text">Opening a record is logged, and the person can see who opened it.</div>
        </div></div></div>
        <div class="col-lg-6"><div class="row">
            @foreach (['today' => 'Seen today', 'week' => 'Seen this week', 'resting' => 'Excused today'] as $k => $l)
                <div class="col-4"><div class="card"><div class="card-body text-center"><div class="fs-3 fw-semibold">{{ $counts[$k] }}</div><div class="text-muted fs-12">{{ $l }}</div></div></div></div>
            @endforeach
        </div></div>
    </div>
    <div class="card"><div class="card-header d-flex justify-content-between"><h6 class="card-title mb-0">Today</h6>@can('View clinic statistics')<a href="{{ route('clinic.stats') }}" class="fs-13">Statistics</a>@endcan</div>
        <div class="table-responsive"><table class="table align-middle mb-0"><thead class="table-light"><tr><th>Time</th><th>Patient</th><th>Reason</th><th>Outcome</th><th>Seen by</th></tr></thead><tbody>
            @forelse ($today as $v)
                <tr><td>{{ $v->seen_at->format('g:ia') }}</td><td><a href="{{ route('clinic.patient', $v->user) }}">{{ $v->user->name }}</a></td><td>{{ \App\Models\ClinicVisit::CATEGORIES[$v->category] }}</td>
                    <td>{{ \App\Models\ClinicVisit::OUTCOMES[$v->outcome] }}{{ $v->rest_days ? ' · ' . $v->rest_days . ' day(s) rest' : '' }}</td><td class="fs-13">{{ $v->clinician?->name }}</td></tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-4">No visits recorded today.</td></tr>
            @endforelse
        </tbody></table></div>
    </div>
</div></div></div>
@endsection
