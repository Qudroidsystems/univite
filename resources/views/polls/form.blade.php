@extends('layouts.master')

@section('content')
@php $locked = $poll->exists && $poll->isLocked(); @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => $poll->exists ? 'Edit poll' : 'New poll or election', 'icon' => 'ri-checkbox-multiple-line', 'back' => [$poll->exists ? route('polls.show', $poll) : route('polls.index'), 'Back']])
    @include('academics.partials.alerts')
    @if ($locked)<div class="alert alert-info">People have already voted, so only the description and a later closing time can be changed.</div>@endif
    <form method="POST" action="{{ $poll->exists ? route('polls.update', $poll) : route('polls.store') }}" class="card"><div class="card-body">@csrf @if ($poll->exists) @method('PUT') @endif
        <div class="row g-3">
            <div class="col-md-8"><label class="form-label">Title</label><input name="title" value="{{ old('title', $poll->title) }}" class="form-control" required maxlength="200" placeholder="Students' Union elections 2026/2027"></div>
            <div class="col-md-4"><label class="form-label">Kind</label><select name="kind" class="form-select">@foreach (\App\Models\Poll::KINDS as $k => $l)<option value="{{ $k }}" @selected(old('kind', $poll->kind) === $k)>{{ $l }}</option>@endforeach</select>
                <div class="form-text">An election has offices, each with candidates; voters choose one per office.</div></div>
            <div class="col-12"><label class="form-label">Description or instructions</label><textarea name="description" rows="3" class="form-control">{{ old('description', $poll->description) }}</textarea></div>
            <div class="col-md-4"><label class="form-label">Voting opens</label><input type="datetime-local" name="opens_at" value="{{ old('opens_at', $poll->opens_at?->format('Y-m-d\TH:i')) }}" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label">Voting closes</label><input type="datetime-local" name="closes_at" value="{{ old('closes_at', $poll->closes_at?->format('Y-m-d\TH:i')) }}" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label">Show results to voters</label><select name="results_shown" class="form-select">@foreach (\App\Models\Poll::RESULTS as $k => $l)<option value="{{ $k }}" @selected(old('results_shown', $poll->results_shown) === $k)>{{ $l }}</option>@endforeach</select></div>
            <div class="col-12"><div class="form-check form-switch"><input type="hidden" name="is_secret" value="0"><input type="checkbox" name="is_secret" value="1" class="form-check-input" id="secret" @checked(old('is_secret', $poll->is_secret))><label for="secret" class="form-check-label">Secret ballot: nobody, including administrators, can see how a person voted</label></div></div>
            <div class="col-12"><h6 class="mt-2">Who may vote</h6>@include('partials.audience', ['audience' => $poll->exists ? $poll->audienceData() : null])</div>
        </div>
    </div><div class="card-footer text-end"><button class="btn btn-primary">{{ $poll->exists ? 'Save' : 'Save as draft' }}</button></div></form>
</div></div></div>
@endsection
