{{-- Pay an invoice online. Pass $invoice, $action (the form's URL), $choices (gateway => label) and $blocker (why not, or null). --}}
@php $pending = $invoice->payments->where('status', 'pending')->where('gateway', '!=', 'manual'); @endphp
@foreach ($pending as $p)
    <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <span>Payment {{ $p->reference }} of ₦{{ number_format((float) $p->amount, 2) }} with {{ ucfirst($p->gateway) }} is waiting for confirmation.@if ($p->gateway === 'remita' && $p->gateway_reference) RRR <strong>{{ $p->gateway_reference }}</strong>; you can also pay it at any bank.@endif</span>
        <a href="{{ route('payments.return', $p->reference) }}" class="btn btn-sm btn-warning">Check again</a>
    </div>
@endforeach
@if ($blocker)
    @if ($invoice->outstanding > 0)<p class="text-muted mb-0">{{ $blocker }}</p>@endif
@else
    @php $min = $invoice->minimumPayment(); $fixed = $min + 0.001 >= $invoice->outstanding; @endphp
    <form method="POST" action="{{ $action }}" class="row g-2 align-items-end">@csrf
        <div class="col-sm-5"><label class="form-label">Pay with</label>
            <select name="gateway" class="form-select" required>@foreach ($choices as $k => $l)<option value="{{ $k }}" @selected(old('gateway') === $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="col-sm-4"><label class="form-label">Amount (₦)</label>
            @if ($fixed)
                <input type="hidden" name="amount" value="{{ $invoice->outstanding }}"><div class="form-control-plaintext fw-semibold">{{ number_format($invoice->outstanding, 2) }}</div>
            @else
                <input type="number" name="amount" step="0.01" min="{{ $min }}" max="{{ $invoice->outstanding }}" value="{{ old('amount', $min) }}" class="form-control" required>
            @endif
        </div>
        <div class="col-sm-3"><button class="btn btn-success w-100"><i class="ri-bank-card-line me-1"></i>Pay online</button></div>
        @unless ($fixed)<div class="col-12 form-text">At least ₦{{ number_format($min, 2) }}, up to the whole balance of ₦{{ number_format($invoice->outstanding, 2) }}.</div>@endunless
    </form>
@endif
