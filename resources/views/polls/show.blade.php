@extends('layouts.master')

@section('content')
@php
    [$label, $badge] = $poll->phaseBadge();
    $phase = $poll->phase();
    $editable = ! $locked && $phase !== 'closed';
    $u = auth()->user();
@endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => $poll->title, 'icon' => 'ri-checkbox-multiple-line', 'back' => [route('polls.index'), 'All polls']])
    @include('academics.partials.alerts')

    <div class="card"><div class="card-body">
        <div class="d-flex flex-wrap justify-content-between gap-3">
            <div>
                <span class="badge {{ $badge }}">{{ $label }}</span>
                <span class="badge bg-light text-body">{{ \App\Models\Poll::KINDS[$poll->kind] }}</span>
                <span class="badge bg-light text-body">{{ $poll->is_secret ? 'Secret ballot' : 'Open ballot: voters are recorded against their answers' }}</span>
                @if ($poll->results_published_at)<span class="badge bg-primary-subtle text-primary">Results published {{ $poll->results_published_at->format('j M Y') }}</span>@endif
                <div class="mt-2"><strong>Who may vote:</strong> {{ $audience }}</div>
                <div><strong>Voting:</strong> {{ $poll->opens_at->format('l j M Y, g:ia') }} to {{ $poll->closes_at->format('l j M Y, g:ia') }}</div>
                <div><strong>Voters see results:</strong> {{ strtolower(\App\Models\Poll::RESULTS[$poll->results_shown]) }}</div>
                @if ($poll->description)<div class="text-muted mt-2" style="white-space:pre-line">{{ $poll->description }}</div>@endif
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-start">
                @can('Create polls')@if ($phase !== 'closed')<a href="{{ route('polls.edit', $poll) }}" class="btn btn-light"><i class="ri-edit-line me-1"></i>Edit</a>@endif @endcan
                @can('Publish polls')
                    @if ($phase === 'draft')
                        <form method="POST" action="{{ route('polls.publish', $poll) }}">@csrf<button class="btn btn-success"><i class="ri-send-plane-line me-1"></i>Publish</button></form>
                    @elseif (in_array($phase, ['scheduled', 'open']) && ! $locked)
                        <form method="POST" action="{{ route('polls.unpublish', $poll) }}">@csrf<button class="btn btn-light">Back to draft</button></form>
                    @endif
                    @if (in_array($phase, ['scheduled', 'open']))
                        <form method="POST" action="{{ route('polls.close', $poll) }}" onsubmit="return confirm('Close voting now? Nobody else will be able to vote.')">@csrf<button class="btn btn-warning">Close voting now</button></form>
                    @endif
                    @if ($phase === 'closed' && ! $poll->results_published_at)
                        <form method="POST" action="{{ route('polls.results.publish', $poll) }}">@csrf<button class="btn btn-primary"><i class="ri-megaphone-line me-1"></i>Publish results</button></form>
                    @endif
                @endcan
                @if ($phase !== 'draft')<a href="{{ route('polls.export', $poll) }}" class="btn btn-light"><i class="ri-download-2-line me-1"></i>Results (CSV)</a>@endif
                @can('Delete polls')@unless ($locked)<form method="POST" action="{{ route('polls.destroy', $poll) }}" onsubmit="return confirm('Delete this poll?')">@csrf @method('DELETE')<button class="btn btn-outline-danger">Delete</button></form>@endunless @endcan
            </div>
        </div>
    </div></div>

    <div class="row">
        <div class="col-lg-{{ $results ? 6 : 12 }}">
            <div class="card"><div class="card-header"><h6 class="card-title mb-0">{{ $poll->isElection() ? 'Offices and candidates' : 'Questions' }}</h6>
                @if ($locked)<div class="text-muted fs-12">Fixed now that voting has started.</div>@endif</div>
                <div class="card-body">
                @forelse ($poll->questions as $i => $q)
                    <div class="border rounded p-3 mb-3">
                        <div class="d-flex justify-content-between gap-2">
                            <div><span class="text-muted">{{ $i + 1 }}.</span> <strong>{{ $q->prompt }}</strong>
                                <div class="text-muted fs-12">{{ \App\Models\PollQuestion::TYPES[$q->type] }}@if ($q->type === 'multiple' && $q->max_choices) (up to {{ $q->max_choices }})@endif · {{ $q->is_required ? 'Must be answered' : 'May be skipped' }}</div></div>
                            @if ($editable && $u->can('Create polls'))
                                <form method="POST" action="{{ route('polls.questions.destroy', $q) }}" onsubmit="return confirm('Remove this {{ $poll->isElection() ? 'office' : 'question' }}?')">@csrf @method('DELETE')<button class="btn btn-sm btn-link text-danger p-0">Remove</button></form>
                            @endif
                        </div>
                        @if ($q->type !== 'text')
                            <ul class="list-unstyled mb-0 mt-2">
                                @foreach ($q->options as $o)
                                    <li class="d-flex align-items-center gap-2 py-1 border-top">
                                        @if ($o->photo_url)<img src="{{ $o->photo_url }}" alt="" class="rounded-circle" style="width:28px;height:28px;object-fit:cover">@endif
                                        <span class="flex-grow-1">{{ $o->label }}@if ($o->user) <span class="text-muted fs-12">· {{ $o->user->student?->matric_number ?? $o->user->staffProfile?->staff_number ?? $o->user->email }}</span>@endif
                                            @if ($o->description)<div class="text-muted fs-12">{{ \Illuminate\Support\Str::limit($o->description, 140) }}</div>@endif</span>
                                        @if ($editable && $u->can('Create polls'))
                                            <form method="POST" action="{{ route('polls.options.destroy', $o) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-link text-danger p-0" title="Remove"><i class="ri-close-line"></i></button></form>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                            @if ($editable && $u->can('Create polls'))
                                <form method="POST" action="{{ route('polls.options.store', $q) }}" enctype="multipart/form-data" class="row g-2 mt-2">@csrf
                                    @if ($poll->isElection())
                                        <div class="col-md-4"><input name="person" class="form-control form-control-sm" placeholder="Matric no., staff no. or email"></div>
                                        <div class="col-md-4"><input name="label" class="form-control form-control-sm" placeholder="Name on the ballot (optional)"></div>
                                        <div class="col-md-4"><input type="file" name="photo" accept="image/*" class="form-control form-control-sm"></div>
                                        <div class="col-md-9"><input name="description" class="form-control form-control-sm" placeholder="Short manifesto or note (optional)"></div>
                                        <div class="col-md-3"><button class="btn btn-sm btn-primary w-100">Add candidate</button></div>
                                    @else
                                        <div class="col-md-9"><input name="label" class="form-control form-control-sm" placeholder="Another answer" required></div>
                                        <div class="col-md-3"><button class="btn btn-sm btn-primary w-100">Add answer</button></div>
                                    @endif
                                </form>
                            @endif
                        @endif
                    </div>
                @empty
                    <p class="text-muted">Nothing yet.</p>
                @endforelse

                @if ($editable && $u->can('Create polls'))
                    <form method="POST" action="{{ route('polls.questions.store', $poll) }}" class="border rounded p-3 bg-light">@csrf
                        <h6>{{ $poll->isElection() ? 'Add an office' : 'Add a question' }}</h6>
                        <div class="row g-2">
                            <div class="col-12"><input name="prompt" class="form-control" required maxlength="500" placeholder="{{ $poll->isElection() ? 'President' : 'How satisfied are you with the library opening hours?' }}"></div>
                            @unless ($poll->isElection())
                                <div class="col-md-5"><select name="type" class="form-select">@foreach (\App\Models\PollQuestion::TYPES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                                <div class="col-md-3"><input type="number" name="max_choices" min="2" class="form-control" placeholder="Most choices"></div>
                                <div class="col-12"><textarea name="options" rows="3" class="form-control" placeholder="Answers, one per line"></textarea></div>
                            @endunless
                            <div class="col-md-8"><div class="form-check"><input type="hidden" name="is_required" value="0"><input type="checkbox" name="is_required" value="1" class="form-check-input" id="req" checked><label for="req" class="form-check-label">Must be answered</label></div></div>
                            <div class="col-md-4 text-end"><button class="btn btn-primary">Add</button></div>
                        </div>
                    </form>
                @endif
            </div></div>
        </div>
        @if ($results)
            <div class="col-lg-6"><div class="card"><div class="card-header"><h6 class="card-title mb-0">{{ $phase === 'closed' ? 'Results' : 'Votes so far' }}</h6></div>
                <div class="card-body">@include('polls._results')</div></div></div>
        @endif
    </div>
</div></div></div>
@endsection
