@extends('layouts.master')

@section('content')
@php
    $canAdmit = auth()->user()->can('Admit applicants');
    $canReject = auth()->user()->can('Reject applicants');
    $row = fn (string $l, $v) => '<li class="list-group-item d-flex justify-content-between gap-3"><span class="text-muted">' . e($l) . '</span><span class="text-end">' . ($v !== null && $v !== '' ? e($v) : '—') . '</span></li>';
    $invoice = $applicant->invoices->where('status', '!=', 'cancelled')->first();
@endphp
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="row"><div class="col-12"><div class="page-title-box d-sm-flex align-items-center justify-content-between">
                <h4 class="mb-sm-0">{{ $applicant->full_name }}</h4>
                <div class="page-title-right"><ol class="breadcrumb m-0">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('applicants.index') }}">Applicants</a></li>
                    <li class="breadcrumb-item active">{{ $applicant->application_no }}</li></ol></div>
            </div></div></div>

            @include('academics.partials.alerts')

            <div class="row">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="card-title mb-0">Application</h6>
                            <span class="badge bg-light text-body border">{{ \App\Models\Applicant::STATUSES[$applicant->status] }}</span>
                        </div>
                        <ul class="list-group list-group-flush">
                            {!! $row('Application no.', $applicant->application_no) !!}
                            {!! $row('Programme', $applicant->programme->name . ' (' . $applicant->programme->code . ')') !!}
                            {!! $row('Faculty', $applicant->programme->department->faculty->name) !!}
                            {!! $row('Session', $applicant->session->name) !!}
                            {!! $row('Entry mode / level', \App\Models\EntryMode::label($applicant->entry_mode) . ' · ' . $applicant->entry_level . 'L') !!}
                            {!! $row('JAMB number', $applicant->jamb_reg_no) !!}
                            {!! $row('UTME score', $applicant->jamb_score) !!}
                            {!! $row('Submitted', $applicant->submitted_at?->format('d M Y, H:i')) !!}
                            {!! $row('Admission batch', $applicant->admission_batch) !!}
                        </ul>
                    </div>

                    <div class="card">
                        <div class="card-header"><h6 class="card-title mb-0">Personal details</h6></div>
                        <ul class="list-group list-group-flush">
                            {!! $row('Email', $applicant->email) !!}
                            {!! $row('Phone', $applicant->phone_number) !!}
                            {!! $row('Gender', $applicant->gender ? ucfirst($applicant->gender) : null) !!}
                            {!! $row('Date of birth', $applicant->date_of_birth?->format('d M Y')) !!}
                            {!! $row('Nationality', $applicant->nationality) !!}
                            {!! $row('State / LGA', trim(($applicant->state_of_origin ?? '') . ' / ' . ($applicant->lga ?? ''), ' /')) !!}
                            {!! $row('Address', $applicant->address) !!}
                            {!! $row('Guardian', trim(($applicant->guardian_name ?? '') . ($applicant->guardian_relationship ? ' (' . $applicant->guardian_relationship . ')' : ''))) !!}
                            {!! $row('Guardian phone', $applicant->guardian_phone) !!}
                        </ul>
                    </div>

                    <div class="card">
                        <div class="card-header"><h6 class="card-title mb-0">Results</h6></div>
                        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                            <thead class="table-light"><tr><th>Exam</th><th>Year</th><th>Exam number</th><th>Subject</th><th>Grade</th></tr></thead>
                            <tbody>@forelse ($applicant->results as $r)<tr><td>{{ \App\Models\ApplicantResult::EXAMS[$r->exam_type] }}</td><td>{{ $r->exam_year }}</td><td>{{ $r->exam_number }}</td><td>{{ $r->subject }}</td><td>{{ $r->grade }}</td></tr>
                                @empty<tr><td colspan="5" class="text-center text-muted py-3">No results entered.</td></tr>@endforelse</tbody>
                        </table></div>
                    </div>
                </div>

                <div class="col-lg-4">
                    @if ($applicant->status !== 'admitted' && ($canAdmit || $canReject))
                        <div class="card"><div class="card-body d-grid gap-2">
                            @if ($canAdmit && in_array($applicant->status, ['pending', 'rejected']))
                                <form method="POST" action="{{ route('applicants.admit', $applicant->id) }}">@csrf<button class="btn btn-success w-100"><i class="ri-user-follow-line me-1"></i>Admit applicant</button></form>
                            @endif
                            @if ($canReject && $applicant->status === 'pending')
                                <form method="POST" action="{{ route('applicants.reject', $applicant->id) }}" onsubmit="return confirm('Reject this application?')">@csrf<button class="btn btn-soft-danger w-100">Reject application</button></form>
                            @endif
                            @if ($applicant->status === 'draft')<div class="text-muted fs-12">Not submitted yet, so it cannot be admitted.</div>@endif
                        </div></div>
                    @endif
                    @if ($applicant->student)
                        <div class="card"><div class="card-body">Admitted as <a href="{{ route('students.show', $applicant->student->id) }}" class="fw-semibold">{{ $applicant->student->matric_number }}</a></div></div>
                    @endif

                    <div class="card">
                        <div class="card-header"><h6 class="card-title mb-0">Checklist</h6></div>
                        <ul class="list-group list-group-flush">
                            @foreach ($checklist as [$key, $label, $ok])<li class="list-group-item d-flex gap-2"><i class="{{ $ok ? 'ri-checkbox-circle-fill text-success' : 'ri-checkbox-blank-circle-line text-muted' }}"></i><span class="fs-13">{{ $label }}</span></li>@endforeach
                        </ul>
                        @if ($invoice && auth()->user()->can('View invoices'))
                            <div class="card-footer"><a href="{{ route('invoices.show', $invoice->id) }}">Screening fee invoice {{ $invoice->reference }}</a> · {{ \App\Models\Invoice::STATUSES[$invoice->status] }}</div>
                        @endif
                    </div>

                    <div class="card">
                        <div class="card-header"><h6 class="card-title mb-0">Documents</h6></div>
                        <ul class="list-group list-group-flush">
                            @forelse ($applicant->documents as $d)
                                <li class="list-group-item"><a href="{{ route('applicants.documents.show', $d->id) }}" target="_blank">{{ $d->label }}</a><div class="text-muted fs-12">{{ $d->original_name }} · {{ number_format($d->size / 1024, 0) }} KB</div></li>
                            @empty
                                <li class="list-group-item text-muted">Nothing uploaded.</li>
                            @endforelse
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
