@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="page-title-box"><h4 class="mb-0">Result queries</h4></div>
            @include('academics.partials.alerts')

            <div class="card">
                <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
                    <ul class="nav nav-pills">
                        @foreach (['open' => 'Awaiting review', 'upheld' => 'Upheld', 'rejected' => 'Not upheld', 'all' => 'All'] as $k => $l)
                            <li class="nav-item"><a class="nav-link {{ $status === $k ? 'active' : '' }}" href="{{ route('results.complaints', ['status' => $k]) }}">{{ $l }}@if ($k !== 'all') <span class="badge bg-light text-body ms-1">{{ $counts[$k] ?? 0 }}</span>@endif</a></li>
                        @endforeach
                    </ul>
                    <form method="GET" class="d-flex gap-2"><input type="hidden" name="status" value="{{ $status }}"><input name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Matric number or surname"><button class="btn btn-sm btn-light">Search</button></form>
                </div>
                <ul class="list-group list-group-flush">
                    @forelse ($list as $c)
                        @php $r = $c->result; @endphp
                        <li class="list-group-item py-3">
                            <div class="d-flex flex-wrap justify-content-between gap-2">
                                <div>
                                    <span class="fw-semibold">{{ $c->student->full_name }}</span> <span class="text-muted">{{ $c->student->matric_label }}</span>
                                    <div class="fs-13">{{ $r->course->code }} {{ $r->course->title }} · {{ $r->session->name }} {{ $r->semesterType->name }} · CA {{ $r->ca }} + Exam {{ $r->exam }} = <strong>{{ $r->total }} ({{ $r->grade }})</strong>
                                        @if ($r->sheet)<a href="{{ route('results.sheets.show', $r->result_sheet_id) }}" class="ms-1">Score sheet</a>@endif</div>
                                </div>
                                <div class="text-end"><span class="badge {{ \App\Models\ResultComplaint::BADGES[$c->status] }}">{{ \App\Models\ResultComplaint::STATUSES[$c->status] }}</span><div class="text-muted fs-12">{{ $c->created_at->format('j M Y') }}</div></div>
                            </div>
                            <div class="mt-2 p-2 bg-light rounded fs-13">{{ $c->reason }}</div>
                            @if ($c->status !== 'open')
                                <div class="mt-2 fs-13"><strong>Answer</strong> by {{ $c->handler?->name ?? '—' }}, {{ $c->handled_at?->format('j M Y') }}: {{ $c->response }}</div>
                            @else
                                @can('Respond to result complaints')
                                    <form method="POST" action="{{ route('results.complaints.respond', $c->id) }}" class="row g-2 mt-1 align-items-end">
                                        @csrf
                                        <div class="col-md-2"><label class="form-label fs-12 mb-0">Decision</label><select name="decision" class="form-select form-select-sm"><option value="rejected">Not upheld</option><option value="upheld">Upheld</option></select></div>
                                        <div class="col-md-5"><label class="form-label fs-12 mb-0">Answer for the student</label><input name="response" class="form-control form-control-sm" maxlength="2000" required></div>
                                        @can('Amend published results')
                                            <div class="col-md-1"><label class="form-label fs-12 mb-0">New CA</label><input type="number" step="0.01" name="ca" class="form-control form-control-sm" placeholder="{{ $r->ca }}"></div>
                                            <div class="col-md-1"><label class="form-label fs-12 mb-0">New exam</label><input type="number" step="0.01" name="exam" class="form-control form-control-sm" placeholder="{{ $r->exam }}"></div>
                                        @endcan
                                        <div class="col-md-2"><button class="btn btn-sm btn-primary" type="submit">Send answer</button></div>
                                        @can('Amend published results')<div class="col-12 form-text mt-0">If upheld, fill in both new scores to correct the result now. The correction is logged on the score sheet.</div>@endcan
                                    </form>
                                @endcan
                            @endif
                        </li>
                    @empty
                        <li class="list-group-item text-center text-muted py-5">No result queries here.</li>
                    @endforelse
                </ul>
                @if ($list->hasPages())<div class="card-footer">{{ $list->links() }}</div>@endif
            </div>
        </div>
    </div>
</div>
@endsection
