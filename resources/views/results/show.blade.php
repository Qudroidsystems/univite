@extends('layouts.master')

@section('content')
@php $edit = $canEnter && $sheet->isEditable(); @endphp
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="mb-0">{{ $sheet->course->code }} · {{ $sheet->course->title }}
                    <span class="badge {{ \App\Models\ResultSheet::BADGES[$sheet->status] }} fs-12 align-middle">{{ \App\Models\ResultSheet::STATUSES[$sheet->status] }}{{ $sheet->stage ? ' · ' . $sheet->stage->name : '' }}</span></h4>
                <a href="{{ route('results.sheets.index') }}" class="btn btn-light">Back</a>
            </div>
            @include('academics.partials.alerts')
            <p class="text-muted">{{ $sheet->session->name }} · {{ $sheet->semesterType->name }} · {{ $sheet->course->credit_units }} units · CA out of {{ rtrim(rtrim(number_format($caMax, 2), '0'), '.') }}, exam out of {{ rtrim(rtrim(number_format($examMax, 2), '0'), '.') }}</p>

            @if ($edit)
                <div class="card">
                    <div class="card-body d-flex flex-wrap gap-3 align-items-end">
                        <form method="POST" action="{{ route('results.sheets.upload', $sheet->id) }}" enctype="multipart/form-data" class="d-flex gap-2 align-items-end">
                            @csrf
                            <div><label class="form-label mb-1">Upload scores (CSV or Excel)</label><input type="file" name="file" class="form-control" accept=".csv,.txt,.xlsx,.xls" required></div>
                            <button class="btn btn-soft-primary" type="submit"><i class="ri-upload-2-line me-1"></i>Upload</button>
                        </form>
                        <a href="{{ route('results.sheets.template', $sheet->id) }}" class="btn btn-light"><i class="ri-download-2-line me-1"></i>Download sheet to fill in</a>
                    </div>
                </div>
            @endif

            <form method="POST" action="{{ route('results.sheets.save', $sheet->id) }}">
                @csrf
                <div class="card">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light"><tr><th>#</th><th>Matric number</th><th>Name</th><th style="width:110px">CA</th><th style="width:110px">Exam</th><th>Total</th><th>Grade</th></tr></thead>
                            <tbody>
                            @forelse ($rows as $i => $r)
                                <tr>
                                    <td>{{ $loop->iteration }}</td>
                                    <td>{{ $r->student->matric_label }}</td>
                                    <td>{{ $r->student->full_name }}</td>
                                    <td>@if ($edit)<input type="number" step="0.01" min="0" max="{{ $caMax }}" name="scores[{{ $r->student_id }}][ca]" value="{{ $r->ca }}" class="form-control form-control-sm">@else{{ $r->ca }}@endif</td>
                                    <td>@if ($edit)<input type="number" step="0.01" min="0" max="{{ $examMax }}" name="scores[{{ $r->student_id }}][exam]" value="{{ $r->exam }}" class="form-control form-control-sm">@else{{ $r->exam }}@endif</td>
                                    <td>{{ $r->total }}</td>
                                    <td>@if ($r->grade)<span class="badge {{ $r->is_pass ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' }}">{{ $r->grade }}</span>@endif</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted py-4">No student has an approved registration for this course yet.</td></tr>
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
                                <div class="col-md-1"><input type="number" step="0.01" name="ca" value="{{ $r->ca }}" class="form-control form-control-sm" required></div>
                                <div class="col-md-1"><input type="number" step="0.01" name="exam" value="{{ $r->exam }}" class="form-control form-control-sm" required></div>
                                <div class="col-md-5"><input name="reason" class="form-control form-control-sm" placeholder="Reason for the correction" required></div>
                                <div class="col-md-2"><button class="btn btn-sm btn-soft-warning" type="submit">Correct</button></div>
                            </form>
                        @endforeach
                    </div></div>
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
