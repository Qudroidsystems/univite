@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="page-title-box"><h4 class="mb-0">My Fees</h4></div>
            @include('academics.partials.alerts')

            <div class="card border-0 {{ $owing > 0 ? 'bg-danger-subtle' : 'bg-success-subtle' }}">
                <div class="card-body">
                    @if ($owing > 0)
                        <div class="text-muted">You currently owe</div><h3 class="mb-0">₦{{ number_format($owing, 2) }}</h3>
                    @else
                        <h5 class="mb-0"><i class="ri-checkbox-circle-line me-1"></i>You have nothing outstanding.</h5>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Invoice</th><th>Session</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Status</th></tr></thead>
                        <tbody>
                            @forelse ($invoices as $invoice)
                                <tr>
                                    <td><a href="{{ route('my.fees.show', $invoice->id) }}" class="fw-semibold">{{ $invoice->reference }}</a><div class="text-muted fs-12">{{ $invoice->title }}</div></td>
                                    <td>{{ $invoice->session?->name }}</td>
                                    <td class="text-end">₦{{ number_format((float) $invoice->total, 2) }}</td>
                                    <td class="text-end">₦{{ number_format((float) $invoice->amount_paid, 2) }}</td>
                                    <td class="text-end fw-semibold">₦{{ number_format($invoice->outstanding, 2) }}</td>
                                    <td><span class="badge {{ \App\Models\Invoice::BADGES[$invoice->status] }}">{{ \App\Models\Invoice::STATUSES[$invoice->status] }}</span></td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted py-5">No invoices have been raised for you yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
