@extends('layouts.master')

@section('content')
@php use App\Models\StudentLoan as L; $n = fn ($v) => number_format((float) $v, 2); @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'My school loans', 'icon' => 'ri-hand-coin-line', 'subtitle' => 'Borrow towards your fees from the school and repay in instalments', 'back' => [route('my.fees'), 'My fees']])
    @include('academics.partials.alerts')
    <div class="row">
        <div class="col-xl-5">
            <div class="card"><div class="card-body">@include('fees.partials.standing', ['standing' => $standing])@unless ($standing->billed)<div class="text-muted">Nothing has been billed for this session yet.</div>@endunless</div></div>
            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">Apply for a loan</h6></div>
                @if ($cannot)
                    <div class="card-body text-muted">{{ $cannot }}</div>
                @else
                    <form method="POST" action="{{ route('my.loans.apply') }}" class="card-body">
                        @csrf
                        <div class="fs-13 mb-2">Against <strong>{{ $invoice->reference }}</strong> ({{ $invoice->title }}). You can borrow up to <strong>{{ $n($max) }}</strong>.</div>
                        <label class="form-label mb-1">Amount</label><input type="number" step="0.01" min="1" max="{{ $max }}" name="amount" value="{{ old('amount', $max) }}" class="form-control mb-2" required>
                        <label class="form-label mb-1">Why you need it <span class="text-muted">(optional)</span></label><textarea name="reason" rows="2" class="form-control mb-2">{{ old('reason') }}</textarea>
                        <div class="fw-medium mt-2 mb-1">Guarantor {{ $guarantor ? '' : '(optional)' }}</div>
                        <div class="row g-2">
                            <div class="col-md-6"><input name="guarantor_name" value="{{ old('guarantor_name') }}" class="form-control form-control-sm" placeholder="Full name" @required($guarantor)></div>
                            <div class="col-md-6"><input name="guarantor_phone" value="{{ old('guarantor_phone') }}" class="form-control form-control-sm" placeholder="Phone number" @required($guarantor)></div>
                            <div class="col-md-6"><input name="guarantor_relationship" value="{{ old('guarantor_relationship') }}" class="form-control form-control-sm" placeholder="Relationship (father, sponsor ...)"></div>
                            <div class="col-md-6"><input name="guarantor_address" value="{{ old('guarantor_address') }}" class="form-control form-control-sm" placeholder="Address"></div>
                        </div>
                        <div class="alert alert-light fs-12 mt-3 mb-2">{{ $terms }}</div>
                        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="accept" value="1" id="acc" required><label class="form-check-label fs-13" for="acc">I accept these terms</label></div>
                        <button class="btn btn-primary w-100"><i class="ri-send-plane-line me-1"></i>Apply</button>
                    </form>
                @endif
            </div>
        </div>
        <div class="col-xl-7">
            @forelse ($loans as $l)
                @php $next = $l->nextInstalment(); @endphp
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center"><div><h6 class="card-title mb-0">{{ $l->reference }}</h6><div class="fs-12 text-muted">{{ $l->session?->name }} · applied {{ $l->created_at->format('j M Y') }}</div></div>
                        <div class="d-flex gap-2 align-items-center"><span class="badge {{ L::BADGES[$l->status] }}">{{ L::STATUSES[$l->status] }}</span>
                            @if ($l->status === 'pending')<form method="POST" action="{{ route('my.loans.cancel', $l->id) }}">@csrf<button class="btn btn-sm btn-link text-danger p-0" data-confirm="Withdraw this application?">Withdraw</button></form>@endif</div></div>
                    <div class="card-body">
                        <div class="row text-center g-2">
                            <div class="col"><div class="text-muted fs-12">Asked</div><div class="fw-semibold">{{ $n($l->amount_requested) }}</div></div>
                            <div class="col"><div class="text-muted fs-12">Approved</div><div class="fw-semibold">{{ $l->amount_approved !== null ? $n($l->amount_approved) : '—' }}</div></div>
                            <div class="col"><div class="text-muted fs-12">To repay</div><div class="fw-semibold">{{ $l->amount_due > 0 ? $n($l->amount_due) : '—' }}</div></div>
                            <div class="col"><div class="text-muted fs-12">Still owed</div><div class="fw-semibold {{ $l->overdue() > 0 ? 'text-danger' : '' }}">{{ $l->status === 'disbursed' ? $n($l->balance) : '—' }}</div></div>
                        </div>
                        @if ($l->decision_note)<div class="fs-13 mt-2"><span class="text-muted">Bursary:</span> {{ $l->decision_note }}</div>@endif
                        @if ($l->status === 'disbursed' && $next)<div class="alert {{ $l->overdue() > 0 ? 'alert-danger' : 'alert-info' }} fs-13 mt-3 mb-0">{{ $l->overdue() > 0 ? 'Overdue: ' . $n($l->overdue()) . '. Pay it at the bursary as soon as you can.' : 'Next repayment: ' . $n($next['amount']) . ' by ' . \Illuminate\Support\Carbon::parse($next['due'])->format('j M Y') . '.' }}</div>@endif
                        @if ($l->instalments)
                            <table class="table table-sm mt-3 mb-0"><thead><tr><th>Due</th><th class="text-end">Amount</th></tr></thead><tbody>
                                @foreach ($l->instalments as $i)<tr><td>{{ \Illuminate\Support\Carbon::parse($i['due'])->format('j M Y') }}</td><td class="text-end">{{ $n($i['amount']) }}</td></tr>@endforeach
                            </tbody></table>
                        @endif
                    </div>
                </div>
            @empty
                <div class="card"><div class="card-body text-center text-muted py-5">{{ $enabled ? 'You have no school loans.' : 'The school loan scheme is not open at the moment.' }}</div></div>
            @endforelse
        </div>
    </div>
</div></div></div>
@endsection
