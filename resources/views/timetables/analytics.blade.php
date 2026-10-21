@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Timetable analytics', 'icon' => 'ri-bar-chart-2-line', 'subtitle' => $t->title, 'back' => [route('timetables.show', $t), 'Back to the timetable']])
    @include('academics.partials.alerts')
    <div class="d-flex justify-content-end gap-2 mb-2">
        @if ($canPrepare)<form method="POST" action="{{ route('timetables.analytics.refresh', $t) }}">@csrf<button class="btn btn-sm btn-soft-secondary"><i class="ri-refresh-line me-1"></i>Recalculate</button></form>@endif
        <a href="{{ route('timetables.runs', $t) }}" class="btn btn-sm btn-soft-primary"><i class="ri-stack-line me-1"></i>Candidates</a>
    </div>
    @if ($a)
        @include('timetables.partials.analytics', ['a' => $a])
    @else
        <div class="card"><div class="card-body text-muted">No statistics yet. Generate the timetable (or use a candidate), then come back. @if ($canPrepare)Or press Recalculate to measure it as it stands.@endif</div></div>
    @endif
</div></div></div>
@endsection
