@extends('layouts.master')

@section('content')
@php
    $edit = $canEnter && $sheet->isEditable();
    $locked = ! $edit && $canCorrect;
    $items = $scheme?->items ?? collect();
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
    $marksOf = fn ($r) => $r->assessmentScores->mapWithKeys(fn ($m) => [$m->assessment_item_id => $fmt($m->score)]);
@endphp
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="mb-0">{{ $sheet->course->code }} · {{ $sheet->course->title }} <span class="fs-13 fw-normal"><x-course-tag :course="$sheet->course" full /></span>
                    <span class="badge {{ \App\Models\ResultSheet::BADGES[$sheet->status] }} fs-12 align-middle">{{ \App\Models\ResultSheet::STATUSES[$sheet->status] }}{{ $sheet->stage ? ' · ' . $sheet->stage->name : '' }}</span></h4>
                <a href="{{ route('results.sheets.index') }}" class="btn btn-light">Back</a>
            </div>
            @include('academics.partials.alerts')
            <p class="text-muted">{{ $sheet->session->name }} · {{ $sheet->semesterType->name }} · {{ $sheet->course->credit_units }} units · CA out of {{ $fmt($caMax) }}, exam out of {{ $fmt($examMax) }}</p>
            <x-department-leaders :department="$sheet->course->department" label="Course department" />
            @if ($scheme)
                <p class="text-muted fs-13 mt-n2"><i class="ri-survey-line me-1"></i>Assessment scheme <strong>{{ $scheme->name }}</strong>: {{ $scheme->summary() }}. Enter each mark and the CA, exam, total and grade add up as you type, or type a CA or exam total directly. Save as often as you like; every student needs a total before you submit.</p>
            @endif

            @if ($newer && $newer['differs'])
                <div class="alert alert-warning d-flex flex-wrap gap-2 align-items-center justify-content-between">
                    <span><i class="ri-error-warning-line me-1"></i>{{ $sheet->course->code }} now uses <strong>{{ $newer['scheme']?->name ?? 'plain CA and exam' }}</strong>{{ $newer['scheme'] ? ' (' . $newer['scheme']->summary() . ')' : '' }}, but this sheet still has {{ $scheme?->name ?? 'plain CA and exam' }}.
                        @if (! $sheet->isEditable()) It has been submitted, so it keeps its assessments unless it is returned to the lecturer.@elseif ($schemes->isEmpty()) Ask the exams office to move it.@endif</span>
                    @if ($sheet->isEditable() && $schemes->isNotEmpty())
                        <form method="POST" action="{{ route('results.sheets.scheme', $sheet->id) }}" data-confirm="Move this sheet to {{ $newer['scheme']?->name ?? 'plain CA and exam' }}? Marks already entered are kept: an assessment with the same code keeps its mark, the rest of a CA or exam is spread over the new assessments.">@csrf
                            <input type="hidden" name="assessment_scheme_id" value="{{ $newer['scheme']?->id }}"><button class="btn btn-sm btn-warning">Move to {{ $newer['scheme']?->name ?? 'plain CA and exam' }}</button></form>
                    @endif
                </div>
            @endif

            @if ($schemes->isNotEmpty())
                <form method="POST" action="{{ route('results.sheets.scheme', $sheet->id) }}" class="card" data-confirm="Change the assessments on this sheet? Marks already entered are kept: an assessment with the same code keeps its mark, the rest of a CA or exam is spread over the new assessments.">
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
                        @include('results.partials.download-menu', ['sheet' => $sheet, 'label' => 'Download score sheet'])
                        <div class="text-muted fs-12 w-100">Detailed has a column per assessment; totals only has the CA and exam totals. Both carry the marks already entered and refuse marks out of range. Fill either in offline and upload it here or from the UniVite app.</div>
                    </div>
                </div>
            @else
                <div class="d-flex justify-content-end mb-2">@include('results.partials.download-menu', ['sheet' => $sheet, 'label' => 'Download', 'small' => true])</div>
            @endif

            <form method="POST" action="{{ route($locked ? 'results.sheets.correct' : 'results.sheets.save', $sheet->id) }}" @if ($locked) data-lock-form data-confirm="Save your changes to these scores? Each change is recorded on the sheet's history." @endif>
                @csrf
                @if ($locked && $rows->isNotEmpty())
                    <div class="alert alert-info fs-13 d-flex flex-wrap gap-2 align-items-center"><i class="ri-lock-2-line fs-16"></i>
                        <span class="flex-grow-1" style="min-width:240px">You can change scores on this sheet before it is published. Each score is locked: click its lock to unlock it, make the change, then click the lock again. Save once every score is locked.</span>
                        <input name="note" class="form-control form-control-sm" style="max-width:320px" maxlength="400" placeholder="Reason for the change (shown on the history)">
                    </div>
                @endif
                @include('results.partials.score-table', ['rows' => $rows, 'scheme' => $scheme, 'edit' => $edit || $locked, 'locked' => $locked, 'caMax' => $caMax, 'examMax' => $examMax, 'saveLabel' => $locked ? 'Save changes' : null])
                @if ($edit && $rows->isNotEmpty() && $missing)
                    <p class="text-muted fs-13 mt-n2">{{ $missing }} student(s) still need scores.</p>
                @endif
            </form>

            @if ($edit && $rows->isNotEmpty())
                <form method="POST" action="{{ route('results.sheets.submit', $sheet->id) }}" data-confirm="Submit these scores for approval? You will not be able to change them unless they are returned to you.">
                    @csrf<button class="btn btn-primary mb-3" type="submit"><i class="ri-send-plane-line me-1"></i>Submit for approval</button>
                </form>
            @endif

            @if ($canReview)
                <div class="card"><form method="POST" action="{{ route('results.sheets.decide', $sheet->id) }}" class="card-body d-flex flex-wrap gap-2 align-items-start" data-lock-guard>
                    @csrf
                    <input name="note" class="form-control" style="flex:1;min-width:240px" placeholder="Note (needed when returning the sheet)">
                    @if ($canApprove)<button class="btn btn-success" name="decision" value="approve"><i class="ri-check-line me-1"></i>Approve{{ $sheet->stage ? ' as ' . $sheet->stage->name : '' }}</button>@endif
                    @if ($sheet->status === 'approved' && auth()->user()->can('Publish results'))<button class="btn btn-primary" name="decision" value="publish" data-confirm="Publish these results? Students will see them immediately."><i class="ri-megaphone-line me-1"></i>Publish to students</button>@endif
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
