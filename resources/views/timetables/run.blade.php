@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => $run->label, 'icon' => 'ri-stack-line', 'subtitle' => $run->code . ' · ' . $t->title, 'back' => [route('timetables.runs', $t), 'All candidates']])
    @include('academics.partials.alerts')
    <div class="card"><div class="card-body d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <div><span class="badge {{ \App\Models\TimetableRun::BADGES[$run->status] }}">{{ \App\Models\TimetableRun::STATUSES[$run->status] }}</span>
            <span class="text-muted fs-13 ms-2">{{ $run->algorithmLabel() }} · variation {{ $run->seed }} · by {{ $run->creator?->name }} {{ $run->created_at->format('j M Y H:i') }}</span>
            @if ($run->scope)<div class="fs-13 mt-1">Partial regeneration: only {{ collect($run->scope)->map(fn ($v, $k) => str_replace('_id', '', $k))->implode(', ') }} re-placed.</div>@endif</div>
        @if ($canPrepare && $run->status === 'done')<form method="POST" action="{{ route('timetables.runs.apply', [$t, $run]) }}" onsubmit="return confirm('Replace the draft\'s placement with this candidate?')">@csrf<button class="btn btn-success"><i class="ri-check-double-line me-1"></i>Use this candidate</button></form>@endif
    </div></div>

    @if ($run->error)<div class="alert alert-danger">{{ $run->error }}</div>@endif
    @if ($run->stats)@include('timetables.partials.analytics', ['a' => $run->stats])@endif

    @if ($run->unplaced)
        <div class="card border-warning"><div class="card-header bg-warning-subtle"><h6 class="card-title mb-0">Not placed in this candidate ({{ count($run->unplaced) }})</h6></div>
            <ul class="list-group list-group-flush" style="max-height:420px;overflow:auto">@foreach ($run->unplaced as $u)<li class="list-group-item fs-13"><strong>{{ $u['code'] }}</strong> meeting {{ $u['meeting'] }}
                <ul class="mb-0">@foreach ($u['reasons'] ?? [] as $why)<li>{{ $why }}</li>@endforeach</ul>@if (! empty($u['suggestions']))<div class="text-success">{{ implode(' ', $u['suggestions']) }}</div>@endif</li>@endforeach</ul></div>
    @endif

    @if ($diff)@include('timetables.partials.diff', ['diff' => $diff, 'aLabel' => 'Current draft', 'bLabel' => $run->label])@endif
</div></div></div>
@endsection
