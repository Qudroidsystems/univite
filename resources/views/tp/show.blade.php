@extends('layouts.master')

@section('content')
@php $u = auth()->user(); $schoolList = $exercise->schools; @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => $exercise->name, 'icon' => 'ri-presentation-line', 'subtitle' => $exercise->session->name . ($exercise->starts_on ? ' · ' . $exercise->starts_on->format('j M') . ' – ' . $exercise->ends_on?->format('j M Y') : '') . ' · ' . $exercise->visits_required . ' assessed visit(s)' . ($exercise->course ? ' · graded under ' . $exercise->course->code : ''), 'back' => [route('tp.exercises.index'), 'Teaching practice']])
    @include('academics.partials.alerts')
    @if (session('skipped') && count(session('skipped')))<div class="alert alert-warning"><ul class="mb-0 fs-13">@foreach (session('skipped') as $s)<li>{{ $s }}</li>@endforeach</ul></div>@endif

    <div class="d-flex flex-wrap gap-2 mb-3">
        @can('Set up teaching practice')<a href="{{ route('tp.exercises.edit', $exercise) }}" class="btn btn-sm btn-light">Settings</a>
            <form method="POST" action="{{ route('tp.exercises.enrol', $exercise) }}">@csrf<button class="btn btn-sm btn-light">Add students it is for</button></form>@endcan
        @can('Post students for teaching practice')<form method="POST" action="{{ route('tp.exercises.auto-post', $exercise) }}" onsubmit="return confirm('Post every student who has not been posted?')">@csrf<button class="btn btn-sm btn-primary" @disabled(! ($counts['pending'] ?? 0))>Post {{ $counts['pending'] ?? 0 }} waiting student(s)</button></form>@endcan
        <a href="{{ route('tp.exercises.export', $exercise) }}" class="btn btn-sm btn-light"><i class="ri-download-2-line me-1"></i>CSV</a>
        @can('Publish teaching practice scores')@if ($exercise->course)<form method="POST" action="{{ route('tp.exercises.publish', $exercise) }}" onsubmit="return confirm('Write the final scores into the {{ $exercise->course->code }} result sheet?')">@csrf<button class="btn btn-sm btn-success">Send scores to {{ $exercise->course->code }} results</button></form>@endif @endcan
    </div>

    <ul class="nav nav-tabs mb-3">
        @foreach (['postings' => 'Students', 'schools' => 'Schools (' . $schoolList->count() . ')', 'supervisors' => 'Supervisors (' . $exercise->supervisors->count() . ')'] as $k => $l)
            <li class="nav-item"><a class="nav-link {{ $tab === $k ? 'active' : '' }}" href="{{ route('tp.exercises.show', [$exercise, 'tab' => $k]) }}">{{ $l }}</a></li>
        @endforeach
    </ul>

    @if ($tab === 'schools')
        <form method="POST" action="{{ route('tp.exercises.schools', $exercise) }}" class="card">@csrf
            <div class="card-header fs-13 text-muted">Give each school taking students on this exercise its number of places; leave others empty.</div>
            <div class="table-responsive"><table class="table align-middle mb-0"><thead class="table-light"><tr><th>School</th><th>Where</th><th style="width:140px">Places</th><th class="text-center">Left</th></tr></thead><tbody>
                @foreach ($allSchools as $s)
                    @php $on = $schoolList->firstWhere('id', $s->id); @endphp
                    <tr><td>{{ $s->name }}</td><td class="fs-13">{{ $s->place() }}</td>
                        <td><input name="capacity[{{ $s->id }}]" type="number" min="0" value="{{ $on?->pivot->capacity }}" placeholder="{{ $s->default_capacity }}" class="form-control form-control-sm" @cannot('Set up teaching practice') disabled @endcannot></td>
                        <td class="text-center">{{ $on ? $left[$s->id] : '' }}</td></tr>
                @endforeach
            </tbody></table></div>
            @can('Set up teaching practice')<div class="card-footer text-end"><button class="btn btn-primary">Save schools</button></div>@endcan
        </form>
    @elseif ($tab === 'supervisors')
        <div class="card">
            @can('Set up teaching practice')
                <div class="card-body"><form method="POST" action="{{ route('tp.exercises.supervisors.store', $exercise) }}" class="row g-2">@csrf
                    <div class="col-md-5"><input name="person" class="form-control" placeholder="Staff number or email" required></div>
                    <div class="col-md-5"><select name="school_id" class="form-select">@foreach ($schoolList as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></div>
                    <div class="col-md-2"><button class="btn btn-primary w-100">Add</button></div></form></div>
            @endcan
            <ul class="list-group list-group-flush">
                @forelse ($exercise->supervisors->sortBy(fn ($s) => $s->school->name) as $s)
                    <li class="list-group-item d-flex justify-content-between"><span>{{ $s->school->name }} — <strong>{{ $s->user->name }}</strong></span>
                        @can('Set up teaching practice')<form method="POST" action="{{ route('tp.exercises.supervisors.destroy', [$exercise, $s]) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-link text-danger">Remove</button></form>@endcan</li>
                @empty
                    <li class="list-group-item text-muted">No supervisors yet.</li>
                @endforelse
            </ul>
        </div>
    @else
        <div class="card">
            <div class="card-header d-flex flex-wrap gap-2 justify-content-between">
                <div class="fs-13">@foreach (\App\Models\TpPosting::STATUSES as $k => $l)<span class="me-3">{{ $l }}: <strong>{{ $counts[$k] ?? 0 }}</strong></span>@endforeach</div>
                <form method="GET" class="d-flex gap-2"><input type="hidden" name="tab" value="postings">
                    <select name="school" class="form-select form-select-sm"><option value="">All schools</option>@foreach ($schoolList as $s)<option value="{{ $s->id }}" @selected(request('school') == $s->id)>{{ $s->name }}</option>@endforeach</select>
                    <select name="status" class="form-select form-select-sm"><option value="">Any status</option>@foreach (\App\Models\TpPosting::STATUSES as $k => $l)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select>
                    <input name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Name or matric no."><button class="btn btn-sm btn-light">Filter</button></form>
            </div>
            <div class="table-responsive"><table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>Student</th><th>School</th><th>Visits</th><th>Final</th><th style="width:1%" class="text-nowrap">Last changed by</th><th></th></tr></thead><tbody>
                @forelse ($postings as $p)
                    <tr>
                        <td>{{ $p->student->full_name }}<div class="fs-12 text-muted">{{ $p->student->matric_number }}{{ $p->subject ? ' · ' . $p->subject : '' }}</div></td>
                        <td>@if ($p->school){{ $p->school->name }}@else<span class="badge {{ \App\Models\TpPosting::BADGES[$p->status] }}">{{ \App\Models\TpPosting::STATUSES[$p->status] }}</span>@endif
                            @if ($p->choices && $p->status === 'pending')<div class="fs-12 text-muted">Wants: {{ collect($p->choices)->map(fn ($id) => $schoolList->firstWhere('id', $id)?->name)->filter()->implode(', ') }}</div>@endif</td>
                        <td class="fs-13">@foreach ($p->assessments as $a)<span class="badge bg-light text-body">{{ $a->visit_no }}: {{ $a->percent }}%</span> @endforeach</td>
                        <td class="fw-semibold">{{ ($f = $tpService->finalScore($p)) !== null ? $f . '%' : '' }}</td>
                        <td><x-last-change :record="$p" :among="$postings" /></td>
                        <td class="text-end text-nowrap">
                            @if ($p->status === 'posted')<a href="{{ route('tp.letter', $p) }}" target="_blank" class="btn btn-sm btn-light" title="Posting letter"><i class="ri-mail-line"></i></a>
                                @if ($u->can('Record teaching practice scores'))<a href="{{ route('tp.assess', $p) }}" class="btn btn-sm btn-light">Mark</a>@endif @endif
                            @can('Post students for teaching practice')
                                @if ($p->status !== 'withdrawn')
                                    <form method="POST" action="{{ route('tp.postings.post', $p) }}" class="d-inline-flex gap-1">@csrf
                                        <select name="school_id" class="form-select form-select-sm" style="width:160px">@foreach ($schoolList as $s)<option value="{{ $s->id }}" @selected($p->tp_school_id === $s->id)>{{ $s->name }} ({{ $left[$s->id] }})</option>@endforeach</select>
                                        <button class="btn btn-sm btn-light">{{ $p->school ? 'Move' : 'Post' }}</button></form>
                                    <form method="POST" action="{{ route('tp.postings.withdraw', $p) }}" class="d-inline" onsubmit="return confirm('Withdraw this student from the exercise?')">@csrf<button class="btn btn-sm btn-link text-danger">Withdraw</button></form>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-5">No students yet. Use "Add students it is for".</td></tr>
                @endforelse
            </tbody></table></div>
            @if ($postings->hasPages())<div class="card-footer">{{ $postings->links() }}</div>@endif
        </div>
    @endif
</div></div></div>
@endsection
