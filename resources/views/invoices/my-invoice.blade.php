@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="page-title-box d-flex justify-content-between align-items-center">
                <h4 class="mb-0">{{ $invoice->reference }}</h4>
                <a href="{{ route('my.fees') }}" class="btn btn-light">Back to My Fees</a>
            </div>
            @include('academics.partials.alerts')

            <div class="card">
                <div class="card-header d-flex justify-content-between"><h6 class="card-title mb-0">{{ $invoice->title }}</h6>
                    <span class="badge {{ \App\Models\Invoice::BADGES[$invoice->status] }}">{{ \App\Models\Invoice::STATUSES[$invoice->status] }}</span></div>
                <div class="card-body">
                    <table class="table mb-0">
                        <thead class="table-light"><tr><th>Item</th><th class="text-end">Amount</th></tr></thead>
                        <tbody>@foreach ($invoice->lines as $line)<tr><td>{{ $line->description }}</td><td class="text-end">₦{{ number_format((float) $line->amount, 2) }}</td></tr>@endforeach</tbody>
                        <tfoot>
                            <tr><th>Total</th><th class="text-end">₦{{ number_format((float) $invoice->total, 2) }}</th></tr>
                            <tr><td class="text-muted">Paid</td><td class="text-end text-success">₦{{ number_format((float) $invoice->amount_paid, 2) }}</td></tr>
                            <tr><th>Balance</th><th class="text-end text-danger">₦{{ number_format($invoice->outstanding, 2) }}</th></tr>
                        </tfoot>
                    </table>
                </div>
                @if ($invoice->outstanding > 0)
                    @php $plan = $invoice->installment_plan ?: []; $state = $invoice->windowState(); @endphp
                    @if (count($plan) > 1)
                        <div class="card-footer">
                            <div class="fw-semibold mb-1">Pay in {{ count($plan) }} instalments</div>
                            @php $running = 0; @endphp
                            @foreach ($plan as $i => $part)
                                @php $running += (float) $part; $done = (float) $invoice->amount_paid + 0.001 >= $running; @endphp
                                <div class="d-flex justify-content-between"><span>Instalment {{ $i + 1 }} @if ($done)<span class="badge bg-success-subtle text-success">Paid</span>@endif</span><span>₦{{ number_format((float) $part, 2) }}</span></div>
                            @endforeach
                            <div class="mt-2 text-muted">Pay at least ₦{{ number_format($invoice->minimumPayment(), 2) }} now to stay on schedule.</div>
                        </div>
                    @endif
                    @if ($state === 'open' && $invoice->closes_at)
                        <div class="card-footer text-muted">Payment closes on {{ $invoice->closes_at->format('j M Y, g:i A') }}.</div>
                    @endif
                    <div class="card-footer">@include('payments.partials.pay-form', ['action' => route('my.fees.pay', $invoice->id)])</div>
                    <div class="card-footer text-muted">You can also pay using the details given by the bursary, quoting <strong>{{ $invoice->reference }}</strong>. Once the bursary records that payment it appears below with a receipt.</div>
                @endif
            </div>

            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">Payments</h6></div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light"><tr><th>Date</th><th>Reference</th><th class="text-end">Amount</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($invoice->payments->whereIn('status', ['successful', 'reversed', 'reversal']) as $p)
                                <tr><td>{{ ($p->paid_at ?? $p->created_at)->format('d M Y') }}</td><td>{{ $p->reference }}@if ($p->status === 'reversed')<div class="text-danger fs-12">Reversed: {{ $p->reversal_reason }}</div>@elseif ($p->status === 'reversal')<div class="text-muted fs-12">Reversal entry</div>@endif</td><td class="text-end">₦{{ number_format((float) $p->amount, 2) }}</td>
                                    <td class="text-end">@if ($p->status !== 'reversal')<a href="{{ route('my.receipt', $p->id) }}" class="btn btn-sm btn-soft-primary">Receipt</a>@endif</td></tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-4">No payments yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
