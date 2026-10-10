@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid" style="max-width:960px">
            <div class="page-title-box d-flex justify-content-between align-items-center"><h4 class="mb-0">Operation {{ $batch->operation_id }}</h4>
                <a href="{{ route('operations.index') }}" class="btn btn-sm btn-light"><i class="ri-arrow-left-line me-1"></i>History</a></div>
            @include('academics.partials.alerts')
            <div class="card"><div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-3">What</dt><dd class="col-sm-9">{{ str_replace('_', ' ', ucfirst($batch->type)) }} ({{ $batch->module }})</dd>
                    <dt class="col-sm-3">By</dt><dd class="col-sm-9">{{ $batch->user?->name ?? 'System' }}, {{ $batch->created_at->format('d M Y, g:i A') }}</dd>
                    <dt class="col-sm-3">Session</dt><dd class="col-sm-9">{{ $batch->session?->name ?? '—' }}</dd>
                    <dt class="col-sm-3">Result</dt><dd class="col-sm-9">{{ ucfirst($batch->status) }}: {{ $batch->success_count }} ok, {{ $batch->failed_count }} failed, {{ $batch->skipped_count }} skipped</dd>
                    @if ($batch->reason)<dt class="col-sm-3">Reason</dt><dd class="col-sm-9">{{ $batch->reason }}</dd>@endif
                    @if ($batch->related)<dt class="col-sm-3">Reverses / follows</dt><dd class="col-sm-9"><a href="{{ route('operations.show', $batch->related) }}">{{ $batch->related->operation_id }}</a></dd>@endif
                    @if ($followUps->isNotEmpty())<dt class="col-sm-3">Followed by</dt><dd class="col-sm-9">@foreach ($followUps as $f)<a href="{{ route('operations.show', $f) }}" class="me-2">{{ $f->operation_id }}</a>@endforeach</dd>@endif
                    @if ($batch->meta)<dt class="col-sm-3">Options</dt><dd class="col-sm-9"><code>{{ json_encode($batch->meta) }}</code></dd>@endif
                </dl>
            </div></div>
            <div class="card"><div class="card-header"><h5 class="card-title mb-0">Events ({{ $batch->events->count() }})</h5></div>
                <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>Time</th><th>Action</th><th>Record</th><th>Before</th><th>After</th><th>Note</th></tr></thead>
                    <tbody>
                    @forelse ($batch->events as $e)
                        <tr><td class="text-nowrap">{{ $e->created_at?->format('d M, g:i:s A') }}</td><td>{{ str_replace('_', ' ', $e->action) }}</td>
                            <td>{{ $e->subject_type }}@if ($e->subject_id) #{{ $e->subject_id }}@endif</td>
                            <td class="small"><code>{{ $e->before ? json_encode($e->before) : '—' }}</code></td><td class="small"><code>{{ $e->after ? json_encode($e->after) : '—' }}</code></td><td>{{ $e->note }}</td></tr>
                    @empty
                        <tr><td colspan="6" class="text-muted text-center py-3">No events.</td></tr>
                    @endforelse
                    </tbody></table></div></div>
        </div>
    </div>
</div>
@endsection
