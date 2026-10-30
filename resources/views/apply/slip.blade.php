@extends('layouts.public')

@section('title', 'Screening slip')

@section('content')
@php $passport = $applicant->documents->firstWhere('type', 'passport'); @endphp
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="d-flex justify-content-between mb-3 d-print-none">
            <a href="{{ route('apply.dashboard') }}" class="btn btn-light">Back</a>
            <button class="btn btn-primary" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print slip</button>
        </div>
        <div class="card pub-card">
            <div class="card-body p-4">
                @include('partials.letterhead', ['subtitle' => 'Post-UTME screening slip · ' . ($applicant->session->name) . ' session'])
                <div class="d-flex gap-4">
                    <table class="table table-sm flex-grow-1 mb-0">
                        <tr><th style="width:38%">Application no.</th><td class="fw-semibold">{{ $applicant->application_no }}</td></tr>
                        <tr><th>Name</th><td>{{ $applicant->full_name }}</td></tr>
                        <tr><th>JAMB number</th><td>{{ $applicant->jamb_reg_no }}</td></tr>
                        @if ($applicant->jamb_score)<tr><th>UTME score</th><td>{{ $applicant->jamb_score }}</td></tr>@endif
                        <tr><th>Programme</th><td>{{ $applicant->programme->applicationLabel() }}</td></tr>
                        <tr><th>Faculty</th><td>{{ $applicant->programme->department->faculty->name }}</td></tr>
                        <tr><th>Entry mode</th><td>{{ \App\Models\EntryMode::label($applicant->entry_mode) }}</td></tr>
                        <tr><th>Submitted</th><td>{{ $applicant->submitted_at?->format('d M Y, H:i') }}</td></tr>
                        <tr><th>Email</th><td>{{ $applicant->email }}</td></tr>
                        <tr><th>Phone</th><td>{{ $applicant->phone_number }}</td></tr>
                    </table>
                    @if ($passport)<img src="{{ route('apply.documents.show', $passport->id) }}" alt="Passport" style="width:130px;height:160px;object-fit:cover" class="border rounded align-self-start">@endif
                </div>
                <p class="text-muted fs-12 mt-4 mb-0">Bring this slip, your original documents and any other items the university tells you about when you are called for screening. Keep your application number.</p>
            </div>
        </div>
    </div>
</div>
@endsection
