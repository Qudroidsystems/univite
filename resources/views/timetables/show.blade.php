@extends('layouts.master')

@section('content')
@php
    $u = auth()->user();
    $s = $t->summary ?? [];
    $cells = [];
    foreach ($entries->groupBy('unit_key') as $key => $rows) {
        $e = $rows->first();
        $row = $t->isExam() ? $e->date?->toDateString() : $e->day;
        $cells[$row][substr($e->starts_at, 0, 5)][] = ['key' => $key, 'e' => $e, 'venues' => $rows->map(fn ($r) => $r->venue?->code . ($rows->count() > 1 ? " ({$r->seats})" : ''))->filter()->implode(', '), 'locked' => $rows->contains('locked', true)];
    }
@endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => $t->title, 'icon' => $t->isExam() ? 'ri-file-edit-line' : 'ri-calendar-2-line', 'subtitle' => \App\Models\Timetable::KINDS[$t->kind] . ' · ' . $t->session?->name . ' · ' . $t->semesterType?->name, 'back' => [route('timetables.index'), 'Timetables']])
    @include('academics.partials.alerts')

    <div class="card"><div class="card-body d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <div><span class="badge fs-12 {{ \App\Models\Timetable::BADGES[$t->status] }}">{{ \App\Models\Timetable::STATUSES[$t->status] }}</span>
            @if ($t->approved_at)<span class="text-muted fs-12 ms-2">Approved by {{ $t->approver?->name }}, {{ $t->approved_at->format('j M Y') }}</span>@endif
            @if ($t->published_at)<span class="text-muted fs-12 ms-2">Published {{ $t->published_at->format('j M Y H:i') }}</span>@endif
            @if ($t->decision_note)<div class="fs-13 mt-1"><i class="ri-chat-quote-line me-1"></i>{{ $t->decision_note }}</div>@endif
            @if ($revision && $revision->id !== $t->id)<div class="fs-13 mt-1">Newer version: <a href="{{ route('timetables.show', $revision) }}">{{ $revision->title }}</a> ({{ \App\Models\Timetable::STATUSES[$revision->status] }})</div>@endif
        </div>
        <div class="d-flex flex-wrap gap-2">
            @if ($t->isEditable() && $canPrepare)
                <a href="{{ route('timetables.items', $t) }}" class="btn btn-sm btn-soft-secondary"><i class="ri-list-check me-1"></i>What to schedule</a>
                <a href="{{ route('timetables.settings', $t) }}" class="btn btn-sm btn-soft-secondary"><i class="ri-settings-3-line me-1"></i>Rules</a>
                <form method="POST" action="{{ route('timetables.generate', $t) }}" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').innerText='Generating…'">@csrf<button class="btn btn-sm btn-primary"><i class="ri-magic-line me-1"></i>{{ $t->generated_at ? 'Generate again' : 'Generate' }}</button></form>
                <a href="{{ route('timetables.whatif', $t) }}" class="btn btn-sm btn-soft-info"><i class="ri-flask-line me-1"></i>What if…</a>
                @if ($t->generated_at)<form method="POST" action="{{ route('timetables.submit', $t) }}">@csrf<button class="btn btn-sm btn-success"><i class="ri-send-plane-line me-1"></i>Send for approval</button></form>@endif
            @endif
            @if ($t->isExam())<a href="{{ route('timetables.invigilators', $t) }}" class="btn btn-sm btn-soft-secondary"><i class="ri-team-line me-1"></i>Invigilators</a>
                @canany(['View exam seating', 'Generate exam seating'])<a href="{{ route('seating.show', $t) }}" class="btn btn-sm btn-soft-secondary"><i class="ri-layout-grid-line me-1"></i>Seating</a>@endcanany @endif
            <a href="{{ route('timetables.print', $t) }}" target="_blank" class="btn btn-sm btn-light"><i class="ri-printer-line me-1"></i>Print</a>
            @if ($t->status === 'published')@can('Publish timetables')<form method="POST" action="{{ route('timetables.revise', $t) }}">@csrf<button class="btn btn-sm btn-soft-warning"><i class="ri-git-branch-line me-1"></i>Start a revision</button></form>@endcan @endif
            @if ($t->status === 'draft' && ! $t->published_at && $canPrepare)<form method="POST" action="{{ route('timetables.destroy', $t) }}" onsubmit="return confirm('Delete this draft timetable?')">@csrf @method('DELETE')<button class="btn btn-sm btn-soft-danger">Delete draft</button></form>@endif
        </div>
    </div></div>

    @if ($t->status === 'review')@can('Approve timetables')
        <div class="card border-warning"><div class="card-body row g-3">
            <form method="POST" action="{{ route('timetables.approve', $t) }}" class="col-md-7">@csrf
                <h6>Approve</h6>
                <input name="note" class="form-control form-control-sm mb-2" placeholder="Note (optional), e.g. Senate approval reference">
                @if (($s['unplaced'] ?? 0) > 0)<div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="accept_unplaced" value="1" id="au"><label class="form-check-label fs-13" for="au">Approve without the {{ $s['unplaced'] }} item(s) that could not be placed</label></div>@endif
                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="confirm" value="1" id="ok"><label class="form-check-label fs-13" for="ok">I have reviewed this timetable. It breaks no hard rule.</label></div>
                <button class="btn btn-sm btn-success">Approve</button></form>
            <form method="POST" action="{{ route('timetables.return', $t) }}" class="col-md-5">@csrf
                <h6>Return to the committee</h6><textarea name="note" rows="2" class="form-control form-control-sm mb-2" placeholder="What needs to change" required minlength="5"></textarea><button class="btn btn-sm btn-soft-danger">Return</button></form>
        </div></div>
    @endcan @endif
    @if ($t->status === 'approved')
        <div class="card border-success"><div class="card-body d-flex flex-wrap gap-3 align-items-center">
            @can('Publish timetables')
                <form method="POST" action="{{ route('timetables.publish', $t) }}" class="d-flex gap-2 align-items-center">@csrf
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="confirm" value="1" id="pub"><label class="form-check-label fs-13" for="pub">Publish to students and staff and notify them @if ($t->previous_id)(this replaces version {{ $t->version - 1 }})@endif</label></div>
                    <button class="btn btn-sm btn-success"><i class="ri-megaphone-line me-1"></i>Publish</button></form>
            @endcan
            @can('Approve timetables')<form method="POST" action="{{ route('timetables.return', $t) }}" class="d-flex gap-2">@csrf<input name="note" class="form-control form-control-sm" placeholder="Reason to return" required minlength="5"><button class="btn btn-sm btn-light">Return</button></form>@endcan
        </div></div>
    @endif

    <div class="row g-3 mb-3">
        @foreach ([['Placed', ($s['placed'] ?? 0) . ' / ' . ($s['units'] ?? 0), 'success'], ['Not placed', $s['unplaced'] ?? 0, ($s['unplaced'] ?? 0) ? 'warning' : 'success'], ['Hard rules broken', $s['violations'] ?? 0, ($s['violations'] ?? 0) ? 'danger' : 'success'], ['Soft score (lower is better)', $s['score'] ?? '—', 'info']] as [$l, $v, $c])
            <div class="col-6 col-md-3"><div class="card mb-0 border-{{ $c }}"><div class="card-body py-2"><div class="fs-4 fw-semibold text-{{ $c }}">{{ $v }}</div><div class="text-muted fs-12">{{ $l }}</div></div></div></div>
        @endforeach
    </div>

    @if (! empty($s['problems']))
        <div class="card border-danger"><div class="card-header bg-danger-subtle"><h6 class="card-title mb-0"><i class="ri-error-warning-line me-1"></i>Hard rules broken</h6><div class="fs-12">These must be fixed before approval. They usually come from a venue closing, someone going on leave, or a change of registrations after generating.</div></div>
            <ul class="list-group list-group-flush">@foreach ($s['problems'] as $m)<li class="list-group-item fs-13">{{ $m }}</li>@endforeach</ul></div>
    @endif
    @if (! empty($s['invigilation']['short']))
        <div class="card border-warning"><div class="card-header bg-warning-subtle"><h6 class="card-title mb-0">Not enough invigilators</h6><div class="fs-12">{{ $s['invigilation']['advice'] }}</div></div>
            <ul class="list-group list-group-flush">@foreach (array_slice($s['invigilation']['short'], 0, 15) as $m)<li class="list-group-item fs-13">{{ $m }}</li>@endforeach</ul></div>
    @endif

    @if ($unplaced->isNotEmpty())
        <div class="card border-warning"><div class="card-header bg-warning-subtle"><h6 class="card-title mb-0"><i class="ri-question-line me-1"></i>Why some could not be placed</h6><div class="fs-12">Nothing was forced: these were left out rather than break a hard rule.</div></div>
            <div class="card-body">
                @foreach ($unplaced as $item)
                    @foreach ($item->issues as $why)
                        <div class="mb-3 pb-2 border-bottom"><div class="fw-semibold">{{ $item->label }}@if (! $t->isExam() && $item->meetings > 1) · meeting {{ $why['meeting'] ?? '' }}@endif <span class="text-muted fw-normal fs-12">{{ $item->students }} {{ $t->isExam() ? 'candidates' : 'students' }}</span></div>
                            <ul class="mb-1 fs-13">@foreach ($why['reasons'] as $r)<li>{{ $r }}</li>@endforeach</ul>
                            @if (! empty($why['nearest']))<div class="fs-13 text-muted"><i class="ri-focus-3-line me-1"></i>Closest option: {{ $why['nearest'] }}</div>@endif
                            @if (! empty($why['suggestions']))<div class="fs-13 text-success"><i class="ri-lightbulb-line me-1"></i>{{ implode(' ', $why['suggestions']) }}</div>@endif
                        </div>
                    @endforeach
                @endforeach
            </div></div>
    @endif

    <div class="card">
        <div class="card-header d-flex flex-wrap justify-content-between gap-2 align-items-center"><h6 class="card-title mb-0">{{ $t->isExam() ? 'Examination timetable' : 'Weekly timetable' }}</h6>
            <form method="GET" class="d-flex gap-2"><select name="level" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">All levels</option>@foreach ($levels as $l)<option value="{{ $l }}" @selected((int) request('level') === $l)>{{ $l }} level</option>@endforeach</select>
                <select name="venue" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">All venues</option>@foreach ($venues as $id => $code)<option value="{{ $id }}" @selected((int) request('venue') === $id)>{{ $code }}</option>@endforeach</select></form></div>
        <div class="table-responsive">
            @if (! $grid['rows'])
                <div class="card-body text-muted">Nothing placed yet. @if ($t->isEditable() && $canPrepare)Check what to schedule and the rules, then press Generate.@endif</div>
            @else
                <table class="table table-bordered align-top mb-0 fs-12">
                    <thead class="table-light"><tr><th style="width:110px"></th>@foreach ($grid['cols'] as $label)<th class="text-center">{{ $label }}</th>@endforeach</tr></thead>
                    <tbody>
                    @foreach ($grid['rows'] as $rk => $rl)
                        <tr><th class="table-light">{{ $rl }}</th>
                            @foreach ($grid['cols'] as $ck => $cl)
                                <td style="min-width:120px">
                                    @foreach ($cells[$rk][$ck] ?? [] as $c)
                                        <a href="{{ route('timetables.entry', [$t, $c['key']]) }}" class="d-block mb-1 p-1 rounded text-body {{ $c['locked'] ? 'bg-warning-subtle' : 'bg-primary-subtle' }}" title="{{ $c['e']->course?->title }} · {{ $c['e']->when }}">
                                            <strong>{{ $c['e']->item?->label }}</strong>@if ($c['locked']) <i class="ri-lock-line"></i>@endif
                                            <div class="text-muted">{{ $c['venues'] ?: 'no venue' }}@if (! $t->isExam() && count($c['e']->slots) > 1) · {{ count($c['e']->slots) }} periods @endif</div></a>
                                    @endforeach
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        <div class="card-body text-muted fs-12 border-top">Click an entry to see where else it could go and move it. Moves are checked against every hard rule; a moved entry is locked (yellow) so generating again keeps it.</div>
    </div>
</div></div></div>
@endsection
