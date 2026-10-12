@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'My Health', 'icon' => 'ri-heart-pulse-line', 'subtitle' => 'Only the health centre can see this. Every time they open it, it is listed at the bottom of this page.'])
    @include('academics.partials.alerts')
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <div class="row">
        <div class="col-lg-7">
            <form method="POST" action="{{ route('my.health.update') }}" class="card">@csrf @method('PUT')
                <div class="card-header"><h6 class="card-title mb-0">My details</h6></div>
                <div class="card-body">@include('health._details-form', ['record' => $record])</div>
                <div class="card-footer text-end"><button class="btn btn-primary">Save</button></div>
            </form>
        </div>
        <div class="col-lg-5">
            <div class="card"><div class="card-body">
                <h6>Medical examination</h6>
                @if ($record->examined_at)
                    <p class="mb-0">Examined on {{ $record->examined_at->format('j F Y') }}: <span class="badge {{ \App\Models\HealthRecord::FITNESS_BADGES[$record->fitness] }}">{{ \App\Models\HealthRecord::FITNESS[$record->fitness] }}</span></p>
                @else
                    <p class="text-muted mb-0">Not yet examined. New students are examined at the health centre during registration.</p>
                @endif
            </div></div>
            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Visits and excuse notes</h6></div><ul class="list-group list-group-flush">
                @forelse ($visits as $v)
                    <li class="list-group-item fs-13 d-flex justify-content-between"><span>{{ $v->seen_at->format('j M Y') }} · {{ \App\Models\ClinicVisit::CATEGORIES[$v->category] }}</span>
                        @if ($v->hasExcuse())<a href="{{ route('my.health.excuse', $v) }}" target="_blank">Excuse note ({{ $v->rest_days }} day{{ $v->rest_days === 1 ? '' : 's' }})</a>@endif</li>
                @empty
                    <li class="list-group-item text-muted fs-13">None.</li>
                @endforelse
            </ul></div>
            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Who opened my record</h6></div><ul class="list-group list-group-flush">
                @forelse ($log as $a)
                    <li class="list-group-item fs-12 d-flex justify-content-between"><span>{{ $a->viewer?->name ?? 'Unknown' }}: {{ str_replace('_', ' ', $a->action) }}</span><span class="text-muted">{{ $a->created_at->format('j M Y, g:ia') }}</span></li>
                @empty
                    <li class="list-group-item text-muted fs-13">Nobody yet.</li>
                @endforelse
            </ul></div>
        </div>
    </div>
</div></div></div>
@endsection
