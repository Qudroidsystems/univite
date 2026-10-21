@extends('layouts.master')

@section('content')
@php
    $rows = [
        ['Class meetings placed', fn ($s) => $s['placed'] . ' / ' . $s['required'], fn ($s) => -$s['placed']],
        ['Not placed', fn ($s) => $s['unplaced'], fn ($s) => $s['unplaced']],
        ['Hard rules broken', fn ($s) => $s['hard_violations'], fn ($s) => $s['hard_violations']],
        ['Preferences missed', fn ($s) => $s['soft_missed'], fn ($s) => $s['soft_missed']],
        ['Preference score (lower is better)', fn ($s) => round($s['score'], 1), fn ($s) => $s['score']],
        ['Idle gaps for classes', fn ($s) => $s['classes']['gaps'], fn ($s) => $s['classes']['gaps']],
        ['Busiest day for a class (periods)', fn ($s) => $s['classes']['max_per_day'], fn ($s) => $s['classes']['max_per_day']],
        ['Heaviest lecturer load (periods/week)', fn ($s) => $s['lecturers']['max'], fn ($s) => $s['lecturers']['max']],
        ['Venue occupancy', fn ($s) => $s['venues']['occupancy'] . '%', null],
        ['Lab occupancy', fn ($s) => $s['venues']['lab_occupancy'] . '%', null],
        ['Time taken', fn ($s) => number_format($s['seconds'] ?? 0, 1) . ' s', null],
    ];
@endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Compare candidates', 'icon' => 'ri-git-compare-line', 'subtitle' => $t->title, 'back' => [route('timetables.runs', $t), 'All candidates']])
    @include('academics.partials.alerts')
    <div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
        <thead class="table-light"><tr><th></th>@foreach ($runs as $r)<th class="text-center"><a href="{{ route('timetables.runs.show', [$t, $r]) }}">{{ $r->label }}</a><div class="fw-normal text-muted fs-12">{{ $r->algorithmLabel() }} · {{ $r->seed }}</div></th>@endforeach</tr></thead>
        <tbody>
        @foreach ($rows as [$label, $show, $rank])
            @php $best = $rank ? $runs->filter(fn ($r) => $r->stats)->map(fn ($r) => $rank($r->stats))->min() : null; @endphp
            <tr><th class="fs-13 fw-normal">{{ $label }}</th>
                @foreach ($runs as $r)<td class="text-center {{ $rank && $r->stats && $rank($r->stats) == $best ? 'text-success fw-semibold' : '' }}">{{ $r->stats ? $show($r->stats) : '—' }}</td>@endforeach</tr>
        @endforeach
        <tr><th></th>@foreach ($runs as $r)<td class="text-center">@if ($r->status === 'done' && $t->isEditable())<form method="POST" action="{{ route('timetables.runs.apply', [$t, $r]) }}" onsubmit="return confirm('Use {{ $r->label }} as the draft?')">@csrf<button class="btn btn-sm btn-success">Use this</button></form>@elseif ($r->status === 'applied')<span class="badge bg-success-subtle text-success">In use</span>@endif</td>@endforeach</tr>
        </tbody></table></div></div>
    @include('timetables.partials.diff', ['diff' => $diff, 'aLabel' => $runs[0]->label, 'bLabel' => $runs[1]->label])
</div></div></div>
@endsection
