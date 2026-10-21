@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Timetables', 'icon' => 'ri-calendar-2-line', 'subtitle' => 'Lecture and examination timetables. The committee prepares and generates them; they are approved, then published to students and staff.'])
    @include('academics.partials.alerts')
    <div class="card">
        <div class="card-header d-flex flex-wrap gap-2 justify-content-between">
            <form method="GET" class="d-flex gap-2">
                <select name="kind" class="form-select form-select-sm" style="width:auto"><option value="">Lecture and examination</option>@foreach (\App\Models\Timetable::KINDS as $k => $l)<option value="{{ $k }}" @selected(request('kind') === $k)>{{ $l }}</option>@endforeach</select>
                <select name="session" class="form-select form-select-sm" style="width:auto"><option value="">All sessions</option>@foreach ($sessions as $id => $n)<option value="{{ $id }}" @selected((int) request('session') === $id)>{{ $n }}</option>@endforeach</select>
                <button class="btn btn-sm btn-light">Filter</button>
            </form>
            <div class="d-flex gap-2">
                @can('Set staff availability')<a href="{{ route('staff-availability.index') }}" class="btn btn-sm btn-soft-secondary"><i class="ri-user-unfollow-line me-1"></i>Staff availability</a>@endcan
                @can('Create timetables')<a href="{{ route('timetables.wizard') }}" class="btn btn-sm btn-success"><i class="ri-magic-line me-1"></i>Generation wizard</a>@endcan
                @can('Create timetables')<a href="{{ route('timetables.create') }}" class="btn btn-sm btn-primary"><i class="ri-add-line me-1"></i>New timetable</a>@endcan
            </div>
        </div>
        <div class="table-responsive"><table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Timetable</th><th>Session</th><th>Kind</th><th>Status</th><th>Last generated</th><th style="width:1%" class="text-nowrap">Last changed by</th><th></th></tr></thead>
            <tbody>
            @forelse ($timetables as $t)
                <tr><td><a href="{{ route('timetables.show', $t) }}" class="fw-semibold">{{ $t->title }}</a>@if ($t->summary)<div class="text-muted fs-12">{{ $t->summary['placed'] ?? 0 }} placed · {{ $t->summary['unplaced'] ?? 0 }} not placed · {{ $t->summary['violations'] ?? 0 }} hard-rule problem(s)</div>@endif</td>
                    <td>{{ $t->session?->name }} · {{ $t->semesterType?->name }}</td><td>{{ \App\Models\Timetable::KINDS[$t->kind] }}</td>
                    <td><span class="badge {{ \App\Models\Timetable::BADGES[$t->status] }}">{{ \App\Models\Timetable::STATUSES[$t->status] }}</span></td>
                    <td class="fs-13">{{ $t->generated_at?->format('j M Y H:i') ?? 'Not yet' }}</td>
                    <td><x-last-change :record="$t" :among="$timetables" /></td>
                    <td class="text-end"><a href="{{ route('timetables.show', $t) }}" class="btn btn-sm btn-light">Open</a></td></tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-4">No timetables yet.</td></tr>
            @endforelse
            </tbody></table></div>
        @if ($timetables->hasPages())<div class="card-body">{{ $timetables->links() }}</div>@endif
    </div>
</div></div></div>
@endsection
