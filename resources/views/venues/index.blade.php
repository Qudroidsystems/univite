@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Venues', 'icon' => 'ri-building-2-line', 'subtitle' => 'Every lecture hall, classroom, laboratory and examination hall. Both timetables read venues from here.'])
    @include('academics.partials.alerts')

    <div class="row g-3 mb-3">
        @foreach ([['Venues in use', $stats['venues'], 'ri-building-2-line', 'primary'], ['Teaching seats', number_format($stats['teaching']), 'ri-presentation-line', 'info'], ['Examination seats', number_format($stats['exam']), 'ri-file-edit-line', 'success'], ['Out of use', $stats['out'], 'ri-tools-line', 'warning'], ['Open faults', $stats['faults'], 'ri-alarm-warning-line', 'danger']] as [$label, $value, $icon, $c])
            <div class="col-6 col-md"><div class="card mb-0"><div class="card-body d-flex align-items-center gap-3">
                <span class="avatar-title rounded bg-{{ $c }}-subtle text-{{ $c }} fs-4" style="width:42px;height:42px"><i class="{{ $icon }}"></i></span>
                <div><div class="fs-5 fw-semibold">{{ $value }}</div><div class="text-muted fs-12">{{ $label }}</div></div></div></div></div>
        @endforeach
    </div>

    <div class="card">
        <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <form method="GET" class="d-flex flex-wrap gap-2">
                <input type="search" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Name or code" style="width:180px">
                <select name="type" class="form-select form-select-sm" style="width:auto"><option value="">All types</option>@foreach ($types as $k => $v)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $v }}</option>@endforeach</select>
                <select name="building" class="form-select form-select-sm" style="width:auto"><option value="">All buildings</option>@foreach ($buildings as $b)<option @selected(request('building') === $b)>{{ $b }}</option>@endforeach</select>
                <select name="status" class="form-select form-select-sm" style="width:auto"><option value="">Any status</option>@foreach (\App\Models\Venue::STATUSES as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select>
                <button class="btn btn-sm btn-light">Filter</button>
            </form>
            <div class="d-flex gap-2">
                <a href="{{ route('venues.availability') }}" class="btn btn-sm btn-soft-info"><i class="ri-search-line me-1"></i>Find a free venue</a>
                <a href="{{ route('venues.usage') }}" class="btn btn-sm btn-soft-secondary"><i class="ri-bar-chart-2-line me-1"></i>Usage</a>
                @can('Create venues')<a href="{{ route('venues.create') }}" class="btn btn-sm btn-primary"><i class="ri-add-line me-1"></i>Add venue</a>@endcan
            </div>
        </div>
        <div class="table-responsive"><table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Venue</th><th>Type</th><th>Location</th><th class="text-end">Seats</th><th class="text-end">Teaching</th><th class="text-end">Exams</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($venues as $v)
                <tr class="{{ $v->is_active ? '' : 'opacity-50' }}">
                    <td><a href="{{ route('venues.show', $v) }}" class="fw-semibold">{{ $v->name }}</a><div class="text-muted fs-12"><code>{{ $v->code }}</code>@if ($v->department) · {{ $v->department->name }}@endif</div></td>
                    <td>{{ $v->type_label }}</td>
                    <td class="fs-13">{{ $v->location ?: '—' }}</td>
                    <td class="text-end">{{ number_format($v->capacity) }}</td>
                    <td class="text-end">{{ $v->allows('lecture') || $v->allows('practical') ? number_format($v->teaching_capacity) : '—' }}</td>
                    <td class="text-end">{{ $v->allows('exam') ? number_format($v->exam_capacity) : '—' }}</td>
                    <td><span class="badge {{ \App\Models\Venue::BADGES[$v->status] ?? '' }}">{{ \App\Models\Venue::STATUSES[$v->status] ?? $v->status }}</span>
                        @if ($v->open_issues_count)<span class="badge bg-danger-subtle text-danger">{{ $v->open_issues_count }} fault(s)</span>@endif
                        @unless ($v->is_active)<span class="badge bg-light text-muted">Inactive</span>@endunless</td>
                    <td class="text-end"><a href="{{ route('venues.show', $v) }}" class="btn btn-sm btn-light">Open</a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No venues yet. Add the institution's halls, classrooms and laboratories so timetables can use them.</td></tr>
            @endforelse
            </tbody></table></div>
        @if ($venues->hasPages())<div class="card-body">{{ $venues->links() }}</div>@endif
    </div>
</div></div></div>
@endsection
