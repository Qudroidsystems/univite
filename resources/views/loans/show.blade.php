@extends('layouts.master')

@section('content')
@php use App\Models\StudentLoan as L; $n = fn ($v) => number_format((float) $v, 2); $s = $loan->student; @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Loan ' . $loan->reference, 'icon' => 'ri-hand-coin-line', 'subtitle' => $s?->full_name . ' · ' . $s?->matric_number . ' · ' . $s?->programme?->name, 'back' => [route('loans.index'), 'All loans']])
    @include('academics.partials.alerts')
    <div class="row">
        <div class="col-xl-5">
            <div class="card"><div class="card-body">
                <div class="d-flex justify-content-between mb-2"><span class="badge {{ L::BADGES[$loan->status] }} fs-12">{{ L::STATUSES[$loan->status] }}</span><span class="text-muted fs-12">Applied {{ $loan->created_at->format('j M Y') }}</span></div>
                <table class="table table-sm mb-0">
                    <tr><th>Against invoice</th><td>@if ($loan->invoice)<a href="{{ route('invoices.show', $loan->invoice) }}">{{ $loan->invoice->reference }}</a> · {{ $n($loan->invoice->total) }} (balance {{ $n($loan->invoice->outstanding) }})@else — @endif</td></tr>
                    <tr><th>Asked for</th><td>{{ $n($loan->amount_requested) }}</td></tr>
                    @if ($loan->amount_approved !== null)<tr><th>Approved</th><td>{{ $n($loan->amount_approved) }} + {{ rtrim(rtrim(number_format($loan->interest_rate, 2), '0'), '.') }}% = <strong>{{ $n($loan->amount_due) }}</strong> to repay</td></tr>@endif
                    @if ($loan->status === 'disbursed' || $loan->status === 'settled')<tr><th>Repaid</th><td>{{ $n($loan->amount_repaid) }} · still owed <strong>{{ $n($loan->balance) }}</strong>@if ($loan->overdue() > 0)<span class="text-danger"> · {{ $n($loan->overdue()) }} overdue</span>@endif</td></tr>@endif
                    <tr><th>Reason</th><td>{{ $loan->reason ?: '—' }}</td></tr>
                    <tr><th>Guarantor</th><td>{{ $loan->guarantor_name ?: '—' }}@if ($loan->guarantor_phone) · {{ $loan->guarantor_phone }}@endif @if ($loan->guarantor_relationship)({{ $loan->guarantor_relationship }})@endif<div class="fs-12 text-muted">{{ $loan->guarantor_address }}</div></td></tr>
                    @if ($loan->decided_at)<tr><th>Decided</th><td>{{ $loan->decider?->name }}, {{ $loan->decided_at->format('j M Y') }}@if ($loan->decision_note)<div class="fs-12">{{ $loan->decision_note }}</div>@endif</td></tr>@endif
                    @if ($loan->close_note)<tr><th>Closed</th><td>{{ $loan->close_note }}</td></tr>@endif
                </table>
            </div></div>

            @if ($loan->status === 'pending')
                @can('Approve school loans')
                    <form method="POST" action="{{ route('loans.approve', $loan->id) }}" class="card">@csrf
                        <div class="card-header"><h6 class="card-title mb-0">Approve</h6></div>
                        <div class="card-body row g-2">
                            <div class="col-6"><label class="form-label mb-1">Amount</label><input type="number" step="0.01" name="amount" value="{{ old('amount', $loan->amount_requested) }}" class="form-control form-control-sm" required></div>
                            <div class="col-6"><label class="form-label mb-1">Interest %</label><input type="number" step="0.01" min="0" max="100" name="interest" value="{{ old('interest', $interest) }}" class="form-control form-control-sm"></div>
                            <div class="col-6"><label class="form-label mb-1">Instalments</label><select name="instalments" class="form-select form-select-sm">@foreach (range(1, $maxInstalments) as $i)<option @selected($i === min(3, $maxInstalments))>{{ $i }}</option>@endforeach</select></div>
                            <div class="col-6"><label class="form-label mb-1">First repayment due</label><input type="date" name="first_due" value="{{ old('first_due', $firstDue) }}" class="form-control form-control-sm"></div>
                            <div class="col-12"><input name="note" class="form-control form-control-sm" placeholder="Note (optional)"></div>
                        </div>
                        <div class="card-footer text-end"><button class="btn btn-success btn-sm">Approve loan</button></div>
                    </form>
                    <form method="POST" action="{{ route('loans.decline', $loan->id) }}" class="card">@csrf
                        <div class="card-body d-flex gap-2"><input name="note" class="form-control form-control-sm" placeholder="Why it is declined" required><button class="btn btn-outline-danger btn-sm text-nowrap">Decline</button></div>
                    </form>
                @endcan
            @endif
            @if ($loan->status === 'approved')
                @can('Approve school loans')
                    <form method="POST" action="{{ route('loans.disburse', $loan->id) }}" class="card">@csrf
                        <div class="card-body d-flex justify-content-between align-items-center gap-2"><span class="fs-13">Pay {{ $n($loan->amount_approved) }} against {{ $loan->invoice?->reference }}. It shows on the invoice as a school-loan payment and counts as fees paid.</span>
                            <button class="btn btn-primary btn-sm text-nowrap" data-confirm="Pay this loan against the student's fees?">Pay against fees</button></div>
                    </form>
                @endcan
            @endif
            @if ($loan->status === 'disbursed')
                @can('Record loan repayments')
                    <form method="POST" action="{{ route('loans.repay', $loan->id) }}" class="card">@csrf
                        <div class="card-header"><h6 class="card-title mb-0">Record a repayment</h6></div>
                        <div class="card-body row g-2">
                            <div class="col-6"><label class="form-label mb-1">Amount</label><input type="number" step="0.01" name="amount" value="{{ old('amount', $loan->nextInstalment()['amount'] ?? $loan->balance) }}" class="form-control form-control-sm" required></div>
                            <div class="col-6"><label class="form-label mb-1">Paid on</label><input type="date" name="paid_on" value="{{ today()->toDateString() }}" class="form-control form-control-sm"></div>
                            <div class="col-6"><label class="form-label mb-1">How</label><select name="channel" class="form-select form-select-sm">@foreach ($channels as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select></div>
                            <div class="col-6"><label class="form-label mb-1">Reference</label><input name="reference" class="form-control form-control-sm"></div>
                        </div>
                        <div class="card-footer text-end"><button class="btn btn-success btn-sm">Record repayment</button></div>
                    </form>
                @endcan
            @endif
            @if (in_array($loan->status, ['pending', 'approved', 'disbursed'], true))
                @can('Manage school loans')
                    <form method="POST" action="{{ route('loans.close', $loan->id) }}" class="card">@csrf
                        <input type="hidden" name="action" value="{{ $loan->status === 'disbursed' ? 'write_off' : 'cancel' }}">
                        <div class="card-body d-flex gap-2"><input name="note" class="form-control form-control-sm" placeholder="Reason" required><button class="btn btn-sm btn-outline-secondary text-nowrap" data-confirm="Are you sure?">{{ $loan->status === 'disbursed' ? 'Write off what is left' : 'Cancel loan' }}</button></div>
                    </form>
                @endcan
            @endif
        </div>
        <div class="col-xl-7">
            @if ($loan->instalments)
                <div class="card"><div class="card-header"><h6 class="card-title mb-0">Repayment plan</h6></div>
                    <table class="table table-sm mb-0"><thead class="table-light"><tr><th>#</th><th>Due</th><th class="text-end">Amount</th><th>State</th></tr></thead><tbody>
                    @php $paid = (float) $loan->amount_repaid; $run = 0; @endphp
                    @foreach ($loan->instalments as $i => $ins)
                        @php $run += (float) $ins['amount']; $done = $paid + 0.009 >= $run; $late = ! $done && \Illuminate\Support\Carbon::parse($ins['due'])->lt(today()); @endphp
                        <tr><td>{{ $i + 1 }}</td><td>{{ \Illuminate\Support\Carbon::parse($ins['due'])->format('j M Y') }}</td><td class="text-end">{{ $n($ins['amount']) }}</td>
                            <td>@if ($done)<span class="badge bg-success-subtle text-success">Paid</span>@elseif ($late)<span class="badge bg-danger-subtle text-danger">Overdue</span>@else<span class="badge bg-light text-muted">To come</span>@endif</td></tr>
                    @endforeach
                    </tbody></table></div>
            @endif
            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Repayments</h6></div>
                <table class="table table-sm mb-0"><thead class="table-light"><tr><th>Date</th><th class="text-end">Amount</th><th>How</th><th>Reference</th><th>Recorded by</th></tr></thead><tbody>
                @forelse ($loan->repayments as $r)
                    <tr><td>{{ $r->paid_on->format('j M Y') }}</td><td class="text-end">{{ $n($r->amount) }}</td><td>{{ $channels[$r->channel] ?? $r->channel }}</td><td>{{ $r->reference }}</td><td>{{ $r->recorder?->name }}</td></tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-3">None yet.</td></tr>
                @endforelse
                </tbody></table></div>
        </div>
    </div>
</div></div></div>
@endsection
