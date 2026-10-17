@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="page-title-box"><h4 class="mb-0">Results</h4></div>
            @include('academics.partials.alerts')

            @if ($waiting->isNotEmpty())
                <div class="card">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <h6 class="card-title mb-0">Waiting for your approval <span class="badge bg-warning-subtle text-warning ms-1">{{ $waiting->count() }}</span></h6>
                        <a href="{{ route('results.broadsheet') }}" class="btn btn-sm btn-soft-primary"><i class="ri-table-line me-1"></i>Approve a whole level from the master sheet</a>
                    </div>
                    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Course</th><th>Department</th><th>Session</th><th class="text-end">Students</th><th>Step</th><th style="width:1%" class="text-nowrap">Last changed by</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($waiting as $s)
                            <tr><td>{{ $s->course->code }} · {{ $s->course->title }}</td><td>{{ $s->course->department?->name }}</td><td>{{ $s->session->name }} {{ $s->semesterType->name }}</td>
                                <td class="text-end">{{ $s->results_count }}</td><td>{{ $s->stage?->name }}</td>
                                <td><x-last-change :record="$s" :among="$waiting" /></td>
                                <td class="text-end"><a href="{{ route('results.sheets.show', $s->id) }}" class="btn btn-sm btn-soft-success">Review</a></td></tr>
                        @endforeach
                        </tbody>
                    </table></div>
                </div>
            @endif

            @if ($mine->isNotEmpty() || $mySheets->isNotEmpty())
                <div class="card">
                    <div class="card-header"><h6 class="card-title mb-0">My courses{{ $ctx ? ' · ' . $ctx->session->name . ' ' . $ctx->type->name : '' }}</h6></div>
                    <ul class="list-group list-group-flush">
                        @foreach ($mine as $a)
                            @php $sc = $schemes[$a->course_id]['scheme'] ?? null; $sh = $schemes[$a->course_id]['sheet'] ?? null; @endphp
                            <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                                <span>
                                    <span class="fw-semibold">{{ $a->course->code }}</span> {{ $a->course->title }}
                                    @if ($sh)<span class="badge {{ \App\Models\ResultSheet::BADGES[$sh->status] }} ms-1">{{ \App\Models\ResultSheet::STATUSES[$sh->status] }}</span>@endif
                                    <span class="d-block mt-1">
                                        @if ($sc)
                                            <span class="text-muted fs-12 me-1"><i class="ri-survey-line"></i> {{ $sc->name }}:</span>
                                            @foreach ($sc->items as $it)<span class="badge {{ $it->component === 'exam' ? 'bg-primary-subtle text-primary' : 'bg-info-subtle text-info' }} me-1">{{ $it->name }} /{{ $it->maxLabel() }}</span>@endforeach
                                        @else
                                            <span class="text-muted fs-12 me-1">No assessment scheme:</span><span class="badge bg-info-subtle text-info me-1">CA /{{ rtrim(rtrim(number_format($caMax, 2), '0'), '.') }}</span><span class="badge bg-primary-subtle text-primary">Exam /{{ rtrim(rtrim(number_format($examMax, 2), '0'), '.') }}</span>
                                        @endif
                                    </span>
                                </span>
                                <a href="{{ route('results.open', $a->course_id) }}" class="btn btn-sm btn-primary text-nowrap">Enter scores</a>
                            </li>
                        @endforeach
                    </ul>
                    @if ($mySheets->isNotEmpty())
                        <div class="card-body border-top pt-2">
                            <div class="text-muted fs-12 mb-1">Recent sheets</div>
                            @foreach ($mySheets as $s)
                                <a href="{{ route('results.sheets.show', $s->id) }}" class="d-block py-1">{{ $s->course->code }} · {{ $s->session->name }} {{ $s->semesterType->name }} <span class="badge {{ \App\Models\ResultSheet::BADGES[$s->status] }} ms-1">{{ \App\Models\ResultSheet::STATUSES[$s->status] }}</span></a>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            @if ($all)
                <div class="card">
                    <div class="card-header">
                        <ul class="nav nav-pills">
                            @foreach (['submitted' => 'Awaiting approval', 'approved' => 'Ready to publish', 'published' => 'Published', 'draft' => 'Drafts', 'all' => 'All'] as $k => $l)
                                <li class="nav-item"><a class="nav-link {{ $status === $k ? 'active' : '' }}" href="{{ route('results.sheets.index', ['status' => $k]) }}">{{ $l }}@if ($k !== 'all') <span class="badge bg-light text-body ms-1">{{ $counts[$k] ?? 0 }}</span>@endif</a></li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light"><tr><th>Course</th><th>Session</th><th>Students</th><th>Status</th><th style="width:1%" class="text-nowrap">Last changed by</th><th></th></tr></thead>
                            <tbody>
                            @forelse ($all as $s)
                                <tr>
                                    <td><span class="fw-semibold">{{ $s->course->code }}</span> {{ $s->course->title }}</td>
                                    <td>{{ $s->session->name }} · {{ $s->semesterType->name }}</td>
                                    <td>{{ $s->results_count }}</td>
                                    <td><span class="badge {{ \App\Models\ResultSheet::BADGES[$s->status] }}">{{ \App\Models\ResultSheet::STATUSES[$s->status] }}</span>@if ($s->stage)<div class="text-muted fs-12">At: {{ $s->stage->name }}</div>@endif</td>
                                    <td><x-last-change :record="$s" :among="$all" /></td>
                                    <td class="text-end"><a class="btn btn-sm btn-soft-primary" href="{{ route('results.sheets.show', $s->id) }}">Open</a></td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted py-5">No score sheets here.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if ($all->hasPages())<div class="card-footer">{{ $all->links() }}</div>@endif
                </div>
            @elseif ($mine->isEmpty() && $mySheets->isEmpty())
                <div class="alert alert-info">You have no courses assigned this semester.</div>
            @endif
        </div>
    </div>
</div>
@endsection
