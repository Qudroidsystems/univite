@extends('layouts.master')

@section('content')
@php $s = $posting->student; $ex = $posting->exercise; $sc = $posting->school; @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid" style="max-width:800px">
    <div class="d-print-none mb-3 text-end"><button onclick="window.print()" class="btn btn-primary"><i class="ri-printer-line me-1"></i>Print</button></div>
    <div class="card"><div class="card-body p-5">
        @include('partials.letterhead', ['subtitle' => 'Teaching Practice Posting'])
        <p class="text-end">{{ ($posting->posted_at ?? now())->format('j F Y') }}</p>
        <p>The Principal / Head Teacher<br>{{ $sc->name }}<br>{{ $sc->address }}{{ $sc->place() ? ', ' . $sc->place() : '' }}</p>
        <p>Dear Sir/Madam,</p>
        <p class="fw-semibold text-uppercase">Teaching practice: {{ $s->full_name }} ({{ $s->matric_number }})</p>
        <p>The above-named student of {{ $s->programme?->name }} has been posted to your school for teaching practice{{ $ex->starts_on ? ' from ' . $ex->starts_on->format('j F Y') . ($ex->ends_on ? ' to ' . $ex->ends_on->format('j F Y') : '') : '' }}{{ $posting->subject ? ', to teach ' . $posting->subject : '' }}.</p>
        <p>We would be grateful if you would allow the student to teach under your supervision and that of the subject teacher. Supervisors from the institution will visit to observe lessons.</p>
        @if ($ex->letter_text)<p>{!! nl2br(e($ex->letter_text)) !!}</p>@endif
        <p>Thank you for your continued support.</p>
        <div class="mt-5">@include('partials.signatory', ['role' => 'registrar'])</div>
    </div></div>
</div></div></div>
@endsection
