@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Candidates', 'icon' => 'ri-stack-line', 'subtitle' => $t->title . ' · ' . $t->session?->name . ' · ' . $t->semesterType?->name, 'back' => [route('timetables.show', $t), 'Back to the timetable']])
    @include('academics.partials.alerts')

    @if ($pending)
        <div class="alert alert-info d-flex flex-wrap gap-2 align-items-center justify-content-between" id="pendingNote">
            <span><span class="spinner-border spinner-border-sm me-2"></span>{{ $pending }} candidate(s) are being generated in the background. This page updates itself.</span>
            @if ($canPrepare)<form method="POST" action="{{ route('timetables.runs.now', $t) }}" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').innerText='Generating…'">@csrf<button class="btn btn-sm btn-primary">Generate them here now</button></form>@endif
        </div>
    @endif

    <form method="GET" action="{{ route('timetables.runs.compare', $t) }}" class="card" id="compareForm">
        <div class="card-header d-flex flex-wrap justify-content-between gap-2 align-items-center"><div><h6 class="card-title mb-0">Generated candidates</h6><div class="text-muted fs-12">Each is a complete timetable. Tick two to four to compare them side by side, then use the best one.</div></div>
            <button class="btn btn-sm btn-soft-primary" id="compareBtn" disabled><i class="ri-git-compare-line me-1"></i>Compare the ticked</button></div>
        <div class="table-responsive"><table class="table align-middle mb-0">
            <thead class="table-light"><tr><th style="width:1%"></th><th>Candidate</th><th>Method</th><th>Status</th><th class="text-end">Placed</th><th class="text-end">Hard breaks</th><th class="text-end">Preferences missed</th><th class="text-end">Score</th><th class="text-end">Venue use</th><th class="text-end">Time</th><th></th></tr></thead>
            <tbody>
            @php $best = $runs->whereIn('status', ['done', 'applied'])->sortBy(fn ($r) => [$r->summary['unplaced'] ?? 9e9, $r->summary['violations'] ?? 9e9, $r->summary['score'] ?? 9e9])->first(); @endphp
            @forelse ($runs as $r)
                @php $sm = $r->summary ?? []; @endphp
                <tr class="{{ $r->status === 'applied' ? 'table-success' : '' }}">
                    <td>@if ($r->assignment !== null)<input type="checkbox" class="form-check-input" name="runs[]" value="{{ $r->id }}">@endif</td>
                    <td><a href="{{ route('timetables.runs.show', [$t, $r]) }}" class="fw-semibold">{{ $r->label }}</a> @if ($best && $best->id === $r->id && $runs->where('assignment', '!=', null)->count() > 1)<span class="badge bg-success">Best</span>@endif
                        <div class="text-muted fs-12">{{ $r->code }} · variation {{ $r->seed }}@if ($r->scope) · partial: {{ collect($r->scope)->map(fn ($v, $k) => str_replace('_id', '', $k))->implode(', ') }}@endif · {{ $r->created_at->format('j M H:i') }}</div></td>
                    <td class="fs-13">{{ $algorithms[$r->algorithm][0] ?? $r->algorithm }}</td>
                    <td><span class="badge {{ \App\Models\TimetableRun::BADGES[$r->status] }}" data-status="{{ $r->id }}">{{ \App\Models\TimetableRun::STATUSES[$r->status] }}</span>@if ($r->error)<div class="text-danger fs-12">{{ \Illuminate\Support\Str::limit($r->error, 120) }}</div>@endif</td>
                    <td class="text-end">{{ isset($sm['placed']) ? $sm['placed'] . ' / ' . $sm['units'] : '—' }}</td>
                    <td class="text-end {{ ($sm['violations'] ?? 0) ? 'text-danger' : '' }}">{{ $sm['violations'] ?? '—' }}</td>
                    <td class="text-end">{{ $sm['soft_missed'] ?? '—' }}</td>
                    <td class="text-end">{{ isset($sm['score']) ? round($sm['score'], 1) : '—' }}</td>
                    <td class="text-end">{{ isset($r->stats['venues']) ? $r->stats['venues']['occupancy'] . '%' : '—' }}</td>
                    <td class="text-end fs-13">{{ $r->duration_ms ? number_format($r->duration_ms / 1000, 1) . ' s' : '—' }}</td>
                    <td class="text-end text-nowrap">
                        @if ($r->assignment !== null)<a href="{{ route('timetables.runs.show', [$t, $r]) }}" class="btn btn-sm btn-light">Details</a>@endif
                        @if ($canPrepare && $r->status === 'done')<button form="apply{{ $r->id }}" class="btn btn-sm btn-success">Use this</button>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="11" class="text-center text-muted py-4">No candidates yet. Generate some below.</td></tr>
            @endforelse
            </tbody></table></div>
    </form>
    @foreach ($runs->where('status', 'done') as $r)<form id="apply{{ $r->id }}" method="POST" action="{{ route('timetables.runs.apply', [$t, $r]) }}" onsubmit="return confirm('Replace the draft\'s placement with {{ $r->label }}? Locked entries stay where they are.')">@csrf</form>@endforeach

    @if ($canPrepare)
        <div class="row">
            <div class="col-lg-6"><form method="POST" action="{{ route('timetables.runs.store', $t) }}" class="card">@csrf
                <div class="card-header"><h6 class="card-title mb-0">Generate more candidates</h6></div><div class="card-body row g-2">
                    <div class="col-md-6"><label class="form-label fs-13">Method</label><select name="algorithm" class="form-select form-select-sm">@foreach ($algorithms as $k => [$l])<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                    <div class="col-md-3"><label class="form-label fs-13">How many</label><input type="number" name="count" min="1" max="5" value="2" class="form-control form-control-sm"></div>
                    <div class="col-md-3"><label class="form-label fs-13">Variation</label><input type="number" name="seed" min="1" max="99999" class="form-control form-control-sm" placeholder="Random"></div>
                    <div class="col-12"><button class="btn btn-sm btn-primary"><i class="ri-magic-line me-1"></i>Generate</button></div></div></form></div>
            <div class="col-lg-6"><form method="POST" action="{{ route('timetables.runs.store', $t) }}" class="card">@csrf
                <div class="card-header"><h6 class="card-title mb-0">Regenerate part of the timetable</h6><div class="text-muted fs-12">Only what you choose is re-placed; everything else stays exactly where it is in the current draft.</div></div><div class="card-body row g-2">
                    <div class="col-md-6"><select name="department_id" class="form-select form-select-sm"><option value="">Any department</option>@foreach ($departments as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></div>
                    <div class="col-md-6"><select name="user_id" class="form-select form-select-sm"><option value="">Any lecturer</option>@foreach ($lecturers as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></div>
                    <div class="col-md-6"><select name="venue_id" class="form-select form-select-sm"><option value="">Any venue</option>@foreach ($venues as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></div>
                    <div class="col-md-6"><select name="level" class="form-select form-select-sm"><option value="">Any level</option>@foreach ($levels as $l)<option value="{{ $l }}">{{ $l }} Level</option>@endforeach</select></div>
                    <div class="col-md-6"><select name="algorithm" class="form-select form-select-sm">@foreach ($algorithms as $k => [$l])<option value="{{ $k }}" @selected($k === 'thorough')>{{ $l }}</option>@endforeach</select></div>
                    <div class="col-md-6"><button class="btn btn-sm btn-soft-primary w-100"><i class="ri-refresh-line me-1"></i>Regenerate this part</button></div></div></form></div>
        </div>
    @endif
</div></div></div>
@endsection

@push('scripts')
<script>
(() => {
    const boxes = [...document.querySelectorAll('#compareForm [name="runs[]"]')], btn = document.getElementById('compareBtn');
    const sync = () => { const n = boxes.filter(b => b.checked).length; btn.disabled = n < 2 || n > 4; };
    boxes.forEach(b => b.addEventListener('change', sync));
    @if ($pending)
    const poll = setInterval(async () => {
        try {
            const r = await fetch(@json(route('timetables.runs.status', $t)), {headers: {Accept: 'application/json'}});
            const j = await r.json();
            if (!j.runs.some(x => x.status === 'queued' || x.status === 'running')) { clearInterval(poll); location.reload(); return; }
            j.runs.forEach(x => { const b = document.querySelector(`[data-status="${x.id}"]`); if (b) b.textContent = x.label; });
        } catch (e) {}
    }, 4000);
    @endif
})();
</script>
@endpush
