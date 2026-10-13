@extends('layouts.master')

@section('content')
@php
    $edit = $canEnter && $sheet->isEditable();
    $items = $scheme?->items ?? collect();
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
    $marksOf = fn ($r) => $r->assessmentScores->mapWithKeys(fn ($m) => [$m->assessment_item_id => $fmt($m->score)]);
@endphp
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="mb-0">{{ $sheet->course->code }} · {{ $sheet->course->title }}
                    <span class="badge {{ \App\Models\ResultSheet::BADGES[$sheet->status] }} fs-12 align-middle">{{ \App\Models\ResultSheet::STATUSES[$sheet->status] }}{{ $sheet->stage ? ' · ' . $sheet->stage->name : '' }}</span></h4>
                <a href="{{ route('results.sheets.index') }}" class="btn btn-light">Back</a>
            </div>
            @include('academics.partials.alerts')
            <p class="text-muted">{{ $sheet->session->name }} · {{ $sheet->semesterType->name }} · {{ $sheet->course->credit_units }} units · CA out of {{ $fmt($caMax) }}, exam out of {{ $fmt($examMax) }}</p>
            @if ($scheme)
                <p class="text-muted fs-13 mt-n2"><i class="ri-survey-line me-1"></i>Assessment scheme <strong>{{ $scheme->name }}</strong>: {{ $scheme->summary() }}. Enter each mark; CA and exam are added up for you. Save as often as you like; every mark is needed before you submit.</p>
            @endif

            @if ($schemes->isNotEmpty())
                <form method="POST" action="{{ route('results.sheets.scheme', $sheet->id) }}" class="card" data-confirm="Change the assessments on this sheet? Marks already entered are kept and spread over the new assessments.">
                    @csrf
                    <div class="card-body d-flex flex-wrap gap-2 align-items-end">
                        <div style="min-width:260px;flex:1"><label class="form-label mb-1">Assessments on this sheet</label>
                            <select name="assessment_scheme_id" class="form-select">
                                <option value="">Plain CA and exam (Result Settings)</option>
                                @foreach ($schemes as $sc)<option value="{{ $sc->id }}" @selected($scheme?->id === $sc->id)>{{ $sc->name }} ({{ $sc->summary() }})</option>@endforeach
                            </select></div>
                        <button class="btn btn-soft-primary" type="submit">Change</button>
                        <div class="text-muted fs-12 w-100">Use this when a sheet was opened before its scheme was assigned. New sheets take their scheme from <a href="{{ route('academics.assessments.index') }}">Assessment Schemes</a> automatically.</div>
                    </div>
                </form>
            @elseif (! $scheme && $edit)
                <div class="alert alert-info fs-13"><i class="ri-information-line me-1"></i>No assessment scheme applies to this course, so marks are entered as one CA and one exam. Ask the exams office to assign a scheme under Academic Setup, Assessment Schemes.</div>
            @endif

            @if ($edit)
                <div class="card">
                    <div class="card-body d-flex flex-wrap gap-3 align-items-end">
                        <form method="POST" action="{{ route('results.sheets.upload', $sheet->id) }}" enctype="multipart/form-data" class="d-flex gap-2 align-items-end">
                            @csrf
                            <div><label class="form-label mb-1">Upload scores (CSV or Excel)</label><input type="file" name="file" class="form-control" accept=".csv,.txt,.xlsx,.xls" required></div>
                            <button class="btn btn-soft-primary" type="submit"><i class="ri-upload-2-line me-1"></i>Upload</button>
                        </form>
                        <div class="btn-group">
                            <a href="{{ route('results.sheets.template', $sheet->id) }}" class="btn btn-light"><i class="ri-file-excel-2-line me-1 text-success"></i>Download Excel to fill in</a>
                            <a href="{{ route('results.sheets.template', [$sheet->id, 'format' => 'csv']) }}" class="btn btn-light">CSV</a>
                        </div>
                        <div class="text-muted fs-12 w-100">The Excel file has a column per assessment with its maximum, the marks already entered, and refuses marks out of range. Fill it in offline and upload it here or from the UniVite app.</div>
                    </div>
                </div>
            @else
                <div class="d-flex justify-content-end mb-2"><a href="{{ route('results.sheets.template', $sheet->id) }}" class="btn btn-sm btn-light"><i class="ri-file-excel-2-line me-1 text-success"></i>Download as Excel</a></div>
            @endif

            <form method="POST" action="{{ route('results.sheets.save', $sheet->id) }}">
                @csrf
                <div class="card">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light"><tr><th>#</th><th>Matric number</th><th>Name</th>
                                @if ($scheme)
                                    @foreach ($items as $it)<th style="width:96px" class="text-nowrap" title="{{ $it->name }} · {{ \App\Models\AssessmentItem::COMPONENTS[$it->component] }}">{{ $it->code }} <span class="text-muted fw-normal">/{{ $it->maxLabel() }}</span></th>@endforeach
                                    <th>CA</th><th>Exam</th>
                                @else
                                    <th style="width:110px">CA</th><th style="width:110px">Exam</th>
                                @endif
                                <th>Total</th><th>Grade</th></tr></thead>
                            <tbody>
                            @forelse ($rows as $i => $r)
                                <tr>
                                    <td>{{ $loop->iteration }}</td>
                                    <td>{{ $r->student->matric_label }}</td>
                                    <td>{{ $r->student->full_name }}</td>
                                    @if ($scheme)
                                        @php $m = $marksOf($r); @endphp
                                        @foreach ($items as $it)
                                            <td>@if ($edit)<input type="number" step="0.01" min="0" max="{{ $it->max_score }}" name="scores[{{ $r->student_id }}][items][{{ $it->id }}]" value="{{ $m[$it->id] ?? '' }}" class="form-control form-control-sm" aria-label="{{ $it->name }}">@else{{ $m[$it->id] ?? '' }}@endif</td>
                                        @endforeach
                                        <td>{{ $r->ca }}</td><td>{{ $r->exam }}</td>
                                    @else
                                    <td>@if ($edit)<input type="number" step="0.01" min="0" max="{{ $caMax }}" name="scores[{{ $r->student_id }}][ca]" value="{{ $r->ca }}" class="form-control form-control-sm">@else{{ $r->ca }}@endif</td>
                                    <td>@if ($edit)<input type="number" step="0.01" min="0" max="{{ $examMax }}" name="scores[{{ $r->student_id }}][exam]" value="{{ $r->exam }}" class="form-control form-control-sm">@else{{ $r->exam }}@endif</td>
                                    @endif
                                    <td>{{ $r->total }}</td>
                                    <td>@if ($r->grade)<span class="badge {{ $r->is_pass ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' }}">{{ $r->grade }}</span>@endif</td>
                                </tr>
                            @empty
                                <tr><td colspan="{{ 7 + ($scheme ? $items->count() : 0) }}" class="text-center text-muted py-4">No student has an approved registration for this course yet.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if ($edit && $rows->isNotEmpty())
                        <div class="card-footer d-flex justify-content-between align-items-center">
                            <span class="text-muted">{{ $missing ? $missing . ' student(s) still need scores.' : 'All scores are in.' }}</span>
                            <button class="btn btn-light" type="submit"><i class="ri-save-line me-1"></i>Save scores</button>
                        </div>
                    @endif
                </div>
            </form>

            @if ($edit && $rows->isNotEmpty())
                <form method="POST" action="{{ route('results.sheets.submit', $sheet->id) }}" data-confirm="Submit these scores for approval? You will not be able to change them unless they are returned to you.">
                    @csrf<button class="btn btn-primary mb-3" type="submit"><i class="ri-send-plane-line me-1"></i>Submit for approval</button>
                </form>
            @endif

            @if ($canApprove || ($sheet->status === 'approved' && auth()->user()->can('Publish results')))
                <div class="card"><form method="POST" action="{{ route('results.sheets.decide', $sheet->id) }}" class="card-body d-flex flex-wrap gap-2 align-items-start">
                    @csrf
                    <input name="note" class="form-control" style="flex:1;min-width:240px" placeholder="Note (needed when returning the sheet)">
                    @if ($canApprove)<button class="btn btn-success" name="decision" value="approve"><i class="ri-check-line me-1"></i>Approve{{ $sheet->stage ? ' as ' . $sheet->stage->name : '' }}</button>@endif
                    @if ($sheet->status === 'approved')<button class="btn btn-primary" name="decision" value="publish" data-confirm="Publish these results? Students will see them immediately."><i class="ri-megaphone-line me-1"></i>Publish to students</button>@endif
                    <button class="btn btn-danger" name="decision" value="return"><i class="ri-arrow-go-back-line me-1"></i>Return to lecturer</button>
                </form></div>
            @endif

            @if ($sheet->status === 'published' && auth()->user()->can('Amend published results') && $rows->isNotEmpty())
                <div class="card"><div class="card-header"><h6 class="card-title mb-0">Correct a published score</h6></div>
                    <div class="card-body">
                        @foreach ($rows as $r)
                            <form method="POST" action="{{ route('results.amend', $r->id) }}" class="row g-2 align-items-center mb-2" data-confirm="Correct this published score? The change is logged.">
                                @csrf
                                <div class="col-md-3">{{ $r->student->matric_label }} · {{ $r->student->full_name }}</div>
                                @if ($scheme)
                                    @php $m = $marksOf($r); @endphp
                                    <div class="col-md-4 d-flex flex-wrap gap-1">
                                        @foreach ($items as $it)<input type="number" step="0.01" min="0" max="{{ $it->max_score }}" name="items[{{ $it->id }}]" value="{{ $m[$it->id] ?? '' }}" class="form-control form-control-sm" style="width:72px" title="{{ $it->name }} /{{ $it->maxLabel() }}" placeholder="{{ $it->code }}" required>@endforeach
                                    </div>
                                    <div class="col-md-3"><input name="reason" class="form-control form-control-sm" placeholder="Reason for the correction" required></div>
                                @else
                                <div class="col-md-1"><input type="number" step="0.01" name="ca" value="{{ $r->ca }}" class="form-control form-control-sm" required></div>
                                <div class="col-md-1"><input type="number" step="0.01" name="exam" value="{{ $r->exam }}" class="form-control form-control-sm" required></div>
                                <div class="col-md-5"><input name="reason" class="form-control form-control-sm" placeholder="Reason for the correction" required></div>
                                @endif
                                <div class="col-md-2"><button class="btn btn-sm btn-soft-warning" type="submit">Correct</button></div>
                            </form>
                        @endforeach
                    </div></div>
            @endif

            @if ($complaints->isNotEmpty())
                <div class="card"><div class="card-header d-flex justify-content-between"><h6 class="card-title mb-0">Student queries</h6>@can('View result complaints')<a href="{{ route('results.complaints', ['status' => 'all']) }}" class="fs-13">All queries</a>@endcan</div>
                    <ul class="list-group list-group-flush">
                        @foreach ($complaints as $c)
                            <li class="list-group-item fs-13">{{ $c->created_at->format('j M Y') }} · <strong>{{ $c->student->matric_label }}</strong> {{ $c->student->full_name }}: {{ $c->reason }}
                                <span class="badge {{ \App\Models\ResultComplaint::BADGES[$c->status] }} ms-1">{{ \App\Models\ResultComplaint::STATUSES[$c->status] }}</span>@if ($c->response)<div class="text-muted">Answer: {{ $c->response }}</div>@endif</li>
                        @endforeach
                    </ul></div>
            @endif

            @if ($sheet->events->isNotEmpty())
                <div class="card"><div class="card-header"><h6 class="card-title mb-0">History</h6></div>
                    <ul class="list-group list-group-flush">
                        @foreach ($sheet->events as $e)
                            <li class="list-group-item fs-13">{{ $e->created_at->format('j M Y, H:i') }} · <strong>{{ ucfirst($e->action) }}</strong>{{ $e->stage ? ' (' . $e->stage . ')' : '' }} by {{ $e->user?->name ?? 'system' }}@if ($e->note) — {{ $e->note }}@endif</li>
                        @endforeach
                    </ul></div>
            @endif
        </div>
    </div>
</div>
@endsection
