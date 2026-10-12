@extends('layouts.master')

@section('content')
@php $p = $visit->user; @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid" style="max-width:800px">
    <div class="d-print-none mb-3 text-end"><button onclick="window.print()" class="btn btn-primary"><i class="ri-printer-line me-1"></i>Print</button></div>
    <div class="card"><div class="card-body p-5">
        @include('partials.letterhead', ['subtitle' => 'Health Centre · Medical excuse note'])
        <p class="mt-4">This is to confirm that <strong>{{ $p->student?->full_name ?: $p->name }}</strong>
            ({{ $p->student?->matric_number ?? $p->staffProfile?->staff_number }}{{ $p->student?->programme ? ', ' . $p->student->programme->name : '' }})
            was seen at the Health Centre on {{ $visit->seen_at->format('j F Y') }} and should be excused from lectures, examinations and other duties
            from <strong>{{ $visit->excused_from->format('l j F Y') }}</strong> to <strong>{{ $visit->excused_to->format('l j F Y') }}</strong>
            ({{ $visit->rest_days }} day{{ $visit->rest_days === 1 ? '' : 's' }}).</p>
        <p class="text-muted fs-13">The reason is confidential and is not stated on this note.</p>
        <div class="d-flex justify-content-between align-items-end mt-5">
            <div><div style="border-top:1px solid #333;min-width:220px" class="pt-1">{{ $visit->clinician?->name }}</div><div class="fs-12 text-muted">Health Centre</div></div>
            <div class="text-center"><div id="excuse-qr" data-url="{{ route('excuse.check', $visit->excuse_token) }}"></div><div class="fs-11 text-muted mt-1">Scan to confirm</div></div>
        </div>
    </div></div>
</div></div></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>(function () { var el = document.getElementById('excuse-qr'); if (window.QRCode && el) { new QRCode(el, { text: el.dataset.url, width: 96, height: 96 }); } })();</script>
@endsection
