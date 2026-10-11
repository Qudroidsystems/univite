@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Leave register', 'icon' => 'ri-file-list-3-line', 'subtitle' => 'Every leave application, with who is away and who is due back.'])
    @include('academics.partials.alerts')
    <div class="row g-3 mb-3">
        @foreach ([['Awaiting approval', $stats['pending'], 'warning', ['status' => 'pending']], ['On leave today', $stats['away'], 'success', ['phase' => 'away']], ['Due back, not resumed', $stats['due'], 'danger', ['phase' => 'due']], ['Approval steps overdue', $stats['overdue'], 'danger', ['status' => 'pending']]] as [$l, $n, $c, $f])
            <div class="col-6 col-md-3"><a href="?{{ http_build_query($f) }}" class="card card-body mb-0 text-body"><div class="text-muted fs-12">{{ $l }}</div><div class="fs-3 fw-semibold text-{{ $n ? $c : 'muted' }}">{{ $n }}</div></a></div>
        @endforeach
    </div>
    <div class="card"><div class="card-header"><form method="GET" class="d-flex flex-wrap gap-2">
        <input type="search" name="q" value="{{ request('q') }}" class="form-control form-control-sm" style="width:200px" placeholder="Name or reference">
        <select name="status" class="form-select form-select-sm" style="width:auto"><option value="">Any status</option>@foreach (\App\Models\LeaveRequest::STATUSES as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select>
        <select name="phase" class="form-select form-select-sm" style="width:auto"><option value="">Any time</option><option value="away" @selected(request('phase') === 'away')>On leave today</option><option value="due" @selected(request('phase') === 'due')>Due back, not resumed</option><option value="late" @selected(request('phase') === 'late')>Resumed late</option></select>
        <select name="leave_type_id" class="form-select form-select-sm" style="width:auto"><option value="">Any leave type</option>@foreach ($types as $id => $n)<option value="{{ $id }}" @selected(request('leave_type_id') == $id)>{{ $n }}</option>@endforeach</select>
        <select name="department_id" class="form-select form-select-sm" style="width:auto;max-width:220px"><option value="">Any department</option>@foreach ($departments as $id => $n)<option value="{{ $id }}" @selected(request('department_id') == $id)>{{ $n }}</option>@endforeach</select>
        <input type="number" name="year" value="{{ request('year') }}" placeholder="Year" class="form-control form-control-sm" style="width:90px">
        <button class="btn btn-sm btn-primary">Filter</button>
    </form></div>
        <div class="table-responsive"><table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Reference</th><th>Staff</th><th>Leave</th><th>Dates</th><th>Days</th><th>Where it stands</th></tr></thead>
            <tbody>
            @forelse ($rows as $r)
                <tr><td><a href="{{ route('leave.show', $r) }}" class="fw-semibold">{{ $r->reference }}</a></td>
                    <td>{{ $r->user->name }}<div class="text-muted fs-12">{{ $r->user->staffProfile?->department?->name ?? $r->user->staffProfile?->unit }}</div></td>
                    <td>{{ $r->type->name }}</td><td>{{ $r->start_date->format('j M') }} – {{ $r->end_date->format('j M Y') }}</td><td>{{ $r->days }}</td>
                    <td><span class="badge {{ \App\Models\LeaveRequest::BADGES[$r->status] }}">{{ $r->phase }}</span>@if ($r->status === 'pending' && ($s = $r->currentStep()))<div class="fs-12 {{ $s->due_at?->isPast() ? 'text-danger' : 'text-muted' }}">With {{ $s->label }}{{ $s->due_at?->isPast() ? ', overdue' : '' }}</div>@endif</td></tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No applications match.</td></tr>
            @endforelse
            </tbody></table></div>
        <div class="card-body">{{ $rows->links() }}</div></div>
</div></div></div>
@endsection
