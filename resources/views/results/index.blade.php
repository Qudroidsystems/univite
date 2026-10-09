@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="page-title-box"><h4 class="mb-0">Results</h4></div>
            @include('academics.partials.alerts')

            @if ($mine->isNotEmpty() || $mySheets->isNotEmpty())
                <div class="card">
                    <div class="card-header"><h6 class="card-title mb-0">My courses{{ $ctx ? ' · ' . $ctx['session']->name . ' ' . $ctx['type']->name : '' }}</h6></div>
                    <ul class="list-group list-group-flush">
                        @foreach ($mine as $a)
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span><span class="fw-semibold">{{ $a->course->code }}</span> {{ $a->course->title }}</span>
                                <a href="{{ route('results.open', $a->course_id) }}" class="btn btn-sm btn-primary">Enter scores</a>
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
                            <thead class="table-light"><tr><th>Course</th><th>Session</th><th>Students</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                            @forelse ($all as $s)
                                <tr>
                                    <td><span class="fw-semibold">{{ $s->course->code }}</span> {{ $s->course->title }}</td>
                                    <td>{{ $s->session->name }} · {{ $s->semesterType->name }}</td>
                                    <td>{{ $s->results_count }}</td>
                                    <td><span class="badge {{ \App\Models\ResultSheet::BADGES[$s->status] }}">{{ \App\Models\ResultSheet::STATUSES[$s->status] }}</span>@if ($s->stage)<div class="text-muted fs-12">At: {{ $s->stage->name }}</div>@endif</td>
                                    <td class="text-end"><a class="btn btn-sm btn-soft-primary" href="{{ route('results.sheets.show', $s->id) }}">Open</a></td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted py-5">No score sheets here.</td></tr>
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
