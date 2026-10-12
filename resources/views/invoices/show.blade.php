@extends('layouts.master')

@section('content')
@php
    $canRecord = auth()->user()->can('Record offline payments') && $invoice->status !== 'cancelled' && $invoice->outstanding > 0;
    $canCancel = auth()->user()->can('Cancel invoices') && $invoice->status === 'unpaid';
    $student = $invoice->payable;
@endphp
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">

            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                        <h4 class="mb-sm-0">{{ $invoice->reference }}</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                                <li class="breadcrumb-item"><a href="{{ route('invoices.index') }}">Invoices</a></li>
                                <li class="breadcrumb-item active">{{ $invoice->reference }}</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            @include('academics.partials.alerts')

            <div class="row">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="card-title mb-0">{{ $invoice->title }}</h6>
                            <span class="badge {{ \App\Models\Invoice::BADGES[$invoice->status] }}">{{ \App\Models\Invoice::STATUSES[$invoice->status] }}</span>
                        </div>
                        <div class="card-body">
                            @if ($student)
                                <p class="mb-3"><a href="{{ $student instanceof \App\Models\Student ? route('students.show', $student->id) : route('applicants.show', $student->id) }}" class="fw-semibold">{{ $student->full_name }}</a>
                                    <span class="text-muted"> · {{ $student->identifier }} · {{ $student->programme->name }}</span></p>
                            @endif
                            <table class="table mb-0">
                                <thead class="table-light"><tr><th>Item</th><th class="text-end">Amount</th></tr></thead>
                                <tbody>
                                    @foreach ($invoice->lines as $line)
                                        <tr><td>{{ $line->description }}</td><td class="text-end">₦{{ number_format((float) $line->amount, 2) }}</td></tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr><th>Total</th><th class="text-end">₦{{ number_format((float) $invoice->total, 2) }}</th></tr>
                                    <tr><td class="text-muted">Paid</td><td class="text-end text-success">₦{{ number_format((float) $invoice->amount_paid, 2) }}</td></tr>
                                    <tr><th>Balance</th><th class="text-end text-danger">₦{{ number_format($invoice->outstanding, 2) }}</th></tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><h6 class="card-title mb-0">Payments</h6></div>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="table-light"><tr><th>Date</th><th>Reference</th><th>Method</th><th class="text-end">Amount</th><th>Status</th><th></th></tr></thead>
                                <tbody>
                                    @forelse ($invoice->payments as $p)
                                        <tr>
                                            <td>{{ ($p->paid_at ?? $p->created_at)->format('d M Y') }}</td>
                                            <td>{{ $p->reference }}@if ($p->gateway_reference)<div class="text-muted fs-12">{{ $p->gateway_reference }}</div>@endif</td>
                                            <td>{{ $p->gateway === 'manual' ? (\App\Models\Payment::CHANNELS[$p->channel] ?? 'Offline') : ucfirst($p->gateway) }}@if ($p->recorder)<div class="text-muted fs-12">by {{ $p->recorder->name }}</div>@endif</td>
                                            <td class="text-end">₦{{ number_format((float) $p->amount, 2) }}</td>
                                            <td>@if ($p->status === 'reversed')<span class="badge bg-danger-subtle text-danger">Reversed</span><div class="text-muted fs-12">{{ $p->reversed_at?->format('d M Y') }}: {{ $p->reversal_reason }}</div>
                                                @elseif ($p->status === 'reversal')<span class="badge bg-secondary-subtle text-secondary">Reversal entry</span><div class="text-muted fs-12">{{ $p->note }}</div>
                                                @else{{ ucfirst($p->status) }}@if ($p->note && in_array($p->status, ['failed', 'abandoned'], true))<div class="text-muted fs-12">{{ $p->note }}</div>@endif @endif</td>
                                            <td class="text-end text-nowrap">@if (in_array($p->status, ['successful', 'reversed'], true))<a href="{{ route('payments.receipt', $p->id) }}" class="btn btn-sm btn-soft-primary">Receipt</a>@endif
                                                @if (in_array($p->status, ['pending', 'abandoned'], true) && $p->gateway !== 'manual')<form method="POST" action="{{ route('payments.check', $p->id) }}" class="d-inline">@csrf<button class="btn btn-sm btn-soft-warning">Check with {{ ucfirst($p->gateway) }}</button></form>@endif
                                                @if ($p->status === 'successful')@can('Reverse payments')<a href="{{ route('payments.reverse', $p->id) }}" class="btn btn-sm btn-soft-danger">Reverse…</a>@endcan @endif</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6" class="text-center text-muted py-4">No payments yet.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    @if ($canRecord)
                        <div class="card">
                            <div class="card-header"><h6 class="card-title mb-0">Record an offline payment</h6></div>
                            <form method="POST" action="{{ route('invoices.payments.store', $invoice->id) }}" class="card-body">
                                @csrf
                                <div class="mb-3">
                                    <label class="form-label">Amount (₦) <span class="text-danger">*</span></label>
                                    <input type="number" step="0.01" min="0.01" max="{{ $invoice->outstanding }}" name="amount" value="{{ old('amount', $invoice->outstanding) }}" class="form-control @error('amount') is-invalid @enderror" required>
                                    @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    <div class="form-text">Up to ₦{{ number_format($invoice->outstanding, 2) }} (the balance).</div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">How was it paid? <span class="text-danger">*</span></label>
                                    <select name="channel" class="form-select" required>
                                        @foreach (\App\Models\Payment::CHANNELS as $v => $l)<option value="{{ $v }}" @selected(old('channel') === $v)>{{ $l }}</option>@endforeach
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Teller / receipt number</label>
                                    <input type="text" name="gateway_reference" value="{{ old('gateway_reference') }}" class="form-control @error('gateway_reference') is-invalid @enderror" maxlength="100">
                                    @error('gateway_reference')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Date paid</label>
                                    <input type="date" name="paid_on" max="{{ now()->toDateString() }}" value="{{ old('paid_on', now()->toDateString()) }}" class="form-control @error('paid_on') is-invalid @enderror">
                                    @error('paid_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Note</label>
                                    <textarea name="note" rows="2" class="form-control" maxlength="500">{{ old('note') }}</textarea>
                                </div>
                                <button class="btn btn-primary w-100" type="submit"><i class="ri-save-line me-1"></i>Record payment</button>
                            </form>
                        </div>
                    @endif

                    @if ($canCancel)
                        <form method="POST" action="{{ route('invoices.cancel', $invoice->id) }}" onsubmit="return confirm('Cancel this invoice? The student will no longer owe it.')">
                            @csrf
                            <button class="btn btn-soft-danger w-100" type="submit">Cancel invoice</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
