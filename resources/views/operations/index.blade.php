@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="page-title-box"><h4 class="mb-0">Operation history</h4></div>
            @include('academics.partials.alerts')
            <form method="GET" class="card"><div class="card-body row g-2">
                <div class="col-md-2"><input type="text" name="q" value="{{ request('q') }}" class="form-control" placeholder="Operation ID or record"></div>
                <div class="col-md-2"><select name="module" class="form-select"><option value="">Any module</option>@foreach ($modules as $m)<option value="{{ $m }}" @selected(request('module') === $m)>{{ ucfirst($m) }}</option>@endforeach</select></div>
                <div class="col-md-2"><select name="session" class="form-select"><option value="">Any session</option>@foreach ($sessions as $id => $n)<option value="{{ $id }}" @selected((string) request('session') === (string) $id)>{{ $n }}</option>@endforeach</select></div>
                <div class="col-md-2"><select name="user" class="form-select"><option value="">Anyone</option>@foreach ($users as $id => $n)<option value="{{ $id }}" @selected((string) request('user') === (string) $id)>{{ $n }}</option>@endforeach</select></div>
                <div class="col-md-1"><input type="date" name="from" value="{{ request('from') }}" class="form-control" title="From"></div>
                <div class="col-md-1"><input type="date" name="to" value="{{ request('to') }}" class="form-control" title="To"></div>
                <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Filter</button></div>
            </div></form>
            <div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
                <thead class="table-light"><tr><th>Operation</th><th>What</th><th>Session</th><th>By</th><th>When</th><th>Result</th></tr></thead>
                <tbody>
                @forelse ($batches as $b)
                    <tr><td><a href="{{ route('operations.show', $b) }}" class="fw-semibold">{{ $b->operation_id }}</a></td>
                        <td>{{ $b->label() }}<div class="text-muted small">{{ ucfirst($b->module) }}@if ($b->related_operation_id) · follows up an earlier operation @endif</div></td>
                        <td>{{ $b->session?->name ?? '—' }}</td><td>{{ $b->user?->name ?? 'System' }}</td>
                        <td class="text-nowrap">{{ $b->created_at->format('d M Y, g:i A') }}</td>
                        <td><span class="badge bg-{{ ['completed' => 'success', 'partial' => 'warning', 'failed' => 'danger', 'reversed' => 'secondary', 'corrected' => 'info'][$b->status] ?? 'secondary' }}">{{ ucfirst($b->status) }}</span>
                            <span class="text-muted small ms-1">{{ $b->success_count }} ok · {{ $b->failed_count }} failed · {{ $b->skipped_count }} skipped</span></td></tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">Nothing has been logged yet.</td></tr>
                @endforelse
                </tbody></table></div>
                <div class="card-footer">{{ $batches->links() }}</div></div>
        </div>
    </div>
</div>
@endsection
