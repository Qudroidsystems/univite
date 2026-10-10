@extends('layouts.master')

@section('content')
@php $student = $payment->invoice->payable; @endphp
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid" style="max-width:720px">
            <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
                <h4 class="mb-0">Payment receipt</h4>
                <div class="d-flex gap-2">
                    @if (auth()->user()->student && $payment->status === 'successful')<form method="POST" action="{{ route('my.documents.email', ['receipt', $payment->id]) }}">@csrf<button class="btn btn-soft-primary"><i class="ri-mail-send-line me-1"></i>Email me a copy</button></form>@endif
                    <button class="btn btn-primary" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print</button>
                </div>
            </div>
            <div class="card">
                <div class="card-body">
                    @include('partials.letterhead', ['subtitle' => 'Official payment receipt'])
                    @if ($payment->status === 'reversed')
                        <div class="alert alert-danger text-center fw-semibold">REVERSED on {{ $payment->reversed_at?->format('j M Y') }}. This receipt is no longer valid. Reason: {{ $payment->reversal_reason }}</div>
                    @endif
                    <table class="table table-sm">
                        <tr><th style="width:35%">Receipt no.</th><td>{{ $payment->reference }}</td></tr>
                        <tr><th>Date</th><td>{{ ($payment->paid_at ?? $payment->created_at)->format('d M Y, H:i') }}</td></tr>
                        <tr><th>Student</th><td>{{ $student?->full_name }}</td></tr>
                        <tr><th>{{ $student instanceof \App\Models\Student ? 'Matric number' : 'Application number' }}</th><td>{{ $student?->identifier }}</td></tr>
                        <tr><th>Programme</th><td>{{ $student?->programme?->name }}</td></tr>
                        <tr><th>Invoice</th><td>{{ $payment->invoice->reference }} — {{ $payment->invoice->title }}</td></tr>
                        <tr><th>Method</th><td>{{ $payment->gateway === 'manual' ? (\App\Models\Payment::CHANNELS[$payment->channel] ?? 'Offline') : ucfirst($payment->gateway) }}</td></tr>
                        @if ($payment->gateway_reference)<tr><th>Teller / receipt no.</th><td>{{ $payment->gateway_reference }}</td></tr>@endif
                        <tr><th>Amount paid</th><td class="fs-5 fw-semibold">₦{{ number_format((float) $payment->amount, 2) }}</td></tr>
                        <tr><th>Invoice balance</th><td>₦{{ number_format($payment->invoice->outstanding, 2) }}</td></tr>
                        @if ($payment->recorder)<tr><th>Received by</th><td>{{ $payment->recorder->name }}</td></tr>@endif
                    </table>
                    @include('partials.signatory', ['role' => 'bursar'])
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
