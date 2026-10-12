@extends('layouts.master')

@section('content')
@php [$label, $badge] = $poll->phaseBadge(); @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid" style="max-width:820px">
    @include('partials.page-title', ['title' => $poll->title, 'icon' => 'ri-checkbox-multiple-line', 'back' => [route('my.polls'), 'All polls']])
    @include('academics.partials.alerts')
    <div class="card"><div class="card-body">
        <span class="badge {{ $badge }}">{{ $label }}</span>
        @if ($poll->is_secret)<span class="badge bg-light text-body"><i class="ri-lock-line"></i> Secret ballot</span>@endif
        @if ($poll->description)<div class="mt-2" style="white-space:pre-line">{{ $poll->description }}</div>@endif
    </div></div>

    @if (! $why)
        <form method="POST" action="{{ route('my.polls.vote', $poll) }}">@csrf
            @foreach ($poll->questions as $q)
                <div class="card"><div class="card-header"><h6 class="card-title mb-0">{{ $q->prompt }}@if ($q->is_required)<span class="text-danger">*</span>@endif</h6>
                    @if ($q->type === 'multiple')<div class="fs-12 text-muted">Choose {{ $q->max_choices ? 'up to ' . $q->max_choices : 'any number' }}.</div>@endif</div>
                    <div class="card-body">
                    @if ($q->type === 'text')
                        <textarea name="answers[{{ $q->id }}]" rows="3" maxlength="2000" class="form-control">{{ old("answers.{$q->id}") }}</textarea>
                    @else
                        @foreach ($q->options as $o)
                            <label class="d-flex align-items-center gap-3 border rounded p-2 mb-2" style="cursor:pointer">
                                <input type="{{ $q->type === 'multiple' ? 'checkbox' : 'radio' }}" name="answers[{{ $q->id }}]{{ $q->type === 'multiple' ? '[]' : '' }}" value="{{ $o->id }}" class="form-check-input m-0"
                                    @checked(in_array($o->id, (array) old("answers.{$q->id}", [])))>
                                @if ($o->photo_url)<img src="{{ $o->photo_url }}" alt="" class="rounded" style="width:56px;height:56px;object-fit:cover">@endif
                                <span><span class="fw-semibold">{{ $o->label }}</span>@if ($o->description)<div class="fs-12 text-muted">{{ $o->description }}</div>@endif</span>
                            </label>
                        @endforeach
                        @unless ($q->is_required)<div class="fs-12 text-muted">You may leave this blank.</div>@endunless
                    @endif
                </div></div>
            @endforeach
            <div class="card"><div class="card-body">
                <div class="form-check mb-3"><input type="checkbox" name="confirm" value="1" class="form-check-input" id="confirm"><label for="confirm" class="form-check-label">I have checked my choices. I understand a vote cannot be changed once cast.</label></div>
                <button class="btn btn-primary btn-lg"><i class="ri-checkbox-circle-line me-1"></i>Cast my vote</button>
            </div></div>
        </form>
    @else
        <div class="alert {{ $voted ? 'alert-success' : 'alert-info' }}">{{ $voted ? 'You have voted in this poll. Thank you.' : $why }}</div>
    @endif

    @if ($results)
        <div class="card"><div class="card-header"><h6 class="card-title mb-0">{{ $poll->phase() === 'closed' ? 'Results' : 'Votes so far' }}</h6></div><div class="card-body">@include('polls._results')</div></div>
    @elseif ($poll->phase() !== 'draft')
        <p class="text-muted fs-13">Results will be shown {{ ['live' => 'here', 'after_close' => 'here when voting closes', 'on_publish' => 'here once they are published'][$poll->results_shown] }}.</p>
    @endif
</div></div></div>
@endsection
