@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="page-title-box"><h4 class="mb-0">Copy session setup</h4></div>
            @include('academics.partials.alerts')

            <form method="GET" action="{{ route('sessions.clone') }}" class="card">
                <input type="hidden" name="preview" value="1">
                <div class="card-body row g-3">
                    <p class="text-muted mb-0">Copies setup only. Registrations, scores, results, payments, invoices and student data are never copied, and the source session is never changed.</p>
                    <div class="col-md-6"><label class="form-label">Copy from</label>
                        <select name="source" class="form-select" required><option value="">Choose a session</option>@foreach ($sessions as $s)<option value="{{ $s->id }}" @selected($source?->id === $s->id)>{{ $s->name }}</option>@endforeach</select></div>
                    <div class="col-md-6"><label class="form-label">Copy into</label>
                        <select name="dest" class="form-select" required><option value="">Choose a session</option>@foreach ($sessions as $s)<option value="{{ $s->id }}" @selected($dest?->id === $s->id)>{{ $s->name }} ({{ ucfirst($s->status) }})</option>@endforeach</select></div>
                    <div class="col-12">
                        @foreach ($categories as $k => [$l, $h])
                            <div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="categories[]" value="{{ $k }}" id="c-{{ $k }}" @checked(in_array($k, $chosen, true) || ! $chosen)><label class="form-check-label" for="c-{{ $k }}"><strong>{{ $l }}</strong><span class="d-block text-muted small">{{ $h }}</span></label></div>
                        @endforeach
                    </div>
                </div>
                <div class="card-footer text-end"><button class="btn btn-light" type="submit"><i class="ri-eye-line me-1"></i>Preview</button></div>
            </form>

            @if ($preview)
                <div class="card"><div class="card-header"><h5 class="card-title mb-0">Preview: {{ $source->name }} → {{ $dest->name }}</h5></div>
                    <div class="card-body">
                        @foreach ($preview['problems'] as $p)<div class="alert alert-danger py-2">{{ $p }}</div>@endforeach
                        @foreach ($preview['rows'] as $k => $row)
                            <div class="mb-3"><strong>{{ $categories[$k][0] }}</strong>: {{ $row['create'] }} to copy, {{ $row['skip'] }} already there
                                @foreach ($row['notes'] as $n)<div class="text-warning small"><i class="ri-error-warning-line me-1"></i>{{ $n }}</div>@endforeach</div>
                        @endforeach
                        @if (! $preview['problems'])
                            @can('Copy session setup')
                            <form method="POST" action="{{ route('sessions.clone.run') }}" data-confirm="Copy {{ $preview['total'] }} item(s) into {{ $dest->name }}?">
                                @csrf <input type="hidden" name="source" value="{{ $source->id }}"><input type="hidden" name="dest" value="{{ $dest->id }}">
                                @foreach ($preview['rows'] as $k => $row)<input type="hidden" name="categories[]" value="{{ $k }}">@endforeach
                                <button class="btn btn-primary" type="submit" @disabled($preview['total'] === 0)><i class="ri-file-copy-line me-1"></i>{{ $preview['total'] ? 'Copy ' . $preview['total'] . ' item(s)' : 'Nothing new to copy' }}</button>
                            </form>
                            @endcan
                        @endif
                    </div>
                </div>
            @endif

            <div class="card"><div class="card-header"><h5 class="card-title mb-0">Recent copies</h5></div>
                <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>Operation</th><th>Into</th><th>When / by</th><th>Items</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    @forelse ($history as $b)
                        <tr><td><a href="{{ route('operations.show', $b) }}" class="fw-semibold">{{ $b->operation_id }}</a></td><td>{{ $b->session?->name }}</td>
                            <td class="text-nowrap">{{ $b->created_at->format('d M Y, g:i A') }}<div class="text-muted small">{{ $b->user?->name }}</div></td>
                            <td>{{ $b->success_count }} copied, {{ $b->skipped_count }} skipped</td><td><span class="badge bg-{{ $b->status === 'reversed' ? 'secondary' : 'success' }}">{{ ucfirst($b->status) }}</span></td>
                            <td class="text-end">@if ($b->status !== 'reversed')@can('Undo session copies')<form method="POST" action="{{ route('sessions.clone.undo', $b) }}" class="d-inline" data-confirm="Undo this copy? Anything already in use is kept."> @csrf <button class="btn btn-sm btn-outline-danger" type="submit">Undo</button></form>@endcan @endif</td></tr>
                    @empty
                        <tr><td colspan="6" class="text-muted text-center py-3">No copies yet.</td></tr>
                    @endforelse
                    </tbody></table></div>
            </div>
        </div>
    </div>
</div>
@endsection
