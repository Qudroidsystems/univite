<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Examination seating slip</title>
@include('partials.brand-head')
<link href="{{ asset('theme/layouts/assets/css/bootstrap.min.css') }}" rel="stylesheet">
<style>body{padding:20px;font-size:13px}.slip{border:1px solid #ccc;border-radius:6px;padding:12px;margin-bottom:12px;page-break-inside:avoid}@media print{.no-print{display:none}}</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script></head>
<body>
<div class="no-print mb-2"><button onclick="window.print()" class="btn btn-sm btn-primary">Print</button></div>
@include('partials.letterhead', ['subtitle' => 'Examination seating slip'])
<table class="table table-sm mb-3"><tr><th style="width:25%">Name</th><td>{{ $student->full_name }}</td><th style="width:20%">Matric number</th><td>{{ $student->matric_number }}</td></tr><tr><th>Programme</th><td colspan="3">{{ $student->programme?->name }}</td></tr></table>
@forelse ($seats as $s)
    <div class="slip d-flex justify-content-between gap-3">
        <table class="table table-sm table-borderless mb-0">
            <tr><th style="width:30%">Paper</th><td><strong>{{ $s->course?->code }}</strong> · {{ $s->course?->title }}</td></tr>
            <tr><th>Date and time</th><td>{{ $s->date->format('l j F Y') }}, {{ substr($s->starts_at, 0, 5) }}–{{ substr($s->ends_at, 0, 5) }}</td></tr>
            <tr><th>Hall</th><td>{{ $s->venue?->name }}@if ($s->venue?->entry_info) · entry: {{ $s->venue->entry_info }}@endif</td></tr>
            <tr><th>Seat number</th><td class="fs-5 fw-bold">{{ $s->seat_number }}</td></tr>
            <tr><th>Check code</th><td class="font-monospace">{{ $s->code }}</td></tr>
        </table>
        <div class="qr" data-url="{{ route('seating.verify', $s->code) }}" style="width:110px;height:110px;flex-shrink:0"></div>
    </div>
@empty
    <p class="text-muted">No published seats yet.</p>
@endforelse
<p class="text-muted fs-12">Bring this slip and your identity card to every paper. Sit only in the seat shown. The invigilator scans the code to confirm your seat.</p>
<script>document.querySelectorAll('.qr').forEach(function (el) { if (window.QRCode) { new QRCode(el, { text: el.dataset.url, width: 110, height: 110 }); } });</script>
</body></html>
