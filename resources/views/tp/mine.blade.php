@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Teaching Practice', 'icon' => 'ri-presentation-line'])
    @include('academics.partials.alerts')
    @forelse ($postings as $p)
        @php $ex = $p->exercise; @endphp
        <div class="card"><div class="card-header d-flex justify-content-between"><h6 class="card-title mb-0">{{ $ex->name }}</h6><span class="badge {{ \App\Models\TpPosting::BADGES[$p->status] }}">{{ \App\Models\TpPosting::STATUSES[$p->status] }}</span></div>
            <div class="card-body">
                @if ($ex->starts_on)<p class="fs-13 text-muted">From {{ $ex->starts_on->format('l j F Y') }}{{ $ex->ends_on ? ' to ' . $ex->ends_on->format('l j F Y') : '' }}</p>@endif
                @if ($p->status === 'posted')
                    <h5 class="mb-1">{{ $p->school->name }}</h5>
                    <div class="fs-13">{{ $p->school->address }}{{ $p->school->place() ? ', ' . $p->school->place() : '' }}</div>
                    <a href="{{ route('my.tp.letter', $p) }}" target="_blank" class="btn btn-sm btn-primary mt-2"><i class="ri-printer-line me-1"></i>Posting letter</a>
                    @if ($p->assessments->isNotEmpty())
                        <div class="mt-3 fs-13">Visits marked: {{ $p->assessments->count() }} of {{ $ex->visits_required }}
                            @if ($ex->scores_published_at && ($f = $tpService->finalScore($p)) !== null) · Final score {{ $f }}% @endif</div>
                    @endif
                @elseif ($ex->choices_open)
                    <form method="POST" action="{{ route('my.tp.choose', $p) }}" class="row g-2">@csrf
                        @for ($i = 0; $i < min($ex->max_choices, max(1, $ex->schools->count())); $i++)
                            <div class="col-md-4"><label class="form-label">Choice {{ $i + 1 }}</label><select name="choices[]" class="form-select"><option value="">—</option>
                                @foreach ($ex->schools as $s)<option value="{{ $s->id }}" @selected(($p->choices[$i] ?? null) === $s->id)>{{ $s->name }} ({{ $s->town }})</option>@endforeach</select></div>
                        @endfor
                        <div class="col-md-6"><label class="form-label">Subject you will teach</label><input name="subject" value="{{ $p->subject }}" class="form-control"></div>
                        <div class="col-12"><button class="btn btn-primary">Save choices</button></div>
                    </form>
                @else
                    <p class="mb-0 text-muted">You will be told when you are posted.</p>
                @endif
            </div></div>
    @empty
        <div class="alert alert-info">You are not on a teaching practice exercise.</div>
    @endforelse
</div></div></div>
@endsection
