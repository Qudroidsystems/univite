@extends('layouts.master')

@section('content')
@php
    $icons = \App\Services\Setup\SetupSnapshots::ICONS;
    $perSession = collect($sections)->filter->perSession()->keys()->all();
    $defaultLabel = 'Setup' . ($session ? ' of ' . $session->name : '') . ' on ' . now()->format('d M Y');
@endphp
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="page-title-box d-flex flex-wrap gap-2 align-items-center justify-content-between">
                <h4 class="mb-0">Setup snapshots</h4>
                @can('Copy session setup')<a href="{{ route('sessions.clone', array_filter(['source' => $session?->id])) }}" class="btn btn-soft-primary"><i class="ri-file-copy-2-line me-1"></i>Copy session setup</a>@endcan
            </div>
            @include('academics.partials.alerts')

            <div class="uv-period-bar mb-3">
                <div class="text-white-75 fs-12 text-uppercase fw-semibold mb-1">Snapshots &amp; recovery</div>
                <h5><i class="ri-camera-line me-1"></i>Save the setup now, put it back later</h5>
                <div class="text-white-75 fs-13 mt-1">A snapshot keeps the setup exactly as it is. Restoring shows what will change first, and saves what is there before changing it, so a restore can be undone too. One is also saved automatically before every copy and every restore. Student data, scores, results and payments are never part of a snapshot.</div>
            </div>

            <div class="row">
                <div class="col-xl-4">
                    <form method="POST" action="{{ route('setup.snapshots.store') }}" class="card" id="snap-form">
                        @csrf
                        <div class="card-header"><h5 class="card-title mb-0"><i class="ri-camera-line me-1 text-primary"></i>Take a snapshot</h5></div>
                        <div class="card-body">
                            <div class="mb-3"><label class="form-label">Name</label>
                                <input type="text" name="label" value="{{ old('label', $defaultLabel) }}" class="form-control @error('label') is-invalid @enderror" maxlength="150" required>@error('label')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                            <div class="mb-3"><label class="form-label">Session</label>
                                <select name="session" class="form-select" id="snap-session">
                                    <option value="">None: university-wide setup only</option>
                                    @foreach ($sessions as $s)<option value="{{ $s->id }}" @selected((int) old('session', $session?->id) === $s->id)>{{ $s->name }} ({{ ucfirst($s->status) }})</option>@endforeach
                                </select>
                                <div class="form-text">Windows, bills and course lecturers belong to a session, so they need one.</div></div>
                            <label class="form-label">What to save</label>
                            @error('sections')<div class="text-danger fs-13 mb-1">{{ $message }}</div>@enderror
                            <div class="d-grid gap-2 mb-3">
                                @foreach ($sections as $k => $sec)
                                    <label class="uv-pick" data-per-session="{{ $sec->perSession() ? 1 : 0 }}"><input class="form-check-input" type="checkbox" name="sections[]" value="{{ $k }}" @checked(in_array($k, old('sections', $chosen ?: array_keys($sections)), true))>
                                        <i class="{{ $icons[$k] ?? 'ri-settings-3-line' }} uv-pick-icon"></i><span><strong>{{ $sec->label() }}</strong><small>{{ $sec->about() }}</small></span></label>
                                @endforeach
                            </div>
                            <div><label class="form-label">Note (optional)</label><textarea name="note" rows="2" class="form-control" maxlength="500" placeholder="e.g. Before the Senate changes the grading">{{ old('note') }}</textarea></div>
                        </div>
                        <div class="card-footer text-end"><button class="btn btn-primary" type="submit"><i class="ri-save-3-line me-1"></i>Save snapshot</button></div>
                    </form>
                </div>

                <div class="col-xl-8">
                    <div class="card">
                        <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
                            <h5 class="card-title mb-0">Saved snapshots</h5>
                            <form method="GET" class="d-flex flex-wrap gap-2">
                                <select name="session" class="form-select form-select-sm" onchange="this.form.submit()" style="min-width:170px"><option value="">Every session</option>@foreach ($sessions as $s)<option value="{{ $s->id }}" @selected($session?->id === $s->id)>{{ $s->name }}</option>@endforeach</select>
                                <select name="kind" class="form-select form-select-sm" onchange="this.form.submit()" data-no-search style="min-width:150px"><option value="">Manual and automatic</option><option value="manual" @selected($kind === 'manual')>Manual only</option><option value="auto" @selected($kind === 'auto')>Automatic only</option></select>
                            </form>
                        </div>
                        <div class="table-responsive"><table class="table align-middle mb-0">
                            <thead class="table-light"><tr><th style="min-width:230px">Snapshot</th><th>What it holds</th><th>Saved</th><th></th></tr></thead>
                            <tbody>
                            @forelse ($list as $snap)
                                <tr>
                                    <td><a href="{{ route('setup.snapshots.show', $snap) }}" class="fw-semibold">{{ $snap->label }}</a>
                                        <div class="mt-1"><span class="badge {{ $snap->kind === 'auto' ? 'bg-info-subtle text-info' : 'bg-primary-subtle text-primary' }}">{{ $snap->kind === 'auto' ? 'Automatic' : 'Manual' }}</span>
                                            <span class="text-muted fs-12 ms-1">{{ $snap->session?->name ?? 'University-wide' }}</span></div>
                                        @if ($snap->note)<div class="text-muted fs-12 mt-1">{{ $snap->note }}</div>@endif</td>
                                    <td>@foreach ($snap->sections ?? [] as $k)<span class="uv-count me-1 mb-1"><i class="{{ $icons[$k] ?? 'ri-settings-3-line' }}"></i>{{ isset($sections[$k]) ? $sections[$k]->label() : $k }} · {{ $snap->summary[$k] ?? 0 }}</span>@endforeach</td>
                                    <td class="text-nowrap">{{ $snap->created_at->format('d M Y, g:i A') }}<div class="text-muted small">{{ $snap->creator?->name ?? 'System' }}</div></td>
                                    <td class="text-end text-nowrap">
                                        <a href="{{ route('setup.snapshots.show', $snap) }}" class="btn btn-sm btn-primary"><i class="ri-history-line me-1"></i>Restore…</a>
                                        <a href="{{ route('setup.snapshots.download', $snap) }}" class="btn btn-sm btn-soft-secondary" title="Download as a file"><i class="ri-download-2-line"></i></a>
                                        <form method="POST" action="{{ route('setup.snapshots.destroy', $snap) }}" class="d-inline" data-confirm="Delete the snapshot “{{ $snap->label }}”? It cannot be restored afterwards.">@csrf @method('DELETE')<button class="btn btn-sm btn-soft-danger" type="submit" title="Delete"><i class="ri-delete-bin-line"></i></button></form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-4"><i class="ri-camera-off-line fs-2 d-block mb-1"></i>No snapshots yet. Take one before a big change.</td></tr>
                            @endforelse
                            </tbody></table></div>
                        @if ($list->hasPages())<div class="card-footer">{{ $list->links() }}</div>@endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
(() => {
    const sel = document.getElementById('snap-session');
    const sync = () => document.querySelectorAll('#snap-form [data-per-session="1"] input').forEach(c => {
        if (c.disabled && sel.value) c.checked = true;
        c.disabled = ! sel.value;
        if (! sel.value) c.checked = false;
    });
    sel.addEventListener('change', sync);
    sync();
})();
</script>
@endsection
