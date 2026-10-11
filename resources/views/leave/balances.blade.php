@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Leave balances', 'icon' => 'ri-scales-line', 'subtitle' => 'Entitlement, carry-over, adjustments and days taken for every member of staff.'])
    @include('academics.partials.alerts')
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    <div class="card"><div class="card-header d-flex flex-wrap gap-2 justify-content-between">
        <form method="GET" class="d-flex flex-wrap gap-2">
            <select name="type" class="form-select form-select-sm" style="width:auto">@foreach ($types as $t)<option value="{{ $t->id }}" @selected($type?->id === $t->id)>{{ $t->name }}</option>@endforeach</select>
            <select name="year" class="form-select form-select-sm" style="width:auto">@foreach (range(now()->year + 1, now()->year - 4) as $y)<option @selected($y === $year)>{{ $y }}</option>@endforeach</select>
            <select name="department_id" class="form-select form-select-sm" style="width:auto;max-width:220px"><option value="">All departments</option>@foreach ($departments as $id => $n)<option value="{{ $id }}" @selected(request('department_id') == $id)>{{ $n }}</option>@endforeach</select>
            <input type="search" name="q" value="{{ request('q') }}" class="form-control form-control-sm" style="width:180px" placeholder="Name">
            <button class="btn btn-sm btn-primary">Show</button>
        </form>
        @can('Change leave balances')
            <form method="POST" action="{{ route('leave.balances.carryover') }}" onsubmit="return confirm('Carry unused days from {{ $year - 1 }} into {{ $year }} for everyone? It is safe to repeat.')">@csrf<input type="hidden" name="from" value="{{ $year - 1 }}"><button class="btn btn-sm btn-soft-primary">Carry over {{ $year - 1 }} → {{ $year }}</button></form>
        @endcan
    </div>
        <div class="table-responsive"><table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Staff</th><th class="text-end">Entitled</th><th class="text-end">Carried</th><th class="text-end">Adjusted</th><th class="text-end">Taken</th><th class="text-end">Pending</th><th class="text-end">Available</th>@can('Change leave balances')<th>Adjust</th>@endcan</tr></thead>
            <tbody>
            @forelse ($staff as $u)
                @php $b = $rows[$u->id] ?? null; @endphp
                <tr><td><strong>{{ $u->name }}</strong><div class="text-muted fs-12">{{ $u->staffProfile?->department?->name ?? $u->staffProfile?->unit ?? 'No staff profile' }}</div></td>
                    @if ($b)<td class="text-end">{{ $b->entitled }}</td><td class="text-end">{{ $b->carried + 0 }}</td><td class="text-end">{{ $b->adjusted + 0 }}</td><td class="text-end">{{ $b->taken }}</td><td class="text-end">{{ $b->pending }}</td><td class="text-end fw-semibold">{{ $b->available() + 0 }}</td>@endif
                    @can('Change leave balances')
                        <td><form method="POST" action="{{ route('leave.balances.adjust') }}" class="d-flex gap-1">@csrf
                            <input type="hidden" name="user_id" value="{{ $u->id }}"><input type="hidden" name="leave_type_id" value="{{ $type?->id }}"><input type="hidden" name="year" value="{{ $year }}">
                            <input type="number" step="0.5" name="days" class="form-control form-control-sm" style="width:70px" placeholder="±days" required>
                            <input name="note" class="form-control form-control-sm" style="width:160px" placeholder="Reason" required>
                            <button class="btn btn-sm btn-light">Save</button></form></td>
                    @endcan</tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No staff found.</td></tr>
            @endforelse
            </tbody></table></div>
        <div class="card-body">{{ $staff->links() }}</div></div>

    @if ($ledger->isNotEmpty())
        <div class="card"><div class="card-header"><h6 class="card-title mb-0">Recent carry-overs and adjustments</h6></div>
            <div class="table-responsive"><table class="table table-sm mb-0 fs-13"><tbody>
                @foreach ($ledger as $l)<tr><td>{{ $l->created_at->format('j M Y') }}</td><td>{{ $l->user?->name }}</td><td>{{ $l->kind === 'carryover' ? 'Carried over' : 'Adjusted' }}</td><td class="text-end">{{ sprintf('%+g', $l->days) }}</td><td>{{ $l->note }}</td><td class="text-muted">{{ $l->creator?->name }}</td></tr>@endforeach
            </tbody></table></div></div>
    @endif
</div></div></div>
@endsection
