@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Legacy results', 'icon' => 'ri-history-line', 'subtitle' => 'Bring in results from before the portal so transcripts, CGPA, standing and graduation include them.'])
    @include('academics.partials.alerts')
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    <div class="row">
        <div class="col-lg-5">
            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Upload a file</h6></div><div class="card-body">
                <form method="POST" action="{{ route('legacy.store') }}" enctype="multipart/form-data" data-no-ajax>@csrf
                    <div class="mb-3">
                        <div class="form-check mb-2"><input class="form-check-input" type="radio" name="kind" value="results" id="k1" checked><label class="form-check-label" for="k1"><strong>Course results</strong><div class="text-muted fs-12">One row per student per course: matric number, session, semester, course code and the score or grade.</div></label></div>
                        <div class="form-check"><input class="form-check-input" type="radio" name="kind" value="balances" id="k2"><label class="form-check-label" for="k2"><strong>Previous totals (TCP / TNU)</strong><div class="text-muted fs-12">One row per student with their cumulative totals from the old system, when course-by-course results are not available.</div></label></div>
                    </div>
                    <input type="file" name="file" class="form-control mb-2" accept=".csv,.txt,.xlsx,.xls" required>
                    <div class="form-text mb-3">CSV or Excel, first row = column headings. The columns can be in any order and have any names; you match them on the next page.</div>
                    <button class="btn btn-primary">Upload and match columns</button>
                </form>
            </div></div>
            <div class="card"><div class="card-body fs-13">
                <h6>Before you start</h6>
                <ul class="ps-3 mb-2">
                    <li>The students must already be in the portal with their matric numbers.</li>
                    <li>Use one or the other for a student, not both for the same years: previous totals are added to whatever course results the portal has.</li>
                    <li>Nothing already in the portal is overwritten. Rows with problems are left out and listed so you can fix them and upload them again.</li>
                    <li>Every import can be undone from the operation history.</li>
                </ul>
                Templates: <a href="{{ route('legacy.template', 'results') }}">course results</a> · <a href="{{ route('legacy.template', 'balances') }}">previous totals</a>
            </div></div>
        </div>
        <div class="col-lg-7">
            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Uploads</h6></div>
                <div class="table-responsive"><table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th>File</th><th>Kind</th><th class="text-end">Rows</th><th>Status</th><th>By</th><th style="width:1%" class="text-nowrap">Last changed by</th></tr></thead>
                    <tbody>
                    @forelse ($imports as $i)
                        <tr><td><a href="{{ route('legacy.show', $i) }}" class="fw-semibold">{{ $i->file_name }}</a><div class="text-muted fs-12">{{ $i->created_at->format('j M Y H:i') }}</div></td>
                            <td>{{ \App\Models\LegacyImport::KINDS[$i->kind] }}</td><td class="text-end">{{ number_format($i->rows) }}@if ($i->imported)<div class="text-muted fs-12">{{ number_format($i->imported) }} imported</div>@endif</td>
                            <td><span class="badge {{ \App\Models\LegacyImport::BADGES[$i->status] }}">{{ \App\Models\LegacyImport::STATUSES[$i->status] }}</span></td><td class="fs-13">{{ $i->creator?->name }}</td>
                            <td><x-last-change :record="$i" :among="$imports" /></td></tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">Nothing uploaded yet.</td></tr>
                    @endforelse
                    </tbody></table></div>
                <div class="card-body">{{ $imports->links() }}</div></div>
        </div>
    </div>
</div></div></div>
@endsection
