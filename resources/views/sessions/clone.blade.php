@extends('layouts.master')

@section('content')
@php
    $icons = \App\Services\Setup\SetupSnapshots::ICONS;
    $semesterMode = $mode === 'semester';
    $shown = $semesterMode ? array_intersect_key($categories, array_flip($semesterCategories)) : $categories;
    $keep = array_filter(['source' => $source?->id, 'dest' => $dest?->id, 'source_type' => $pair?->from->id, 'dest_type' => $pair?->to->id, 'categories' => $chosen ?: null]);
    $into = match ($mode) {
        'new' => $newName ?: 'the new session',
        'semester' => trim(($dest?->name ?? '') . ' ' . ($pair?->to->name ?? '')),
        default => $dest?->name,
    };
@endphp
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="page-title-box d-flex flex-wrap gap-2 align-items-center justify-content-between">
                <h4 class="mb-0">Copy session setup</h4>
                @can('Manage setup snapshots')<a href="{{ route('setup.snapshots.index', array_filter(['session' => $dest?->id ?? $source?->id])) }}" class="btn btn-soft-secondary"><i class="ri-camera-line me-1"></i>Snapshots &amp; restore</a>@endcan
            </div>
            @include('academics.partials.alerts')

            <div class="uv-period-bar mb-3">
                <div class="text-white-75 fs-12 text-uppercase fw-semibold mb-1">Copy setup</div>
                <h5><i class="ri-file-copy-2-line me-1"></i>Start a session or semester from one you have already set up</h5>
                <div class="text-white-75 fs-13 mt-1">Only setup is copied. Registrations, scores, results, payments, invoices and student data are never copied, and the source is never changed. What is in the destination is saved as a snapshot first, and every copy can be undone.</div>
            </div>

            <div class="uv-modes mb-3">
                @foreach ($modes as $k => [$l, $h, $i])
                    <a href="{{ route('sessions.clone', ['mode' => $k] + array_intersect_key($keep, array_flip(['source']))) }}" class="uv-mode @if ($mode === $k) is-on @endif">
                        <i class="{{ $i }}"></i><span><strong>{{ $l }}</strong><small>{{ $h }}</small></span></a>
                @endforeach
            </div>

            <form method="GET" action="{{ route('sessions.clone') }}" class="card" id="clone-form">
                <input type="hidden" name="preview" value="1"><input type="hidden" name="mode" value="{{ $mode }}">
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-{{ $semesterMode ? 3 : 6 }}"><label class="form-label">Copy from session</label>
                            <select name="source" class="form-select" required><option value="">Choose a session</option>@foreach ($sessions as $s)<option value="{{ $s->id }}" @selected($source?->id === $s->id)>{{ $s->name }} ({{ ucfirst($s->status) }})</option>@endforeach</select></div>
                        @if ($semesterMode)
                            <div class="col-md-3"><label class="form-label">From semester</label>
                                <select name="source_type" class="form-select" required><option value="">Choose a semester</option>@foreach ($types as $t)<option value="{{ $t->id }}" @selected($pair?->from->id === $t->id || (! $pair && (int) request('source_type') === $t->id))>{{ $t->name }}</option>@endforeach</select></div>
                        @endif
                        @if ($mode === 'new')
                            <div class="col-md-6"><label class="form-label">Name of the new session</label>
                                <input type="text" name="new_name" value="{{ $newName }}" class="form-control" placeholder="2026/2027" pattern="\d{4}/\d{4}" required>
                                <div class="form-text">It is made as a draft with the same semesters, its dates moved forward by the year difference.</div></div>
                        @else
                            <div class="col-md-{{ $semesterMode ? 3 : 6 }}"><label class="form-label">Copy into session</label>
                                <select name="dest" class="form-select" required><option value="">Choose a session</option>@foreach ($sessions as $s)<option value="{{ $s->id }}" @selected($dest?->id === $s->id) @disabled(in_array($s->status, ['closed', 'archived'], true))>{{ $s->name }} ({{ ucfirst($s->status) }})</option>@endforeach</select></div>
                        @endif
                        @if ($semesterMode)
                            <div class="col-md-3"><label class="form-label">Into semester</label>
                                <select name="dest_type" class="form-select" required><option value="">Choose a semester</option>@foreach ($types as $t)<option value="{{ $t->id }}" @selected($pair?->to->id === $t->id)>{{ $t->name }}</option>@endforeach</select></div>
                        @endif
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="form-label mb-0">What to copy</label>
                        <span class="fs-12"><a href="#" data-pick="all">Tick all</a> · <a href="#" data-pick="none">Clear</a></span>
                    </div>
                    <div class="uv-picks">
                        @foreach ($shown as $k => [$l, $h])
                            <label class="uv-pick"><input class="form-check-input" type="checkbox" name="categories[]" value="{{ $k }}" @checked(in_array($k, $chosen, true) || ! $chosen)>
                                <i class="{{ $icons[$k] ?? 'ri-settings-3-line' }} uv-pick-icon"></i><span><strong>{{ $l }}</strong><small>{{ $h }}</small></span></label>
                        @endforeach
                    </div>
                    @if ($mode === 'new')<div class="form-text mt-2">Leave everything unticked to make the session and its semesters only.</div>@endif
                </div>
                <div class="card-footer d-flex justify-content-end"><button class="btn btn-primary" type="submit"><i class="ri-eye-line me-1"></i>Preview the copy</button></div>
            </form>

            @if ($preview)
                <div class="card border-primary-subtle">
                    <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
                        <h5 class="card-title mb-0"><i class="ri-eye-line me-1 text-primary"></i>Preview: {{ $source->name }}{{ $pair ? ' ' . $pair->from->name : '' }} <i class="ri-arrow-right-line mx-1"></i> {{ $into }}</h5>
                        @if (! $preview['problems'])<span class="uv-count {{ $preview['total'] ? 'is-add' : '' }}">{{ $preview['total'] }} item(s) to copy</span>@endif
                    </div>
                    @foreach ($preview['problems'] as $p)<div class="alert alert-danger m-3 mb-0 py-2"><i class="ri-error-warning-line me-1"></i>{{ $p }}</div>@endforeach
                    @if ($mode === 'new' && ! empty($preview['session']))
                        <div class="uv-plan-row"><i class="ri-calendar-2-line uv-pick-icon"></i>
                            <div><strong>New session {{ $preview['session']['name'] }}</strong> (draft){{ $preview['session']['start'] ? ', ' . $preview['session']['start'] . ' to ' . $preview['session']['end'] : '' }}
                                <div class="text-muted fs-13">Semesters: @foreach ($preview['semesters'] as $sm){{ $sm['name'] }}{{ $sm['start'] ? ' (' . $sm['start'] . ' to ' . $sm['end'] . ')' : '' }}{{ $loop->last ? '' : ', ' }}@endforeach</div></div>
                            <span class="uv-count is-add">Will be made</span></div>
                    @endif
                    @foreach ($preview['rows'] as $k => $row)
                        <div class="uv-plan-row"><i class="{{ $icons[$k] ?? 'ri-settings-3-line' }} uv-pick-icon"></i>
                            <div><strong>{{ $categories[$k][0] }}</strong>
                                @foreach ($row['notes'] as $n)<div class="uv-note"><i class="ri-information-line"></i>{{ $n }}</div>@endforeach</div>
                            <div class="d-flex flex-wrap gap-1 justify-content-end"><span class="uv-count {{ $row['create'] ? 'is-add' : '' }}">{{ $row['create'] }} to copy</span>@if ($row['skip'])<span class="uv-count">{{ $row['skip'] }} already there</span>@endif</div></div>
                    @endforeach
                    @if (! $preview['problems'])
                        @can('Copy session setup')
                            <form method="POST" action="{{ route('sessions.clone.run') }}" class="card-footer d-flex flex-wrap gap-2 align-items-center justify-content-between"
                                  data-confirm="{{ $mode === 'new' ? 'Make ' . $newName . ($preview['total'] ? ' and copy ' . $preview['total'] . ' item(s) into it' : '') . '?' : 'Copy ' . $preview['total'] . ' item(s) into ' . $into . '?' }}">
                                @csrf
                                <input type="hidden" name="mode" value="{{ $mode }}"><input type="hidden" name="source" value="{{ $source->id }}">
                                @if ($mode === 'new')<input type="hidden" name="new_name" value="{{ $newName }}">@else<input type="hidden" name="dest" value="{{ $dest->id }}">@endif
                                @if ($pair)<input type="hidden" name="source_type" value="{{ $pair->from->id }}"><input type="hidden" name="dest_type" value="{{ $pair->to->id }}">@endif
                                @foreach ($preview['rows'] as $k => $row)<input type="hidden" name="categories[]" value="{{ $k }}">@endforeach
                                <span class="text-muted fs-13"><i class="ri-shield-check-line me-1 text-success"></i>{{ $mode === 'new' ? 'The new session starts as a draft; nothing else changes.' : 'What ' . $into . ' has now is saved as a snapshot before anything changes.' }}</span>
                                @php $disabled = $mode !== 'new' && $preview['total'] === 0; @endphp
                                <button class="btn btn-primary" type="submit" @disabled($disabled)><i class="ri-file-copy-line me-1"></i>{{ $mode === 'new' ? 'Make ' . $newName : ($preview['total'] ? 'Copy ' . $preview['total'] . ' item(s)' : 'Nothing new to copy') }}</button>
                            </form>
                        @endcan
                    @endif
                </div>
            @endif

            <div class="card"><div class="card-header"><h5 class="card-title mb-0">Recent copies</h5></div>
                <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>Operation</th><th>From → into</th><th>When / by</th><th>Items</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    @forelse ($history as $b)
                        @php $saved = $b->meta['saved_first_as'] ?? null; @endphp
                        <tr><td><a href="{{ route('operations.show', $b) }}" class="fw-semibold">{{ $b->operation_id }}</a></td>
                            <td>{{ $b->meta['source_session'] ?? '' }} → {{ $b->session?->name }}@if (! empty($b->meta['semesters']))<div class="text-muted small">{{ $b->meta['semesters'] }}</div>@endif @if (! empty($b->meta['new_session']))<span class="badge bg-info-subtle text-info ms-1">new session</span>@endif</td>
                            <td class="text-nowrap">{{ $b->created_at->format('d M Y, g:i A') }}<div class="text-muted small">{{ $b->user?->name }}</div></td>
                            <td>{{ $b->success_count }} copied, {{ $b->skipped_count }} skipped</td><td><span class="badge bg-{{ $b->status === 'reversed' ? 'secondary' : 'success' }}">{{ ucfirst($b->status) }}</span></td>
                            <td class="text-end text-nowrap">
                                @if ($saved && isset($savedFirst[$saved]))@can('Manage setup snapshots')<a href="{{ route('setup.snapshots.show', $saved) }}" class="btn btn-sm btn-soft-secondary" title="What was there before this copy"><i class="ri-camera-line"></i> Before</a>@endcan @endif
                                @if ($b->status !== 'reversed')@can('Undo session copies')<form method="POST" action="{{ route('sessions.clone.undo', $b) }}" class="d-inline" data-confirm="Undo this copy? Anything already in use is kept."> @csrf <button class="btn btn-sm btn-outline-danger" type="submit">Undo</button></form>@endcan @endif</td></tr>
                    @empty
                        <tr><td colspan="6" class="text-muted text-center py-3">No copies yet.</td></tr>
                    @endforelse
                    </tbody></table></div>
            </div>
        </div>
    </div>
</div>
<script>
document.querySelectorAll('[data-pick]').forEach(a => a.addEventListener('click', e => {
    e.preventDefault();
    document.querySelectorAll('#clone-form input[name="categories[]"]').forEach(c => { c.checked = a.dataset.pick === 'all'; });
}));
</script>
@endsection
